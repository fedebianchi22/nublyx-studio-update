<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', 'nsu_register_routes' );

function nsu_register_routes() {
    $namespace = 'nublyx-studio-update/v1';

    register_rest_route( $namespace, '/status', [
        'methods'             => 'GET',
        'callback'            => 'nsu_status',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
    ]);

    register_rest_route( $namespace, '/backup', [
        'methods'             => 'POST',
        'callback'            => 'nsu_run_backup',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
    ]);

    register_rest_route( $namespace, '/backup/list', [
        'methods'             => 'GET',
        'callback'            => 'nsu_list_backups',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
    ]);

    register_rest_route( $namespace, '/backup/download', [
        'methods'             => 'GET',
        'callback'            => 'nsu_download_backup_file',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
        'args'                => [
            'backup' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            'file'   => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ]);

    register_rest_route( $namespace, '/update/plugins', [
        'methods'             => 'POST',
        'callback'            => 'nsu_update_plugins',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
    ]);

    register_rest_route( $namespace, '/update/themes', [
        'methods'             => 'POST',
        'callback'            => 'nsu_update_themes',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
    ]);

    register_rest_route( $namespace, '/update/core', [
        'methods'             => 'POST',
        'callback'            => 'nsu_update_core',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
    ]);

    register_rest_route( $namespace, '/update/all', [
        'methods'             => 'POST',
        'callback'            => 'nsu_update_all',
        'permission_callback' => [ 'Nublyx_Hub_Auth', 'permission_callback' ],
    ]);
}

function nsu_status( WP_REST_Request $request ) {
    global $wp_version;
    $update_plugins = get_site_transient( 'update_plugins' );
    $update_themes  = get_site_transient( 'update_themes' );
    $update_core    = get_site_transient( 'update_core' );
    $pending_plugins = ! empty( $update_plugins->response ) ? count( $update_plugins->response ) : 0;
    $pending_themes  = ! empty( $update_themes->response )  ? count( $update_themes->response )  : 0;
    $core_update = 'up_to_date';
    if ( ! empty( $update_core->updates ) ) {
        foreach ( $update_core->updates as $u ) {
            if ( $u->response === 'upgrade' ) { $core_update = $u->current; break; }
        }
    }
    return rest_ensure_response([
        'status'          => 'ok',
        'site_name'       => get_bloginfo( 'name' ),
        'site_url'        => get_bloginfo( 'url' ),
        'wp_version'      => $wp_version,
        'php_version'     => PHP_VERSION,
        'plugin_version'  => NSU_VERSION,
        'pending_updates' => [
            'plugins' => $pending_plugins,
            'themes'  => $pending_themes,
            'core'    => $core_update !== 'up_to_date' ? $core_update : null,
        ],
        'timestamp' => current_time( 'c' ),
    ]);
}

function nsu_run_backup( WP_REST_Request $request ) {
    if ( ! ini_get( 'safe_mode' ) ) set_time_limit( 300 );
    $backup = new Nublyx_Hub_Backup();
    $result = $backup->run();
    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response([ 'status' => 'error', 'message' => $result->get_error_message() ], 500);
    }
    return rest_ensure_response( array_merge( [ 'status' => 'ok' ], $result ) );
}

function nsu_list_backups( WP_REST_Request $request ) {
    $backup  = new Nublyx_Hub_Backup();
    $backups = $backup->list_backups();
    return rest_ensure_response([ 'status' => 'ok', 'total' => count( $backups ), 'backups' => $backups ]);
}

function nsu_download_backup_file( WP_REST_Request $request ) {
    $backup_name = $request->get_param( 'backup' );
    $filename    = $request->get_param( 'file' );
    $backup = new Nublyx_Hub_Backup();
    $data   = $backup->get_file_base64( $backup_name, $filename );
    if ( is_wp_error( $data ) ) {
        return new WP_REST_Response([ 'status' => 'error', 'message' => $data->get_error_message() ], $data->get_error_data( 'status' ) ?? 500);
    }
    return rest_ensure_response([ 'status' => 'ok', 'backup' => $backup_name, 'file' => $filename, 'data_base64' => $data ]);
}

function nsu_update_plugins( WP_REST_Request $request ) {
    if ( ! ini_get( 'safe_mode' ) ) set_time_limit( 300 );
    $updater = new Nublyx_Hub_Updater();
    $results = $updater->update_plugins();
    return rest_ensure_response( array_merge( [ 'status' => 'ok' ], $results ) );
}

function nsu_update_themes( WP_REST_Request $request ) {
    if ( ! ini_get( 'safe_mode' ) ) set_time_limit( 300 );
    $updater = new Nublyx_Hub_Updater();
    $results = $updater->update_themes();
    return rest_ensure_response( array_merge( [ 'status' => 'ok' ], $results ) );
}

function nsu_update_core( WP_REST_Request $request ) {
    if ( ! ini_get( 'safe_mode' ) ) set_time_limit( 300 );
    $updater = new Nublyx_Hub_Updater();
    $result  = $updater->update_core();
    return rest_ensure_response( array_merge( [ 'status' => 'ok' ], $result ) );
}

function nsu_update_all( WP_REST_Request $request ) {
    if ( ! ini_get( 'safe_mode' ) ) set_time_limit( 600 );
    $updater = new Nublyx_Hub_Updater();
    $plugins = $updater->update_plugins();
    $themes  = $updater->update_themes();
    $core    = $updater->update_core();
    return rest_ensure_response([ 'status' => 'ok', 'plugins' => $plugins, 'themes' => $themes, 'core' => $core ]);
}