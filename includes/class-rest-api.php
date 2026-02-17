<?php
/**
 * AW\Rest_Api
 *
 * Registra e gestisce l'endpoint REST del plugin.
 *
 *   POST /wp-json/aw/v1/register
 *
 * Responsabilità:
 *  - verifica nonce
 *  - rate limiting (transient WP)
 *  - lettura campagna e parametri server-side (event_id, causale_prefix)
 *  - delega validazione a AW_Validator_* del template
 *  - delega creazione iscrizione a Workflow
 *  - esegue azioni post-commit (email)
 *
 * NON contiene business logic: orchestra i moduli.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Rest_Api {

    const NAMESPACE = 'aw/v1';
    const ROUTE     = '/register';

    // Rate limiting: max tentativi per IP nell'intervallo
    const RATE_LIMIT_MAX      = 3;
    const RATE_LIMIT_WINDOW   = 600; // 10 minuti in secondi

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    // ─── Registrazione route ─────────────────────────────────────────────────

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods'             => \WP_REST_Server::CREATABLE, // POST
                'callback'            => [ $this, 'handle_register' ],
                'permission_callback' => '__return_true', // pubblica — sicurezza via nonce + rate limit
            ]
        );
    }

    // ─── Handler principale ──────────────────────────────────────────────────

    /**
     * Gestisce la richiesta di iscrizione.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_register( \WP_REST_Request $request ): \WP_REST_Response {

        // 1. Rate limiting
        $rate_error = $this->check_rate_limit();
        if ( $rate_error ) {
            return $this->error_response( $rate_error, 429 );
        }

        // 2. Verifica nonce
        $nonce = $request->get_header( 'X-WP-Nonce' ) ?? $request->get_param( 'nonce' );
        if ( ! wp_verify_nonce( $nonce, 'aw_register' ) ) {
            return $this->error_response( [ 'Sessione scaduta. Ricarica la pagina e riprova.' ], 403 );
        }

        // 3. Decodifica body JSON
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) || empty( $payload ) ) {
            return $this->error_response( [ 'Dati non validi.' ], 400 );
        }

        // 4. Identifica la campagna
        $campaign_id = (int) ( $payload['_meta']['campaign_id'] ?? $request->get_param( 'campaign_id' ) ?? 0 );
        $campaign    = $this->get_campaign( $campaign_id );

        if ( ! $campaign ) {
            return $this->error_response( [ 'Campagna non trovata o non attiva.' ], 404 );
        }

        // 5. Parametri server-side dalla campagna (NON dal client)
        $event_id       = $campaign->event_id;
        $causale_prefix = $campaign->causale_prefix;
        $template_id    = $campaign->template_id;

        // 6. Validazione server-side tramite il validator del template
        $validator = $this->load_validator( $template_id );
        if ( $validator ) {
            $validation_errors = $validator->validate( $payload );
            if ( ! empty( $validation_errors ) ) {
                return $this->error_response( $validation_errors, 422 );
            }
        }

        // 7. Crea iscrizione tramite Workflow
        $workflow = new Workflow();
        $result   = $workflow->create_registration( $campaign_id, $event_id, $payload, $causale_prefix );

        if ( ! $result['success'] ) {
            return $this->error_response( $result['errors'] ?? [ 'Errore interno.' ], 500 );
        }

        // 8. Azioni post-commit (email, ecc.)
        $this->dispatch_actions(
            $result['actions'] ?? [],
            $result['registration_id'],
            $campaign
        );

        // 9. Risposta di successo
        return $this->success_response( [
            'registration_code' => $result['registration_code'],
            'message'           => 'Richiesta ricevuta. Controlla la tua email.',
        ] );
    }

    // ─── Rate limiting ───────────────────────────────────────────────────────

    /**
     * Verifica e aggiorna il contatore di tentativi per IP.
     *
     * @return string[]|null  Array di errori se limite superato, null se ok.
     */
    private function check_rate_limit(): ?array {
        $ip  = $this->get_client_ip();
        $key = 'aw_rl_' . md5( $ip );

        $count = (int) get_transient( $key );

        if ( $count >= self::RATE_LIMIT_MAX ) {
            return [ 'Troppi tentativi. Attendi qualche minuto e riprova.' ];
        }

        // Incrementa: se è il primo tentativo imposta TTL, altrimenti aggiorna
        if ( $count === 0 ) {
            set_transient( $key, 1, self::RATE_LIMIT_WINDOW );
        } else {
            // get_transient non espone il TTL residuo — reimpostiamo la finestra
            // a partire dal primo tentativo, accettando che ogni nuovo tentativo
            // "resetti" il clock. Comportamento conservativo ma semplice.
            set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
        }

        return null;
    }

    /**
     * Restituisce l'IP del client, tenendo conto di eventuali proxy affidabili.
     */
    private function get_client_ip(): string {
        // In produzione con proxy/CDN affidabili, valutare X-Forwarded-For.
        // Per sicurezza default: REMOTE_ADDR non falsificabile dal client.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // ─── Campagna ────────────────────────────────────────────────────────────

    /**
     * Carica i dati di una campagna dal CPT aw_campaign.
     * Restituisce un oggetto con le proprietà necessarie all'handler,
     * oppure null se la campagna non esiste o non è pubblicata.
     *
     * @return object|null
     */
    private function get_campaign( int $campaign_id ): ?object {
        if ( ! $campaign_id ) {
            return null;
        }

        $post = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'aw_campaign' || $post->post_status !== 'publish' ) {
            return null;
        }

        return (object) [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'event_id'       => get_post_meta( $post->ID, '_aw_event_id',       true ) ?: 'ALIENTU_2026',
            'causale_prefix' => get_post_meta( $post->ID, '_aw_causale_prefix', true ) ?: 'ALIENTU26',
            'template_id'    => get_post_meta( $post->ID, '_aw_template_id',    true ) ?: 'alientu-26',
            'status'         => $post->post_status,
        ];
    }

    // ─── Validator del template ──────────────────────────────────────────────

    /**
     * Carica il validator del template se disponibile.
     *
     * @return object|null  Istanza del validator con metodo validate(array): array.
     */
    private function load_validator( string $template_id ): ?object {
        $file = AW_PLUGIN_DIR . "templates/{$template_id}/validate.php";

        if ( ! file_exists( $file ) ) {
            return null;
        }

        require_once $file;

        // Convenzione: classe AW_Validator_{StudlyCase}
        $class = 'AW_Validator_' . str_replace( '-', '_', $template_id );

        if ( ! class_exists( $class ) ) {
            return null;
        }

        return new $class();
    }

    // ─── Dispatch azioni post-commit ─────────────────────────────────────────

    /**
     * Esegue le azioni post-commit restituite dal Workflow.
     *
     * Formato atteso per ogni azione:
     *   [ 'type' => 'send_email', 'template' => 'received' ]
     *
     * @param array[] $actions
     * @param int     $registration_id
     * @param object  $campaign
     */
    private function dispatch_actions( array $actions, int $registration_id, object $campaign ): void {
        foreach ( $actions as $action ) {
            $type = $action['type'] ?? '';

            switch ( $type ) {
                case 'send_email':
                    $this->dispatch_email(
                        $registration_id,
                        $campaign,
                        $action['template'] ?? ''
                    );
                    break;

                // Sprint 2: 'generate_pdf', 'send_reminder', ecc.
                default:
                    // azione non riconosciuta — log silenzioso
                    if ( $type ) {
                        error_log( "[Alientu Engine] Azione post-commit non gestita: {$type}" );
                    }
                    break;
            }
        }
    }

    /**
     * Delega l'invio email a Email_Manager.
     * Errori non bloccanti: l'iscrizione è già salvata.
     */
    private function dispatch_email( int $registration_id, object $campaign, string $template_name ): void {
        try {
            $email_manager = new Email_Manager();
            $email_manager->send( $registration_id, $campaign->template_id, $template_name );
        } catch ( \Throwable $e ) {
            // L'email fallita non deve invalidare l'iscrizione già salvata
            error_log( "[Alientu Engine] Email '{$template_name}' fallita per iscrizione #{$registration_id}: " . $e->getMessage() );
        }
    }

    // ─── Helpers risposta ────────────────────────────────────────────────────

    /**
     * Risposta di successo standardizzata.
     *
     * @param array $data Dati aggiuntivi da includere nella risposta.
     */
    private function success_response( array $data = [] ): \WP_REST_Response {
        return new \WP_REST_Response(
            array_merge( [ 'success' => true ], $data ),
            200
        );
    }

    /**
     * Risposta di errore standardizzata.
     *
     * @param string[] $errors
     * @param int      $status HTTP status code.
     */
    private function error_response( array $errors, int $status = 400 ): \WP_REST_Response {
        return new \WP_REST_Response(
            [
                'success' => false,
                'errors'  => array_values( $errors ),
            ],
            $status
        );
    }
}