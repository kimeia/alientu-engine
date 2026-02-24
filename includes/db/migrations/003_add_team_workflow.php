<?php
/**
 * Migration 003: Team workflow fields and logs
 *
 * Adds to aw_teams:
 * - status (draft|pending_review|needs_changes|approved|locked)
 * - review_note
 * - updated_at
 *
 * Adds aw_team_log table for status/composition history.
 */

defined( 'ABSPATH' ) || exit;

function aw_migration_003_add_team_workflow() {
    global $wpdb;

    $teams_table = "{$wpdb->prefix}aw_teams";
    $log_table   = "{$wpdb->prefix}aw_team_log";

    // status
    $status_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'status'",
            DB_NAME,
            $teams_table
        )
    );
    if ( ! $status_exists ) {
        $wpdb->query( "ALTER TABLE {$teams_table} ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'draft' AFTER capacity" );
        $wpdb->query( "ALTER TABLE {$teams_table} ADD KEY idx_campaign_status (campaign_id, status)" );
    }

    // review_note
    $review_note_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'review_note'",
            DB_NAME,
            $teams_table
        )
    );
    if ( ! $review_note_exists ) {
        $wpdb->query( "ALTER TABLE {$teams_table} ADD COLUMN review_note TEXT AFTER status" );
    }

    // updated_at
    $updated_at_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s
             AND TABLE_NAME = %s
             AND COLUMN_NAME = 'updated_at'",
            DB_NAME,
            $teams_table
        )
    );
    if ( ! $updated_at_exists ) {
        $wpdb->query( "ALTER TABLE {$teams_table} ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at" );
    }

    // aw_team_log
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        team_id BIGINT UNSIGNED NOT NULL,
        from_status VARCHAR(30) DEFAULT NULL,
        to_status VARCHAR(30) DEFAULT NULL,
        note TEXT,
        triggered_by VARCHAR(50) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_team (team_id),
        KEY idx_created (created_at)
    ) {$charset} ENGINE=InnoDB;";
    dbDelta( $sql );

    error_log( '[Alientu Engine] Migration 003 completed: team workflow fields and logs ready' );
}
