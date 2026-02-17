<?php
/**
 * AW_Schema
 *
 * Definizione e installazione delle tabelle custom del plugin.
 * Usare AW_Schema::install() sia in attivazione sia in aggiornamento.
 */

defined( 'ABSPATH' ) || exit;

class AW_Schema {

    /**
     * Crea o aggiorna tutte le tabelle custom tramite dbDelta().
     *
     * @return true|\WP_Error
     */
    public static function install(): true|\WP_Error {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $errors = [];

        foreach ( self::get_schemas() as $sql ) {
            dbDelta( $sql );

            // Verifica che la tabella sia stata effettivamente creata.
            // Usiamo prepare() per essere consistenti con le best practice WP,
            // anche se il nome tabella è generato internamente.
            preg_match( '/CREATE TABLE\s+`?(\S+?)`?\s/i', $sql, $m );
            if ( ! empty( $m[1] ) ) {
                $table  = $m[1];
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                if ( $exists !== $table ) {
                    $errors[] = "Tabella {$table} non creata.";
                }
            }
        }

        if ( ! empty( $errors ) ) {
            $message = implode( ' ', $errors );
            error_log( '[Alientu Engine] Errore schema: ' . $message );
            return new \WP_Error( 'aw_schema_error', $message );
        }

        self::run_migrations();

        return true;
    }

    // ─── SQL delle tabelle ───────────────────────────────────────────────────

    /**
     * Restituisce le istruzioni CREATE TABLE per dbDelta.
     *
     * Note:
     * - ENGINE=InnoDB esplicito: garantisce supporto transazioni
     *   (necessario per il flusso atomico registration → team → participants).
     * - updated_at gestito lato applicativo (non ON UPDATE) per evitare
     *   comportamenti dipendenti dalla configurazione del server MySQL.
     * - payload_json è snapshot raw di collectFormData() — solo per audit/debug.
     *   I dati normalizzati vivono nelle tabelle relazionali.
     */
    private static function get_schemas(): array {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        return [

            // ── aw_registrations ─────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}aw_registrations (
              id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
              campaign_id         BIGINT UNSIGNED  NOT NULL,
              event_id            VARCHAR(50)      NOT NULL,
              registration_type   VARCHAR(20)      NOT NULL,
              registration_code   VARCHAR(30)      DEFAULT NULL,
              status              VARCHAR(30)      NOT NULL DEFAULT 'received',
              payload_json        LONGTEXT,
              total_minimum       DECIMAL(8,2)     DEFAULT NULL,
              total_final         DECIMAL(8,2)     DEFAULT NULL,
              donation            DECIMAL(8,2)     DEFAULT NULL,
              pdf_attachment_id   BIGINT UNSIGNED  DEFAULT NULL,
              referente_name      VARCHAR(150)     DEFAULT NULL,
              referente_email     VARCHAR(150)     DEFAULT NULL,
              referente_phone     VARCHAR(30)      DEFAULT NULL,
              created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at          DATETIME         DEFAULT NULL,
              PRIMARY KEY  (id),
              UNIQUE KEY  registration_code (registration_code),
              KEY  idx_campaign_status (campaign_id, status),
              KEY  idx_campaign_created (campaign_id, created_at),
              KEY  idx_campaign_status_created (campaign_id, status, created_at),
              KEY  idx_event_type (event_id, registration_type),
              KEY  idx_referente_email (referente_email)
            ) {$charset} ENGINE=InnoDB;",

            // ── aw_participants ───────────────────────────────────────────────
            // team_id nullable:
            //   - iscrizioni 'team':       popolato nella stessa transazione DB
            //                              (registration → team → participants)
            //   - iscrizioni 'individual'
            //     o 'social':              NULL, assegnabile manualmente dall'admin
            "CREATE TABLE {$wpdb->prefix}aw_participants (
              id                      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
              campaign_id             BIGINT UNSIGNED  NOT NULL,
              event_id                VARCHAR(50)      NOT NULL,
              source_registration_id  BIGINT UNSIGNED  NOT NULL,
              team_id                 BIGINT UNSIGNED  DEFAULT NULL,
              first_name              VARCHAR(100)     NOT NULL,
              last_name               VARCHAR(100)     NOT NULL,
              email                   VARCHAR(150)     DEFAULT NULL,
              phone                   VARCHAR(30)      DEFAULT NULL,
              age_band                CHAR(1)          DEFAULT NULL,
              attends_social          TINYINT(1)       NOT NULL DEFAULT 0,
              food_notes              TEXT,
              created_at              DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY  (id),
              KEY  idx_registration (source_registration_id),
              KEY  idx_campaign_team (campaign_id, team_id),
              KEY  idx_event (event_id)
            ) {$charset} ENGINE=InnoDB;",

            // ── aw_teams ──────────────────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}aw_teams (
              id          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
              campaign_id BIGINT UNSIGNED   NOT NULL,
              event_id    VARCHAR(50)       NOT NULL,
              name        VARCHAR(100)      NOT NULL,
              color       VARCHAR(50)       DEFAULT NULL,
              capacity    TINYINT UNSIGNED  NOT NULL DEFAULT 12,
              notes       TEXT,
              created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY  (id),
              KEY  idx_campaign (campaign_id),
              KEY  idx_event (event_id)
            ) {$charset} ENGINE=InnoDB;",

            // ── aw_registration_log ───────────────────────────────────────────
            "CREATE TABLE {$wpdb->prefix}aw_registration_log (
              id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
              registration_id  BIGINT UNSIGNED  NOT NULL,
              from_status      VARCHAR(30)      DEFAULT NULL,
              to_status        VARCHAR(30)      NOT NULL,
              note             TEXT,
              triggered_by     VARCHAR(50)      DEFAULT NULL,
              created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY  (id),
              KEY  idx_registration (registration_id),
              KEY  idx_created (created_at)
            ) {$charset} ENGINE=InnoDB;",

        ];
    }

    // ─── Migration versionate ────────────────────────────────────────────────

    /**
     * Esegue le migration in includes/db/migrations/ in ordine numerico.
     * Tiene traccia delle migration applicate tramite l'opzione 'aw_applied_migrations'.
     *
     * Usa un transient come lock per prevenire race condition in caso di
     * attivazione e aggiornamento simultanei (es. multisite o deploy automatici).
     */
    private static function run_migrations(): void {
        // Lock: se già in corso, salta
        if ( get_transient( 'aw_migration_lock' ) ) {
            return;
        }
        set_transient( 'aw_migration_lock', true, 60 );

        try {
            $migrations_dir = AW_PLUGIN_DIR . 'includes/db/migrations/';

            if ( ! is_dir( $migrations_dir ) ) {
                return;
            }

            $applied = (array) get_option( 'aw_applied_migrations', [] );
            $files   = glob( $migrations_dir . '*.php' );

            if ( ! $files ) {
                return;
            }

            sort( $files );

            foreach ( $files as $file ) {
                $name = basename( $file );

                if ( in_array( $name, $applied, true ) ) {
                    continue;
                }

                require_once $file;

                $fn = 'aw_migration_' . pathinfo( $name, PATHINFO_FILENAME );
                if ( function_exists( $fn ) ) {
                    $fn();
                }

                $applied[] = $name;
            }

            update_option( 'aw_applied_migrations', $applied );

        } finally {
            delete_transient( 'aw_migration_lock' );
        }
    }
}