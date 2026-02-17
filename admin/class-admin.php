<?php
/**
 * AW\Admin
 *
 * Interfaccia amministrativa per gestire iscrizioni.
 *
 * Sprint 1: lista iscrizioni con filtri base + cambio stato.
 * Sprint 2: dettaglio iscrizione, gestione squadre, bulk actions, export CSV.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
        add_action( 'admin_post_aw_change_status', [ $this, 'handle_change_status' ] );
        add_action( 'admin_post_aw_manual_registration', [ $this, 'handle_manual_registration' ] );
    }

    // ─── Menu ────────────────────────────────────────────────────────────────

    /**
     * Aggiunge le pagine admin al menu di WordPress.
     */
    public function add_menu_pages(): void {
        // Submenu sotto "Alientu" (il CPT aw_campaign)
        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Iscrizioni',
            'Iscrizioni',
            'edit_posts',
            'aw-registrations',
            [ $this, 'render_registrations_page' ]
        );

        add_submenu_page(
            'edit.php?post_type=aw_campaign',
            'Nuova iscrizione',
            'Nuova iscrizione',
            'edit_posts',
            'aw-new-registration',
            [ $this, 'render_new_registration_page' ]
        );
    }

    // ─── Pagina lista iscrizioni ─────────────────────────────────────────────

    /**
     * Renderizza la pagina lista iscrizioni con filtri e tabella.
     */
    public function render_registrations_page(): void {
        // Filtri GET
        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        $status      = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $search      = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $paged       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

        $repo = new Registration_Repo();

        // Carica iscrizioni
        $args = [
            'campaign_id' => $campaign_id ?: null,
            'status'      => $status ?: null,
            'search'      => $search ?: null,
            'per_page'    => 20,
            'page'        => $paged,
            'orderby'     => 'created_at',
            'order'       => 'DESC',
        ];

        $registrations = $repo->get_list( $args );
        $total         = $repo->count( $args );
        $total_pages   = ceil( $total / 20 );

        // Carica campagne per filtro
        $campaigns = get_posts( [
            'post_type'      => 'aw_campaign',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Iscrizioni</h1>
            <hr class="wp-header-end">

            <!-- Filtri -->
            <form method="get" class="aw-filters" style="margin: 20px 0; display: flex; gap: 10px; align-items: end;">
                <input type="hidden" name="post_type" value="aw_campaign">
                <input type="hidden" name="page" value="aw-registrations">

                <label style="display: flex; flex-direction: column;">
                    <span>Campagna</span>
                    <select name="campaign_id" style="min-width: 200px;">
                        <option value="">Tutte le campagne</option>
                        <?php foreach ( $campaigns as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $campaign_id, $c->ID ); ?>>
                                <?php echo esc_html( $c->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="display: flex; flex-direction: column;">
                    <span>Stato</span>
                    <select name="status">
                        <option value="">Tutti gli stati</option>
                        <option value="received" <?php selected( $status, 'received' ); ?>>Ricevuta</option>
                        <option value="needs_review" <?php selected( $status, 'needs_review' ); ?>>Da revisionare</option>
                        <option value="waiting_payment" <?php selected( $status, 'waiting_payment' ); ?>>In attesa pagamento</option>
                        <option value="confirmed" <?php selected( $status, 'confirmed' ); ?>>Confermata</option>
                        <option value="cancelled" <?php selected( $status, 'cancelled' ); ?>>Annullata</option>
                        <option value="archived" <?php selected( $status, 'archived' ); ?>>Archiviata</option>
                    </select>
                </label>

                <label style="display: flex; flex-direction: column;">
                    <span>Cerca</span>
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Nome, email, codice...">
                </label>

                <button type="submit" class="button">Filtra</button>
                <?php if ( $campaign_id || $status || $search ) : ?>
                    <a href="?post_type=aw_campaign&page=aw-registrations" class="button">Reset</a>
                <?php endif; ?>
            </form>

            <!-- Tabella iscrizioni -->
            <?php if ( empty( $registrations ) ) : ?>
                <p>Nessuna iscrizione trovata.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Codice</th>
                            <th>Referente</th>
                            <th>Tipo</th>
                            <th>Stato</th>
                            <th>Importo</th>
                            <th>Data</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $registrations as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r->id ); ?></td>
                                <td><code><?php echo esc_html( $r->registration_code ); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html( $r->referente_name ); ?></strong><br>
                                    <small><?php echo esc_html( $r->referente_email ); ?></small>
                                </td>
                                <td><?php echo esc_html( $this->get_type_label( $r->registration_type ) ); ?></td>
                                <td><?php echo $this->render_status_badge( $r->status ); ?></td>
                                <td>€ <?php echo esc_html( number_format( (float) $r->total_final, 2, ',', '.' ) ); ?></td>
                                <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $r->created_at ) ) ); ?></td>
                                <td><?php echo $this->render_actions( $r ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Paginazione -->
                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav" style="margin-top: 20px;">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links( [
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'current'   => $paged,
                                'total'     => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ] );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Cambio stato ────────────────────────────────────────────────────────

    /**
     * Gestisce il cambio stato di un'iscrizione.
     */
    public function handle_change_status(): void {
        check_admin_referer( 'aw_change_status' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $registration_id = isset( $_POST['registration_id'] ) ? (int) $_POST['registration_id'] : 0;
        $new_status      = isset( $_POST['new_status'] ) ? sanitize_key( $_POST['new_status'] ) : '';
        $note            = isset( $_POST['note'] ) ? sanitize_textarea_field( $_POST['note'] ) : '';

        if ( ! $registration_id || ! $new_status ) {
            wp_die( 'Parametri non validi.' );
        }

        $workflow = new Workflow();
        $result   = $workflow->transition( $registration_id, $new_status, $note, 'admin' );

        if ( ! $result['success'] ) {
            wp_die( 'Errore: ' . ( $result['error'] ?? 'Transizione non riuscita.' ) );
        }

        // Esegui azioni post-commit (email)
        if ( ! empty( $result['actions'] ) ) {
            $repo         = new Registration_Repo();
            $registration = $repo->find( $registration_id );

            if ( $registration ) {
                $campaign_post = get_post( $registration->campaign_id );
                $template_id   = get_post_meta( $registration->campaign_id, '_aw_template_id', true ) ?: 'alientu-26';

                $campaign = (object) [
                    'id'          => $registration->campaign_id,
                    'template_id' => $template_id,
                ];

                foreach ( $result['actions'] as $action ) {
                    if ( $action['type'] === 'send_email' ) {
                        try {
                            $email_manager = new Email_Manager();
                            $email_manager->send( $registration_id, $campaign->template_id, $action['template'] );
                        } catch ( \Throwable $e ) {
                            error_log( '[Alientu Engine] Email fallita: ' . $e->getMessage() );
                        }
                    }
                }
            }
        }

        // Redirect con messaggio
        wp_safe_redirect( add_query_arg( [
            'post_type' => 'aw_campaign',
            'page'      => 'aw-registrations',
            'updated'   => '1',
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    // ─── Helpers rendering ───────────────────────────────────────────────────

    private function render_status_badge( string $status ): string {
        $colors = [
            'received'        => '#999',
            'needs_review'    => '#f0ad4e',
            'waiting_payment' => '#5bc0de',
            'confirmed'       => '#5cb85c',
            'cancelled'       => '#d9534f',
            'archived'        => '#777',
        ];

        $labels = [
            'received'        => 'Ricevuta',
            'needs_review'    => 'Da revisionare',
            'waiting_payment' => 'In attesa pagamento',
            'confirmed'       => 'Confermata',
            'cancelled'       => 'Annullata',
            'archived'        => 'Archiviata',
        ];

        $color = $colors[ $status ] ?? '#999';
        $label = $labels[ $status ] ?? ucfirst( $status );

        return sprintf(
            '<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; background: %s; color: white; font-size: 11px; font-weight: 600;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    private function render_actions( object $registration ): string {
        $workflow = new Workflow();
        $available = $workflow->available_transitions( $registration->status );

        if ( empty( $available ) ) {
            return '—';
        }

        $actions = [];
        foreach ( $available as $new_status ) {
            $label = $this->get_status_action_label( $new_status );
            $actions[] = sprintf(
                '<a href="#" onclick="awChangeStatus(%d, \'%s\'); return false;">%s</a>',
                (int) $registration->id,
                esc_attr( $new_status ),
                esc_html( $label )
            );
        }

        // Aggiungi script inline per modale cambio stato (minimal)
        add_action( 'admin_footer', [ $this, 'render_change_status_script' ], 1 );

        return implode( ' | ', $actions );
    }

    /**
     * Script JS inline per gestire il cambio stato con conferma.
     */
    public function render_change_status_script(): void {
        static $rendered = false;
        if ( $rendered ) {
            return;
        }
        $rendered = true;

        ?>
        <script>
        function awChangeStatus(id, status) {
            const labels = {
                'needs_review': 'Da revisionare',
                'waiting_payment': 'In attesa pagamento',
                'confirmed': 'Confermata',
                'cancelled': 'Annullata',
                'archived': 'Archiviata'
            };
            const note = prompt('Nota (facoltativa):');
            if (note === null) return; // Annullato

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>';
            form.innerHTML = `
                <input type="hidden" name="action" value="aw_change_status">
                <input type="hidden" name="registration_id" value="${id}">
                <input type="hidden" name="new_status" value="${status}">
                <input type="hidden" name="note" value="${note}">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'aw_change_status' ) ); ?>">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        </script>
        <?php
    }

    private function get_status_action_label( string $status ): string {
        return match ( $status ) {
            'needs_review'    => 'Richiedi revisione',
            'waiting_payment' => 'Approva → Pagamento',
            'confirmed'       => 'Conferma',
            'cancelled'       => 'Annulla',
            'archived'        => 'Archivia',
            default           => ucfirst( $status ),
        };
    }

    private function get_type_label( string $type ): string {
        return match ( $type ) {
            'team'       => 'Squadra',
            'individual' => 'Individuale',
            'social'     => 'Conviviale',
            default      => ucfirst( $type ),
        };
    }

    // ─── Nuova iscrizione manuale ────────────────────────────────────────────

    /**
     * Renderizza la pagina "Nuova iscrizione" con form completo.
     * Riusa alientu.js e alientu.css per la stessa UX del frontend.
     */
    public function render_new_registration_page(): void {
        // Carica campagne disponibili
        $campaigns = get_posts( [
            'post_type'      => 'aw_campaign',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        if ( empty( $campaigns ) ) {
            ?>
            <div class="wrap">
                <h1>Nuova iscrizione</h1>
                <div class="notice notice-warning"><p>Nessuna campagna pubblicata. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=aw_campaign' ) ); ?>">Crea una campagna</a> prima di aggiungere iscrizioni manuali.</p></div>
            </div>
            <?php
            return;
        }

        // Seleziona campagna di default (prima pubblicata)
        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : $campaigns[0]->ID;
        $campaign    = get_post( $campaign_id );

        if ( ! $campaign || $campaign->post_type !== 'aw_campaign' ) {
            wp_die( 'Campagna non valida.' );
        }

        $event_id       = get_post_meta( $campaign->ID, '_aw_event_id', true ) ?: 'ALIENTU_2026';
        $causale_prefix = get_post_meta( $campaign->ID, '_aw_causale_prefix', true ) ?: 'ALIENTU26';
        $template_id    = get_post_meta( $campaign->ID, '_aw_template_id', true ) ?: 'alientu-26';

        // Carica config template
        $config_file = AW_PLUGIN_DIR . "templates/{$template_id}/config.json";
        $config      = file_exists( $config_file ) ? json_decode( file_get_contents( $config_file ) ) : null;

        // Enqueue assets frontend nell'admin
        wp_enqueue_style( 'aw-alientu', AW_PLUGIN_URL . 'assets/css/alientu.css', [], AW_VERSION );
        wp_enqueue_script( 'aw-alientu', AW_PLUGIN_URL . 'assets/js/alientu.js', [], AW_VERSION, true );

        // Inietta config per JS (stesso formato del frontend, ma con endpoint admin)
        wp_localize_script( 'aw-alientu', 'alientuConfig', [
            'campaign_id' => $campaign->ID,
            'event_id'    => $event_id,
            'priceGame'   => $config->prices->game ?? 3,
            'priceSocial' => $config->prices->social ?? 5,
            'eventYear'   => $config->event->year ?? '2026',
            'restUrl'     => admin_url( 'admin-post.php' ), // POST verso admin-post invece di REST
            'nonce'       => wp_create_nonce( 'aw_manual_registration' ),
            'isAdmin'     => true, // flag per distinguere contesto admin
        ] );

        // Carica template HTML form
        $form_template = AW_PLUGIN_DIR . "templates/{$template_id}/form-body.html";

        ?>
        <div class="wrap">
            <h1>Nuova iscrizione manuale</h1>

            <!-- Selettore campagna -->
            <div style="margin: 20px 0; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                <form method="get" style="display: flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="post_type" value="aw_campaign">
                    <input type="hidden" name="page" value="aw-new-registration">
                    <label>
                        <strong>Campagna:</strong>
                        <select name="campaign_id" onchange="this.form.submit()">
                            <?php foreach ( $campaigns as $c ) : ?>
                                <option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $campaign_id, $c->ID ); ?>>
                                    <?php echo esc_html( $c->post_title ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>

            <!-- Form iscrizione (stesso HTML del frontend) -->
            <div class="aw-admin-form-wrapper" style="background: white; padding: 30px; border: 1px solid #ccc; border-radius: 4px;">
                <?php
                if ( file_exists( $form_template ) ) {
                    include $form_template;
                } else {
                    echo '<p>Template form non trovato.</p>';
                }
                ?>
            </div>

            <!-- Script override per submit admin -->
            <script>
            // Override submitForm() per inviare via admin-post invece di REST API
            (function() {
                const originalSubmit = window.submitForm;
                window.submitForm = function() {
                    const data = collectFormData();
                    data._meta.campaign_id = <?php echo (int) $campaign->ID; ?>;

                    showLoader();

                    // Costruisci form POST verso admin-post.php
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="aw_manual_registration">
                        <input type="hidden" name="payload" value='${JSON.stringify(data)}'>
                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign->ID; ?>">
                        <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                        <input type="hidden" name="causale_prefix" value="<?php echo esc_attr( $causale_prefix ); ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'aw_manual_registration' ) ); ?>">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                };
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Gestisce il submit del form iscrizione manuale dall'admin.
     */
    public function handle_manual_registration(): void {
        check_admin_referer( 'aw_manual_registration' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        $campaign_id    = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
        $event_id       = isset( $_POST['event_id'] ) ? sanitize_text_field( $_POST['event_id'] ) : '';
        $causale_prefix = isset( $_POST['causale_prefix'] ) ? sanitize_text_field( $_POST['causale_prefix'] ) : '';
        $payload_json   = isset( $_POST['payload'] ) ? stripslashes( $_POST['payload'] ) : '';

        $payload = json_decode( $payload_json, true );

        if ( ! $campaign_id || ! $event_id || ! $causale_prefix || ! is_array( $payload ) ) {
            wp_die( 'Dati non validi.' );
        }

        // Usa il Workflow per creare l'iscrizione (stessa logica del frontend)
        $workflow = new Workflow();
        $result   = $workflow->create_registration( $campaign_id, $event_id, $payload, $causale_prefix );

        if ( ! $result['success'] ) {
            wp_die( 'Errore creazione iscrizione: ' . implode( ', ', $result['errors'] ?? [] ) );
        }

        // Esegui azioni post-commit (email)
        if ( ! empty( $result['actions'] ) ) {
            $template_id = get_post_meta( $campaign_id, '_aw_template_id', true ) ?: 'alientu-26';

            foreach ( $result['actions'] as $action ) {
                if ( $action['type'] === 'send_email' ) {
                    try {
                        $email_manager = new Email_Manager();
                        $email_manager->send( $result['registration_id'], $template_id, $action['template'] );
                    } catch ( \Throwable $e ) {
                        error_log( '[Alientu Engine] Email fallita (admin): ' . $e->getMessage() );
                    }
                }
            }
        }

        // Redirect alla lista con messaggio successo
        wp_safe_redirect( add_query_arg( [
            'post_type' => 'aw_campaign',
            'page'      => 'aw-registrations',
            'created'   => '1',
            'code'      => $result['registration_code'] ?? '',
        ], admin_url( 'edit.php' ) ) );
        exit;
    }
}