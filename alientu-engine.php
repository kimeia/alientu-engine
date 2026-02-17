<?php
/**
 * Plugin Name:       Alientu Engine
 * Plugin URI:        https://alientu.it
 * Description:       Sistema di iscrizione per eventi Alientu. Gestisce campagne, iscrizioni, partecipanti e squadre.
 * Version:           1.0.0
 * Author:            Alientu
 * Text Domain:       alientu-engine
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 */

defined( 'ABSPATH' ) || exit;

// ─── Costanti ────────────────────────────────────────────────────────────────

define( 'AW_VERSION',     '1.0.0' );
define( 'AW_PLUGIN_FILE', __FILE__ );
define( 'AW_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'AW_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'AW_DB_VERSION',  '1' );

// ─── Autoloader PSR-4 semplificato ───────────────────────────────────────────

spl_autoload_register( function ( string $class ): void {
    $prefix = 'AW\\';
    $base   = AW_PLUGIN_DIR . 'includes/';

    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $file     = $base . 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// ─── Hook attivazione / disattivazione ───────────────────────────────────────

register_activation_hook( __FILE__, 'aw_activate' );
register_deactivation_hook( __FILE__, 'aw_deactivate' );

/**
 * Attivazione.
 *
 * Ordine obbligatorio:
 * 1. Crea/aggiorna le tabelle DB
 * 2. Registra il CPT aw_campaign (PRIMA del flush, altrimenti le rewrite
 *    del CPT non esistono ancora quando WordPress le scrive)
 * 3. flush_rewrite_rules()
 */
function aw_activate(): void {
    require_once AW_PLUGIN_DIR . 'includes/db/schema.php';

    $result = AW_Schema::install();

    if ( is_wp_error( $result ) ) {
        deactivate_plugins( plugin_basename( AW_PLUGIN_FILE ) );

        wp_die(
            sprintf(
                /* translators: %s: messaggio di errore */
                esc_html__( 'Alientu Engine: impossibile creare le tabelle del database. %s', 'alientu-engine' ),
                esc_html( $result->get_error_message() )
            ),
            esc_html__( 'Errore attivazione plugin', 'alientu-engine' ),
            [ 'back_link' => true ]
        );
    }

    update_option( 'aw_db_version', AW_DB_VERSION );

    // Il CPT deve essere registrato PRIMA del flush_rewrite_rules(),
    // altrimenti WordPress non conosce ancora le sue rewrite e il flush
    // produce un set di regole incompleto.
    require_once AW_PLUGIN_DIR . 'includes/class-campaign.php';
    AW\Campaign::register_cpt();

    flush_rewrite_rules();
}

/**
 * Disattivazione: pulizia soft (le tabelle restano).
 */
function aw_deactivate(): void {
    flush_rewrite_rules();
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────

/**
 * Inizializza il plugin dopo che tutti i plugin sono stati caricati.
 */
function aw_init(): void {
    load_plugin_textdomain(
        'alientu-engine',
        false,
        dirname( plugin_basename( AW_PLUGIN_FILE ) ) . '/languages'
    );

    // Verifica e applica eventuali migration pendenti
    $installed = (string) get_option( 'aw_db_version', '0' );
    if ( version_compare( $installed, AW_DB_VERSION, '<' ) ) {
        require_once AW_PLUGIN_DIR . 'includes/db/schema.php';
        AW_Schema::install();
        update_option( 'aw_db_version', AW_DB_VERSION );
    }

    new AW\Campaign();
    new AW\Rest_Api();

    if ( is_admin() ) {
        new AW\Admin();
    }
}
add_action( 'plugins_loaded', 'aw_init' );