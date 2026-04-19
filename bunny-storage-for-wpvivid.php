<?php
/**
 * Plugin Name:       Bunny Storage for WPvivid
 * Plugin URI:        https://nahnuplugins.com/
 * Description:       Adds Bunny.net Storage (FTP, REST API, or S3-compatible) as a remote backup destination for WPvivid Backup & Restore.
 * Version:           1.3.3
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Nahnu Plugins
 * Author URI:        https://nahnuplugins.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bunny-storage-for-wpvivid
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NAHNU_BUNNY_VERSION', '1.3.3' );
define( 'NAHNU_BUNNY_DIR',     plugin_dir_path( __FILE__ ) );
define( 'NAHNU_BUNNY_URL',     plugin_dir_url( __FILE__ ) );

// ─────────────────────────────────────────────────────────────────────────────
// STEP 1 — Register with WPvivid's remote collection AT FILE-LOAD TIME.
//
// WPvivid calls run_wpvivid() directly when its file is required, which builds
// WPvivid_Remote_collection and fires wpvivid_remote_register immediately.
// We must add our filter NOW (before plugins_loaded) so it is present when
// WPvivid fires the filter.  The plugin directory name "bunny-*" sorts before
// "wpvivid-*" alphabetically, guaranteeing WordPress loads our file first.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'wpvivid_remote_register', 'nahnu_bunny_register_remote', 10 );

function nahnu_bunny_register_remote( $collection ) {
    if ( ! class_exists( 'WPvivid_Bunny_Storage' ) ) {
        require_once NAHNU_BUNNY_DIR . 'includes/class-wpvivid-bunny-storage.php';
    }
    $collection['bunny_storage'] = 'WPvivid_Bunny_Storage';
    return $collection;
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP 2 — After plugins_loaded, verify WPvivid is active and boot admin UI.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'nahnu_bunny_storage_init', 20 );

function nahnu_bunny_storage_init() {

    if ( ! defined( 'WPVIVID_PLUGIN_DIR' ) || ! class_exists( 'WPvivid_Remote' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Bunny Storage for WPvivid requires WPvivid Backup & Restore to be installed and active.', 'bunny-storage-for-wpvivid' )
                . '</p></div>';
        } );
        return;
    }

    if ( ! class_exists( 'WPvivid_Bunny_Storage' ) ) {
        require_once NAHNU_BUNNY_DIR . 'includes/class-wpvivid-bunny-storage.php';
    }

    // Register admin UI hooks.
    new WPvivid_Bunny_Storage();

    // ── Safety net ────────────────────────────────────────────────────────────
    // If WPvivid somehow loaded before us, inject our class into its already-built
    // remote_collection via Reflection so AJAX calls work correctly.
    global $wpvivid_plugin;
    if ( isset( $wpvivid_plugin ) && isset( $wpvivid_plugin->remote_collection ) ) {
        try {
            $rc_ref  = new ReflectionClass( $wpvivid_plugin->remote_collection );
            $rc_prop = $rc_ref->getProperty( 'remote_collection' );
            $rc_prop->setAccessible( true );
            $coll = $rc_prop->getValue( $wpvivid_plugin->remote_collection );
            if ( ! isset( $coll['bunny_storage'] ) ) {
                $coll['bunny_storage'] = 'WPvivid_Bunny_Storage';
                $rc_prop->setValue( $wpvivid_plugin->remote_collection, $coll );
            }
        } catch ( \Throwable $e ) {
            // Catches both Exception and Error (PHP 7+) — e.g. ReflectionException,
            // TypeError on setValue() in PHP 8.1 readonly-property edge cases.
            error_log( 'Bunny Storage for WPvivid: could not inject into WPvivid remote_collection — ' . $e->getMessage() );
        }
    }
}
