<?php
/**
 * Migration 002: Add registration tracking fields to teams table
 * 
 * Adds:
 * - registration_id: links team to the registration that created it
 * - created_by_staff: distinguishes user-created (0) vs staff-created (1) teams
 */

defined( 'ABSPATH' ) || exit;

function aw_migration_002_add_created_by_staff() {
    global $wpdb;
    
    $table = "{$wpdb->prefix}aw_teams";
    
    // Check if columns already exist
    $registration_id_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s 
             AND TABLE_NAME = %s 
             AND COLUMN_NAME = 'registration_id'",
            DB_NAME,
            $table
        )
    );
    
    $created_by_staff_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s 
             AND TABLE_NAME = %s 
             AND COLUMN_NAME = 'created_by_staff'",
            DB_NAME,
            $table
        )
    );
    
    // Add registration_id if it doesn't exist
    if ( ! $registration_id_exists ) {
        $wpdb->query(
            "ALTER TABLE {$table} 
             ADD COLUMN registration_id BIGINT UNSIGNED DEFAULT NULL AFTER campaign_id"
        );
        
        $wpdb->query(
            "ALTER TABLE {$table} 
             ADD KEY idx_registration (registration_id)"
        );
    }
    
    // Add created_by_staff if it doesn't exist
    if ( ! $created_by_staff_exists ) {
        $wpdb->query(
            "ALTER TABLE {$table} 
             ADD COLUMN created_by_staff TINYINT(1) NOT NULL DEFAULT 0 
             AFTER " . ( $registration_id_exists ? 'registration_id' : 'campaign_id' )
        );
    }
    
    error_log( '[Alientu Engine] Migration 002 completed: Added registration_id and created_by_staff to teams table' );
}
