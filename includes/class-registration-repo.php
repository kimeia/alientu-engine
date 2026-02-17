<?php
/**
 * AW\Registration_Repo
 *
 * CRUD e query sulla tabella {prefix}aw_registrations.
 * Nessuna business logic qui: solo SQL e mapping riga ↔ oggetto.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Registration_Repo {

    // ─── Lettura ─────────────────────────────────────────────────────────────

    /**
     * Recupera una singola iscrizione per ID.
     *
     * @return object|null
     */
    public function find( int $id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_registrations WHERE id = %d",
                $id
            )
        ) ?: null;
    }

    /**
     * Recupera una singola iscrizione per codice univoco.
     *
     * @return object|null
     */
    public function find_by_code( string $code ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_registrations WHERE registration_code = %s",
                $code
            )
        ) ?: null;
    }

    /**
     * Lista iscrizioni con filtri opzionali e paginazione.
     *
     * @param array{
     *   campaign_id?:        int,
     *   event_id?:           string,
     *   registration_type?:  string,
     *   status?:             string,
     *   search?:             string,
     *   per_page?:           int,
     *   page?:               int,
     *   orderby?:            string,
     *   order?:              string,
     * } $args
     *
     * @return object[]
     */
    public function get_list( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'campaign_id'       => null,
            'event_id'          => null,
            'registration_type' => null,
            'status'            => null,
            'search'            => null,
            'per_page'          => 20,
            'page'              => 1,
            'orderby'           => 'created_at',
            'order'             => 'DESC',
        ];

        $args = wp_parse_args( $args, $defaults );

        $where  = [ '1=1' ];
        $params = [];

        if ( $args['campaign_id'] ) {
            $where[]  = 'campaign_id = %d';
            $params[] = (int) $args['campaign_id'];
        }

        if ( $args['event_id'] ) {
            $where[]  = 'event_id = %s';
            $params[] = $args['event_id'];
        }

        if ( $args['registration_type'] ) {
            $where[]  = 'registration_type = %s';
            $params[] = $args['registration_type'];
        }

        if ( $args['status'] ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if ( $args['search'] ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(referente_name LIKE %s OR referente_email LIKE %s OR registration_code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $allowed_orderby = [ 'created_at', 'updated_at', 'status', 'registration_type', 'referente_name' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = max( 1, (int) $args['per_page'] );
        $offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM {$wpdb->prefix}aw_registrations WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];
    }

    /**
     * Conta le iscrizioni con gli stessi filtri di get_list() (senza paginazione).
     *
     * @param array $args Stessi argomenti di get_list(), ignorando per_page/page/orderby/order.
     */
    public function count( array $args = [] ): int {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['campaign_id'] ) ) {
            $where[]  = 'campaign_id = %d';
            $params[] = (int) $args['campaign_id'];
        }

        if ( ! empty( $args['event_id'] ) ) {
            $where[]  = 'event_id = %s';
            $params[] = $args['event_id'];
        }

        if ( ! empty( $args['registration_type'] ) ) {
            $where[]  = 'registration_type = %s';
            $params[] = $args['registration_type'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(referente_name LIKE %s OR referente_email LIKE %s OR registration_code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}aw_registrations WHERE {$where_sql}";

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $sql );
    }

    // ─── Scrittura ───────────────────────────────────────────────────────────

    /**
     * Inserisce una nuova iscrizione.
     *
     * @param array $data Colonne → valori.
     * @return int|false  ID inserito, false in caso di errore.
     */
    public function insert( array $data ): int|false {
        global $wpdb;

        $data['created_at'] = current_time( 'mysql', true );
        $data['updated_at'] = $data['created_at'];

        $result = $wpdb->insert(
            "{$wpdb->prefix}aw_registrations",
            $data,
            $this->get_format( $data )
        );

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    /**
     * Aggiorna un'iscrizione esistente.
     *
     * @param int   $id
     * @param array $data Colonne → valori da aggiornare.
     * @return bool
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;

        $data['updated_at'] = current_time( 'mysql', true );

        return $wpdb->update(
            "{$wpdb->prefix}aw_registrations",
            $data,
            [ 'id' => $id ],
            $this->get_format( $data ),
            [ '%d' ]
        ) !== false;
    }

    /**
     * Aggiorna solo il campo status e updated_at.
     */
    public function update_status( int $id, string $status ): bool {
        return $this->update( $id, [ 'status' => $status ] );
    }

    /**
     * Elimina un'iscrizione (usato raramente — preferire status 'cancelled').
     */
    public function delete( int $id ): bool {
        global $wpdb;

        return $wpdb->delete(
            "{$wpdb->prefix}aw_registrations",
            [ 'id' => $id ],
            [ '%d' ]
        ) !== false;
    }

    // ─── Generazione codice ──────────────────────────────────────────────────

    /**
     * Genera un codice iscrizione univoco nel formato ALIENTU26-XXXXXX.
     * Riprova fino a 10 volte in caso di collisione (altamente improbabile).
     *
     * @param string $prefix Es. "ALIENTU26"
     * @return string
     */
    public function generate_code( string $prefix ): string {
        global $wpdb;

        for ( $i = 0; $i < 10; $i++ ) {
            $code = strtoupper( $prefix ) . '-' . str_pad( (string) wp_rand( 1, 999999 ), 6, '0', STR_PAD_LEFT );

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}aw_registrations WHERE registration_code = %s",
                    $code
                )
            );

            if ( ! $exists ) {
                return $code;
            }
        }

        // Fallback con timestamp per evitare loop infiniti
        return strtoupper( $prefix ) . '-' . time();
    }

    // ─── Helpers privati ─────────────────────────────────────────────────────

    /**
     * Restituisce l'array di format per wpdb in base ai campi presenti in $data.
     */
    private function get_format( array $data ): array {
        $integer_fields = [ 'campaign_id', 'pdf_attachment_id', 'total_minimum', 'total_final', 'donation' ];
        $float_fields   = [ 'total_minimum', 'total_final', 'donation' ];

        $formats = [];
        foreach ( array_keys( $data ) as $key ) {
            if ( in_array( $key, $float_fields, true ) ) {
                $formats[] = '%f';
            } elseif ( in_array( $key, $integer_fields, true ) ) {
                $formats[] = '%d';
            } else {
                $formats[] = '%s';
            }
        }

        return $formats;
    }
}