<?php
/**
 * AW\Participant_Repo
 *
 * CRUD e query sulla tabella {prefix}aw_participants.
 * Nessuna business logic: solo SQL e mapping riga ↔ oggetto.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Participant_Repo {

    // ─── Lettura ─────────────────────────────────────────────────────────────

    /**
     * Recupera un singolo partecipante per ID.
     */
    public function find( int $id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_participants WHERE id = %d",
                $id
            )
        ) ?: null;
    }

    /**
     * Tutti i partecipanti di una specifica iscrizione.
     *
     * @return object[]
     */
    public function get_by_registration( int $registration_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_participants
                 WHERE source_registration_id = %d
                 ORDER BY last_name ASC, first_name ASC",
                $registration_id
            )
        ) ?: [];
    }

    /**
     * Tutti i partecipanti di una squadra.
     *
     * @return object[]
     */
    public function get_by_team( int $team_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_participants
                 WHERE team_id = %d
                 ORDER BY last_name ASC, first_name ASC",
                $team_id
            )
        ) ?: [];
    }

    /**
     * Tutti i partecipanti di una campagna, con dati squadra opzionali.
     *
     * @param array{
     *   team_id?:       int|null,
     *   attends_social?: bool,
     *   age_band?:      string,
     *   per_page?:      int,
     *   page?:          int,
     * } $args
     *
     * @return object[]
     */
    public function get_by_campaign( int $campaign_id, array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'team_id'       => null,
            'attends_social' => null,
            'age_band'      => null,
            'per_page'      => 50,
            'page'          => 1,
        ];

        $args = wp_parse_args( $args, $defaults );

        $where  = [ 'p.campaign_id = %d' ];
        $params = [ $campaign_id ];

        if ( $args['team_id'] !== null ) {
            if ( $args['team_id'] === 0 ) {
                // Partecipanti senza squadra assegnata
                $where[] = 'p.team_id IS NULL';
            } else {
                $where[]  = 'p.team_id = %d';
                $params[] = (int) $args['team_id'];
            }
        }

        if ( $args['attends_social'] !== null ) {
            $where[]  = 'p.attends_social = %d';
            $params[] = $args['attends_social'] ? 1 : 0;
        }

        if ( $args['age_band'] ) {
            $where[]  = 'p.age_band = %s';
            $params[] = $args['age_band'];
        }

        $per_page = max( 1, (int) $args['per_page'] );
        $offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
        $params[] = $per_page;
        $params[] = $offset;

        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT p.*, t.name AS team_name, t.color AS team_color
                FROM {$wpdb->prefix}aw_participants p
                LEFT JOIN {$wpdb->prefix}aw_teams t ON t.id = p.team_id
                WHERE {$where_sql}
                ORDER BY p.last_name ASC, p.first_name ASC
                LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];
    }

    /**
     * Conta i partecipanti di una campagna (con filtri opzionali).
     */
    public function count_by_campaign( int $campaign_id, array $args = [] ): int {
        global $wpdb;

        $where  = [ 'campaign_id = %d' ];
        $params = [ $campaign_id ];

        if ( isset( $args['team_id'] ) && $args['team_id'] !== null ) {
            if ( (int) $args['team_id'] === 0 ) {
                $where[] = 'team_id IS NULL';
            } else {
                $where[]  = 'team_id = %d';
                $params[] = (int) $args['team_id'];
            }
        }

        if ( isset( $args['attends_social'] ) && $args['attends_social'] !== null ) {
            $where[]  = 'attends_social = %d';
            $params[] = $args['attends_social'] ? 1 : 0;
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}aw_participants WHERE {$where_sql}";

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    // ─── Scrittura ───────────────────────────────────────────────────────────

    /**
     * Inserisce un singolo partecipante.
     *
     * @return int|false ID inserito, false in caso di errore.
     */
    public function insert( array $data ): int|false {
        global $wpdb;

        $data['created_at'] = current_time( 'mysql', true );

        $result = $wpdb->insert(
            "{$wpdb->prefix}aw_participants",
            $data,
            $this->get_format( $data )
        );

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    /**
     * Inserisce più partecipanti tramite loop di insert singoli.
     *
     * Non è un vero batch SQL: usa insert() in loop per gestire
     * correttamente i NULL e mantenere la stessa logica di normalizzazione.
     * Sufficiente per i volumi di Sprint 1 (max ~50 partecipanti per iscrizione).
     * Se in futuro servisse performance su volumi alti, implementare
     * una vera query multi-VALUES con $wpdb->query().
     *
     * @param array[] $rows Array di array colonna → valore.
     * @return int Numero di righe inserite correttamente.
     */
    public function insert_many( array $rows ): int {
        if ( empty( $rows ) ) {
            return 0;
        }

        $inserted = 0;

        foreach ( $rows as $row ) {
            if ( $this->insert( $row ) !== false ) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Aggiorna un partecipante.
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;

        return $wpdb->update(
            "{$wpdb->prefix}aw_participants",
            $data,
            [ 'id' => $id ],
            $this->get_format( $data ),
            [ '%d' ]
        ) !== false;
    }

    /**
     * Assegna un partecipante a una squadra (o rimuove l'assegnazione con NULL).
     */
    public function assign_team( int $participant_id, ?int $team_id ): bool {
        return $this->update( $participant_id, [ 'team_id' => $team_id ] );
    }

    /**
     * Elimina tutti i partecipanti di una iscrizione.
     * Usato in caso di cancellazione iscrizione.
     */
    public function delete_by_registration( int $registration_id ): bool {
        global $wpdb;

        return $wpdb->delete(
            "{$wpdb->prefix}aw_participants",
            [ 'source_registration_id' => $registration_id ],
            [ '%d' ]
        ) !== false;
    }

    // ─── Helpers privati ─────────────────────────────────────────────────────

    private function get_format( array $data ): array {
        $int_fields = [ 'campaign_id', 'source_registration_id', 'team_id', 'attends_social' ];

        $formats = [];
        foreach ( $data as $key => $value ) {
            if ( $value === null ) {
                // wpdb interpreta correttamente NULL solo con formato %s + valore null
                $formats[] = '%s';
            } elseif ( in_array( $key, $int_fields, true ) ) {
                $formats[] = '%d';
            } else {
                $formats[] = '%s';
            }
        }

        return $formats;
    }
}