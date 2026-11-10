<?php
/**
 * Plugin Name: Nublyx Studio Update
 * Plugin URI:  https://nublyx.com
 * Description: API de mantenimiento remoto para Nublyx Studio. Permite actualizar WordPress, plugins y temas, y realizar backups completos vía REST API.
 * Version:     1.1.0
 * Author:      Nublyx Studio
 * Author URI:  https://nublyx.com
 * License:     GPL-2.0+
 * Text Domain: nublyx-studio-update
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NSU_VERSION',    '1.1.0' );
define( 'NSU_FILE',       __FILE__ );
// Repo de GitHub desde donde los sitios reciben las actualizaciones del plugin (formato owner/repo).
define( 'NSU_GITHUB_REPO', 'fedebianchi22/nublyx-studio-update' );
define( 'NSU_PATH',       plugin_dir_path( __FILE__ ) );
define( 'NSU_URL',        plugin_dir_url( __FILE__ ) );
define( 'NSU_BACKUP_DIR', WP_CONTENT_DIR . '/nublyx-backups' );

require_once NSU_PATH . 'includes/class-auth.php';
require_once NSU_PATH . 'includes/class-backup.php';
require_once NSU_PATH . 'includes/class-updater.php';
require_once NSU_PATH . 'includes/class-github-updater.php';
require_once NSU_PATH . 'endpoints/rest-api.php';
require_once NSU_PATH . 'admin/settings-page.php';

register_activation_hook( __FILE__, 'nsu_activate' );
register_deactivation_hook( __FILE__, 'nsu_deactivate' );

function nsu_activate() {
    if ( ! file_exists( NSU_BACKUP_DIR ) ) {
        wp_mkdir_p( NSU_BACKUP_DIR );
    }
    $htaccess = NSU_BACKUP_DIR . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
    }
    if ( ! get_option( 'nsu_token' ) ) {
        update_option( 'nsu_token', wp_generate_password( 48, false ) );
    }
    flush_rewrite_rules();
}

function nsu_deactivate() {
    flush_rewrite_rules();
}
