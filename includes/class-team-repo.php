<?php
/**
 * AW\Team_Repo
 *
 * CRUD e query sulla tabella {prefix}aw_teams.
 * Nessuna business logic: solo SQL e mapping riga ↔ oggetto.
 */

namespace AW;

defined( 'ABSPATH' ) || exit;

class Team_Repo {

    // ─── Lettura ─────────────────────────────────────────────────────────────

    /**
     * Recupera una squadra per ID.
     */
    public function find( int $id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_teams WHERE id = %d",
                $id
            )
        ) ?: null;
    }

    /**
     * Tutte le squadre di una campagna.
     *
     * @return object[]
     */
    public function get_by_campaign( int $campaign_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_teams
                 WHERE campaign_id = %d
                 ORDER BY name ASC",
                $campaign_id
            )
        ) ?: [];
    }

    /**
     * Tutte le squadre di una campagna con conteggio partecipanti.
     * Utile per la vista admin squadre.
     *
     * @return object[]  Ogni oggetto ha i campi di aw_teams + participant_count.
     */
    public function get_by_campaign_with_count( int $campaign_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*,
                        COUNT(p.id) AS participant_count
                 FROM {$wpdb->prefix}aw_teams t
                 LEFT JOIN {$wpdb->prefix}aw_participants p
                        ON p.team_id = t.id
                 WHERE t.campaign_id = %d
                 GROUP BY t.id
                 ORDER BY t.name ASC",
                $campaign_id
            )
        ) ?: [];
    }

    /**
     * Squadra con elenco completo dei partecipanti.
     * Usato nella vista dettaglio squadra nel backoffice.
     *
     * @return object|null  Oggetto squadra con proprietà aggiuntiva `participants` (array).
     */
    public function find_with_participants( int $team_id ): ?object {
        $team = $this->find( $team_id );

        if ( ! $team ) {
            return null;
        }

        global $wpdb;

        $team->participants = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aw_participants
                 WHERE team_id = %d
                 ORDER BY last_name ASC, first_name ASC",
                $team_id
            )
        ) ?: [];

        return $team;
    }

    /**
     * Conta le squadre di una campagna.
     */
    public function count_by_campaign( int $campaign_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}aw_teams WHERE campaign_id = %d",
                $campaign_id
            )
        );
    }

    // ─── Scrittura ───────────────────────────────────────────────────────────

    /**
     * Inserisce una nuova squadra.
     *
     * @return int|false  ID inserito, false in caso di errore.
     */
    public function insert( array $data ): int|false {
        global $wpdb;

        $data['created_at'] = current_time( 'mysql', true );

        $result = $wpdb->insert(
            "{$wpdb->prefix}aw_teams",
            $data,
            $this->get_format( $data )
        );

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    /**
     * Aggiorna una squadra.
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;

        return $wpdb->update(
            "{$wpdb->prefix}aw_teams",
            $data,
            [ 'id' => $id ],
            $this->get_format( $data ),
            [ '%d' ]
        ) !== false;
    }

    /**
     * Elimina una squadra in modo atomico.
     *
     * Operazioni in transazione:
     * 1. Deassegna i partecipanti (team_id → NULL)
     * 2. Cancella la riga squadra
     *
     * I partecipanti restano in aw_participants — la rimozione fisica
     * è responsabilità del chiamante (es. Workflow alla cancellazione campagna).
     */
    public function delete( int $id ): bool {
        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        // 1. Deassegna partecipanti: NULL passato come valore PHP null con formato %s
        //    è l'unico modo sicuro per ottenere SQL NULL via wpdb.
        $deassigned = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}aw_participants SET team_id = NULL WHERE team_id = %d",
                $id
            )
        );

        if ( $deassigned === false ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        // 2. Cancella la squadra
        $deleted = $wpdb->delete(
            "{$wpdb->prefix}aw_teams",
            [ 'id' => $id ],
            [ '%d' ]
        );

        if ( $deleted === false ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        $wpdb->query( 'COMMIT' );
        return true;
    }

    // ─── Helpers privati ─────────────────────────────────────────────────────

    private function get_format( array $data ): array {
        $int_fields = [ 'campaign_id', 'capacity' ];

        $formats = [];
        foreach ( $data as $key => $value ) {
            if ( $value === null ) {
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