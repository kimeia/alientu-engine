<?php
/**
 * AW\Workflow
 *
 * Macchina a stati per le iscrizioni.
 * Centralizza tutta la logica di:
 *  - normalizzazione payload JS → struttura interna
 *  - creazione iscrizione (registration → team → participants in transazione)
 *  - transizioni di stato (con compare-and-swap e log atomico)
 *  - scrittura log
 *
 * Query dirette: solo INSERT su aw_registration_log (read-only log interno).
 * Per tutto il resto delega ai repo.
 *
 * NON invia email: restituisce `actions[]` al chiamante (REST controller)
 * che le esegue dopo il commit.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Workflow {

    // ─── Stati validi ────────────────────────────────────────────────────────

    const STATUS_RECEIVED        = 'received';
    const STATUS_NEEDS_REVIEW    = 'needs_review';
    const STATUS_WAITING_PAYMENT = 'waiting_payment';
    const STATUS_CONFIRMED       = 'confirmed';
    const STATUS_CANCELLED       = 'cancelled';
    const STATUS_ARCHIVED        = 'archived';

    /**
     * Transizioni consentite: stato_corrente → stati_raggiungibili[].
     */
    const TRANSITIONS = [
        self::STATUS_RECEIVED        => [ self::STATUS_NEEDS_REVIEW, self::STATUS_WAITING_PAYMENT, self::STATUS_CANCELLED ],
        self::STATUS_NEEDS_REVIEW    => [ self::STATUS_WAITING_PAYMENT, self::STATUS_CANCELLED ],
        self::STATUS_WAITING_PAYMENT => [ self::STATUS_CONFIRMED, self::STATUS_CANCELLED ],
        self::STATUS_CONFIRMED       => [ self::STATUS_ARCHIVED, self::STATUS_CANCELLED ],
        self::STATUS_CANCELLED       => [ self::STATUS_ARCHIVED ],
        self::STATUS_ARCHIVED        => [],
    ];

    /**
     * Azioni post-commit per ogni stato di destinazione.
     * Formato lista: ogni azione è un array con 'type' + parametri opzionali.
     * Il REST controller esegue queste azioni dopo il commit, delegando a Email_Manager.
     *
     * Estendibile senza refactor: basta aggiungere elementi alla lista,
     * es. ['type' => 'generate_pdf'] o ['type' => 'send_reminder', 'delay' => 86400].
     */
    const POST_COMMIT_ACTIONS = [
        self::STATUS_RECEIVED        => [
            [ 'type' => 'send_email', 'template' => 'received' ],
        ],
        self::STATUS_NEEDS_REVIEW    => [
            [ 'type' => 'send_email', 'template' => 'needs_review' ],
        ],
        self::STATUS_WAITING_PAYMENT => [
            [ 'type' => 'send_email', 'template' => 'waiting_payment' ],
        ],
        self::STATUS_CONFIRMED       => [
            [ 'type' => 'send_email', 'template' => 'confirmed' ],
            // ['type' => 'generate_pdf'],  // Sprint 2
        ],
        self::STATUS_CANCELLED       => [
            [ 'type' => 'send_email', 'template' => 'cancelled' ],
        ],
        self::STATUS_ARCHIVED        => [],
    ];

    // ─── Dipendenze ──────────────────────────────────────────────────────────

    private Registration_Repo $reg_repo;
    private Participant_Repo  $part_repo;
    private Team_Repo         $team_repo;

    public function __construct() {
        $this->reg_repo  = new Registration_Repo();
        $this->part_repo = new Participant_Repo();
        $this->team_repo = new Team_Repo();
    }

    // ─── Creazione iscrizione ────────────────────────────────────────────────

    /**
     * Crea una nuova iscrizione in modo atomico.
     *
     * Normalizza il payload JS prima di elaborarlo.
     * Per il tipo 'team': registration → team → participants in un'unica transazione.
     * Per gli altri tipi: registration → participants (senza team).
     *
     * @param int    $campaign_id
     * @param string $event_id         Letto dalla campagna server-side — NON dal client.
     * @param array  $payload          Output grezzo di collectFormData() già validato.
     * @param string $causale_prefix   Es. "ALIENTU26"
     *
     * @return array{
     *   success: bool,
     *   registration_id?: int,
     *   registration_code?: string,
     *   actions?: array,
     *   errors?: string[],
     * }
     */
    public function create_registration( int $campaign_id, string $event_id, array $payload, string $causale_prefix ): array {
        global $wpdb;

        // Normalizza la struttura nested del payload JS in chiavi interne piatte.
        // event_id NON viene letto dal payload: è responsabilità del chiamante (REST API)
        // ricavarlo dalla campagna e passarlo esplicitamente.
        $data = $this->normalize_payload( $payload );
        $data['event_id'] = sanitize_text_field( $event_id );

        $type = $data['registration_type'];

        if ( ! in_array( $type, [ 'team', 'individual', 'social' ], true ) ) {
            return [ 'success' => false, 'errors' => [ 'Tipo iscrizione non valido.' ] ];
        }

        $wpdb->query( 'START TRANSACTION' );

        try {
            // 1. Genera codice univoco
            $code = $this->reg_repo->generate_code( $causale_prefix );

            // 2. Calcola quote
            $totals = $this->calculate_totals( $data );

            // 3. Inserisci registration
            $reg_id = $this->reg_repo->insert( [
                'campaign_id'       => $campaign_id,
                'event_id'          => $data['event_id'],
                'registration_type' => $type,
                'registration_code' => $code,
                'status'            => self::STATUS_RECEIVED,
                'payload_json'      => wp_json_encode( $payload ), // snapshot raw originale
                'total_minimum'     => $totals['total_minimum'],
                'total_final'       => $totals['total_final'],
                'donation'          => $totals['donation'],
                'referente_name'    => $data['ref_name'],
                'referente_email'   => $data['ref_email'],
                'referente_phone'   => $data['ref_phone'],
            ] );

            if ( ! $reg_id ) {
                throw new \RuntimeException( 'Impossibile salvare l\'iscrizione.' );
            }

            // 4. Per iscrizioni team: crea squadra
            $team_id = null;
            if ( $type === 'team' ) {
                $team_id = $this->create_team_for_registration(
                    $campaign_id,
                    $data['event_id'],
                    $data['team_name'],
                    $data['team_color']
                );
            }

            // 5. Inserisci partecipanti
            $this->insert_participants( $campaign_id, $data['event_id'], $reg_id, $team_id, $data );

            // 6. Log stato iniziale
            $this->write_log( $reg_id, null, self::STATUS_RECEIVED, 'Iscrizione ricevuta.', 'api' );

            $wpdb->query( 'COMMIT' );

            return [
                'success'           => true,
                'registration_id'   => $reg_id,
                'registration_code' => $code,
                'actions'           => self::POST_COMMIT_ACTIONS[ self::STATUS_RECEIVED ],
            ];

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( '[Alientu Engine] create_registration error: ' . $e->getMessage() );

            return [
                'success' => false,
                'errors'  => [ 'Errore interno. Riprova o contatta l\'organizzazione.' ],
            ];
        }
    }

    // ─── Transizioni di stato ────────────────────────────────────────────────

    /**
     * Esegue una transizione di stato su un'iscrizione.
     *
     * Atomicità garantita da transazione DB.
     * Compare-and-swap: aggiorna solo se lo stato corrente nel DB corrisponde
     * a quello atteso — previene transizioni concorrenti da più admin.
     *
     * @param int    $registration_id
     * @param string $new_status
     * @param string $note           Nota libera per il log.
     * @param string $triggered_by   'admin' | 'api' | 'system'
     *
     * @return array{
     *   success: bool,
     *   actions?: array,
     *   error?: string,
     * }
     */
    public function transition( int $registration_id, string $new_status, string $note = '', string $triggered_by = 'admin' ): array {
        global $wpdb;

        $registration = $this->reg_repo->find( $registration_id );

        if ( ! $registration ) {
            return [ 'success' => false, 'error' => 'Iscrizione non trovata.' ];
        }

        $current = $registration->status;

        if ( ! $this->can_transition( $current, $new_status ) ) {
            return [
                'success' => false,
                'error'   => sprintf( 'Transizione non consentita: %s → %s.', $current, $new_status ),
            ];
        }

        $wpdb->query( 'START TRANSACTION' );

        // Compare-and-swap: aggiorna solo se lo stato nel DB è ancora quello atteso.
        // Protegge da due admin che fanno transizioni concorrenti sullo stesso record.
        $rows_updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}aw_registrations
                 SET status = %s, updated_at = %s
                 WHERE id = %d AND status = %s",
                $new_status,
                current_time( 'mysql', true ),
                $registration_id,
                $current
            )
        );

        if ( $rows_updated === false ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'error' => 'Errore aggiornamento stato.' ];
        }

        if ( $rows_updated === 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'error' => 'Stato già modificato da un\'altra operazione. Ricarica e riprova.' ];
        }

        $this->write_log( $registration_id, $current, $new_status, $note, $triggered_by );

        $wpdb->query( 'COMMIT' );

        return [
            'success' => true,
            'actions' => self::POST_COMMIT_ACTIONS[ $new_status ] ?? [],
        ];
    }

    /**
     * Verifica se una transizione è consentita.
     */
    public function can_transition( string $from, string $to ): bool {
        return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
    }

    /**
     * Restituisce gli stati raggiungibili da uno stato dato.
     *
     * @return string[]
     */
    public function available_transitions( string $from ): array {
        return self::TRANSITIONS[ $from ] ?? [];
    }

    // ─── Log ─────────────────────────────────────────────────────────────────

    /**
     * Recupera il log completo di un'iscrizione, dal più recente.
     *
     * @return object[]
     */
    public function get_log( int $registration_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_registration_log
                 WHERE registration_id = %d
                 ORDER BY created_at DESC",
                $registration_id
            )
        ) ?: [];
    }

    // ─── Normalizzazione payload ─────────────────────────────────────────────

    /**
     * Normalizza il payload nested prodotto da collectFormData() in alientu.js
     * in una struttura piatta con chiavi interne predicibili.
     *
     * Struttura attesa dal JS (esempi):
     *   _meta.form                   → registration_type
     *   _meta.event_id               → event_id
     *   referente.first_name         → ref_first_name
     *   referente.last_name          → ref_last_name
     *   referente.email              → ref_email
     *   referente.phone              → ref_phone
     *   team.name                    → team_name
     *   team.color_pref_1            → team_color (prima preferenza)
     *   team.color_custom            → team_color (se presente, sovrascrive)
     *   players[].social             → players[].attends_social
     *   social_participants[]        → social_guests[]
     *   social.mode                  → transport_mode (referente conviviale)
     *
     * @param array $payload Payload grezzo dal frontend.
     * @return array         Struttura normalizzata per uso interno.
     */
    /**
     * Normalizza il payload prodotto da collectFormData() in alientu.js
     * in una struttura piatta con chiavi interne predicibili.
     *
     * Mapping 1:1 verificato sul sorgente JS (febbraio 2026):
     *
     *   _meta.form                       → registration_type
     *   referente.first_name             → ref_first_name
     *   referente.last_name              → ref_last_name
     *   referente.email                  → ref_email
     *   referente.phone                  → ref_phone
     *   referente.fascia                 → age_band  (usato per 'individual')
     *   team.name                        → team_name
     *   team.color_custom ?? color_pref_1 → team_color
     *   players[].social (bool)          → players[].attends_social
     *   social_participants[].intolleranze → social_guests[].food_notes
     *   social.mode ('all'|'some'|'none'|null) → attends_social (per 'individual')
     *   social.food_notes                → food_notes  (note collettive conviviale)
     *   quotes.donation                  → donation
     *
     * NON mappati (non presenti nel DB per Sprint 1):
     *   transport.*  — trasporti
     *   profile.*    — profilo scout/sport
     *   referente.accepted_rules/privacy — solo per audit nel payload_json raw
     *   quotes.*     — ricalcolati server-side, non fidati dal client
     *
     * event_id: NON nel payload — iniettato da create_registration() dalla campagna.
     */
    private function normalize_payload( array $payload ): array {
        $meta   = $payload['_meta']     ?? [];
        $ref    = $payload['referente'] ?? [];
        $team   = $payload['team']      ?? [];
        $social = $payload['social']    ?? [];

        // ── Tipo ─────────────────────────────────────────────────────────────
        $type = sanitize_key( $meta['form'] ?? '' );

        // ── Referente ────────────────────────────────────────────────────────
        $ref_first = sanitize_text_field( $ref['first_name'] ?? '' );
        $ref_last  = sanitize_text_field( $ref['last_name']  ?? '' );
        $ref_email = sanitize_email( $ref['email'] ?? '' );
        $ref_phone = sanitize_text_field( $ref['phone'] ?? '' );

        // referente.fascia → age_band (usato per individual; vuoto per team/social)
        $age_band = sanitize_text_field( $ref['fascia'] ?? '' );

        // ── Squadra (solo tipo 'team') ────────────────────────────────────────
        $team_name  = sanitize_text_field( $team['name'] ?? '' );
        // color_custom ha precedenza sulle preferenze numerate
        $team_color = sanitize_text_field(
            ( ! empty( $team['color_custom'] ) ? $team['color_custom'] : null )
            ?? $team['color_pref_1']
            ?? ''
        );

        // ── Players (solo tipo 'team') ────────────────────────────────────────
        // players[].social (bool JS) → players[].attends_social
        $players = [];
        foreach ( $payload['players'] ?? [] as $p ) {
            $players[] = [
                'first_name'     => sanitize_text_field( $p['first_name'] ?? '' ),
                'last_name'      => sanitize_text_field( $p['last_name']  ?? '' ),
                'age_band'       => sanitize_text_field( $p['age_band']   ?? '' ),
                'email'          => sanitize_email( $p['email'] ?? '' ),
                'phone'          => sanitize_text_field( $p['phone'] ?? '' ),
                'attends_social' => ! empty( $p['social'] ),
                'food_notes'     => '',  // players non hanno note individuali nel form
            ];
        }

        // ── Partecipanti conviviale (solo tipo 'social') ─────────────────────
        // social_participants[].intolleranze → food_notes
        $social_guests = [];
        foreach ( $payload['social_participants'] ?? [] as $sp ) {
            $social_guests[] = [
                'first_name' => sanitize_text_field( $sp['first_name'] ?? '' ),
                'last_name'  => sanitize_text_field( $sp['last_name']  ?? '' ),
                'email'      => sanitize_email( $sp['email'] ?? '' ),
                'phone'      => sanitize_text_field( $sp['phone'] ?? '' ),
                'age_band'   => '',
                'food_notes' => sanitize_textarea_field( $sp['intolleranze'] ?? '' ),
                'attends_social' => true,  // per definizione: sono iscritti solo al conviviale
            ];
        }

        // ── Conviviale referente (solo tipo 'individual') ────────────────────
        // social.mode per 'individual': 'yes_b' = partecipa, 'none' = non partecipa
        // (per 'team': 'all' | 'some' | 'none' — ma attends_social è per partecipante, non per referente)
        $social_mode    = $social['mode'] ?? null;
        $attends_social = ( $type === 'individual' ) && ( $social_mode === 'yes_b' );

        // Note cibo collettive (conviviale) — campo comune a tutti i tipi
        $food_notes = sanitize_textarea_field( $social['food_notes'] ?? '' );

        // ── Donazione ────────────────────────────────────────────────────────
        // Letta da quotes.donation (source of truth nel JS) o fallback top-level
        $donation = (float) ( $payload['quotes']['donation'] ?? $payload['donation'] ?? 0 );

        return [
            'registration_type' => $type,
            // event_id assente: iniettato da create_registration() server-side
            'ref_first_name'    => $ref_first,
            'ref_last_name'     => $ref_last,
            'ref_name'          => trim( "$ref_first $ref_last" ),
            'ref_email'         => $ref_email,
            'ref_phone'         => $ref_phone,
            'age_band'          => $age_band,
            'team_name'         => $team_name,
            'team_color'        => $team_color,
            'players'           => $players,
            'social_guests'     => $social_guests,
            'attends_social'    => $attends_social,
            'food_notes'        => $food_notes,
            'donation'          => $donation,
        ];
    }

    // ─── Helpers privati ─────────────────────────────────────────────────────

    /**
     * Crea la squadra per un'iscrizione di tipo 'team'.
     * Chiamato dentro la transazione di create_registration().
     *
     * @throws \RuntimeException
     */
    private function create_team_for_registration( int $campaign_id, string $event_id, string $team_name, string $team_color ): int {
        if ( ! $team_name ) {
            throw new \RuntimeException( 'Nome squadra mancante.' );
        }

        $team_id = $this->team_repo->insert( [
            'campaign_id' => $campaign_id,
            'event_id'    => $event_id,
            'name'        => $team_name,
            'color'       => $team_color ?: null,
            'capacity'    => 12,
        ] );

        if ( ! $team_id ) {
            throw new \RuntimeException( 'Impossibile creare la squadra.' );
        }

        return $team_id;
    }

    /**
     * Inserisce i partecipanti estratti dal payload normalizzato.
     * Chiamato dentro la transazione di create_registration().
     *
     * @throws \RuntimeException
     */
    private function insert_participants( int $campaign_id, string $event_id, int $reg_id, ?int $team_id, array $data ): void {
        $type = $data['registration_type'];
        $rows = [];

        switch ( $type ) {

            case 'team':
                foreach ( $data['players'] as $p ) {
                    $rows[] = $this->map_participant( $campaign_id, $event_id, $reg_id, $team_id, $p );
                }
                break;

            case 'individual':
                $rows[] = $this->map_participant( $campaign_id, $event_id, $reg_id, null, [
                    'first_name'     => $data['ref_first_name'],
                    'last_name'      => $data['ref_last_name'],
                    'email'          => $data['ref_email'],
                    'phone'          => $data['ref_phone'],
                    'age_band'       => $data['age_band'],
                    'attends_social' => $data['attends_social'],
                    'food_notes'     => $data['food_notes'],
                ] );
                break;

            case 'social':
                foreach ( $data['social_guests'] as $p ) {
                    $rows[] = $this->map_participant( $campaign_id, $event_id, $reg_id, null, $p );
                }
                break;
        }

        if ( empty( $rows ) ) {
            return;
        }

        $inserted = $this->part_repo->insert_many( $rows );

        if ( $inserted !== count( $rows ) ) {
            throw new \RuntimeException(
                sprintf( 'Inseriti solo %d/%d partecipanti.', $inserted, count( $rows ) )
            );
        }
        // insert_many() è in Participant_Repo — loop di insert singoli, gestisce NULL correttamente.
    }

    /**
     * Mappa un array partecipante grezzo alla struttura attesa da Participant_Repo.
     */
    private function map_participant( int $campaign_id, string $event_id, int $reg_id, ?int $team_id, array $p ): array {
        return [
            'campaign_id'            => $campaign_id,
            'event_id'               => $event_id,
            'source_registration_id' => $reg_id,
            'team_id'                => $team_id,
            'first_name'             => sanitize_text_field( $p['first_name'] ?? '' ),
            'last_name'              => sanitize_text_field( $p['last_name']  ?? '' ),
            'email'                  => sanitize_email( $p['email']           ?? '' ),
            'phone'                  => sanitize_text_field( $p['phone']      ?? '' ),
            'age_band'               => sanitize_text_field( $p['age_band']   ?? '' ),
            'attends_social'         => ! empty( $p['attends_social'] ) ? 1 : 0,
            'food_notes'             => sanitize_textarea_field( $p['food_notes'] ?? '' ),
        ];
    }

    /**
     * Calcola le quote dalla struttura normalizzata.
     *
     * total_minimum = (giocatori × prezzo_gioco) + (conv. × prezzo_conv)
     * total_final   = total_minimum + donation
     *
     * Prezzi hardcoded per Sprint 1.
     * Sprint 2: letti da config.json del template.
     */
    private function calculate_totals( array $data ): array {
        $price_game   = 3.00;
        $price_social = 5.00;
        $type         = $data['registration_type'];
        $donation     = (float) $data['donation'];

        $players_count = 0;
        $social_count  = 0;

        switch ( $type ) {
            case 'team':
                $players_count = count( $data['players'] );
                foreach ( $data['players'] as $p ) {
                    if ( ! empty( $p['attends_social'] ) ) {
                        $social_count++;
                    }
                }
                break;

            case 'individual':
                $players_count = 1;
                $social_count  = $data['attends_social'] ? 1 : 0;
                break;

            case 'social':
                $social_count = count( $data['social_guests'] );
                break;
        }

        $total_minimum = round( ( $players_count * $price_game ) + ( $social_count * $price_social ), 2 );
        $total_final   = round( $total_minimum + $donation, 2 );

        return [
            'total_minimum' => $total_minimum,
            'total_final'   => $total_final,
            'donation'      => round( $donation, 2 ),
        ];
    }

    /**
     * Scrive una riga nel log delle iscrizioni.
     * Unica query diretta del Workflow (INSERT su tabella log).
     *
     * @throws \RuntimeException Se l'insert fallisce (usato dentro transazioni).
     */
    private function write_log( int $registration_id, ?string $from, string $to, string $note, string $triggered_by ): void {
        global $wpdb;

        $result = $wpdb->insert(
            "{$wpdb->prefix}aw_registration_log",
            [
                'registration_id' => $registration_id,
                'from_status'     => $from,
                'to_status'       => $to,
                'note'            => sanitize_textarea_field( $note ),
                'triggered_by'    => sanitize_key( $triggered_by ),
                'created_at'      => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $result === false ) {
            throw new \RuntimeException( 'Impossibile scrivere il log iscrizione.' );
        }
    }
}