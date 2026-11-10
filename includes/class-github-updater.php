<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Actualizaciones del plugin desde GitHub Releases.
 * Muestra el aviso de "actualización disponible" en Plugins y permite actualizar con un click
 * (o automáticamente en la corrida semanal del orquestador).
 *
 * Requiere que cada release tenga adjunto el asset nublyx-studio-update.zip
 * (lo genera .github/workflows/release.yml al pushear un tag vX.Y.Z).
 */
class Nublyx_GitHub_Updater {

    const CACHE = 'nsu_gh_release';
    const ASSET = 'nublyx-studio-update.zip';

    public static function init() {
        if ( strpos( NSU_GITHUB_REPO, 'OWNER' ) !== false ) return; // repo sin configurar
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject' ] );
        add_filter( 'plugins_api',                           [ __CLASS__, 'info' ], 20, 3 );
        add_action( 'upgrader_process_complete',             [ __CLASS__, 'clear_cache' ] );
    }

    private static function release() {
        $cached = get_site_transient( self::CACHE );
        if ( $cached !== false ) return $cached ?: null;

        $resp = wp_remote_get( 'https://api.github.com/repos/' . NSU_GITHUB_REPO . '/releases/latest', [
            'timeout' => 15,
            'headers' => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'nublyx-studio-update' ],
        ] );

        $release = null;
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            foreach ( $data['assets'] ?? [] as $asset ) {
                if ( $asset['name'] === self::ASSET ) {
                    $release = [
                        'version' => ltrim( $data['tag_name'] ?? '', 'vV' ),
                        'package' => $asset['browser_download_url'],
                        'url'     => $data['html_url'] ?? '',
                        'notes'   => $data['body'] ?? '',
                    ];
                    break;
                }
            }
        }

        // Cachea también los fallos (10 min) para no golpear la API de GitHub.
        set_site_transient( self::CACHE, $release ?: '', $release ? 30 * MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
        return $release;
    }

    public static function inject( $transient ) {
        if ( ! is_object( $transient ) ) return $transient;
        $release = self::release();
        if ( ! $release ) return $transient;

        $basename = plugin_basename( NSU_FILE );
        $item = (object) [
            'id'          => 'github.com/' . NSU_GITHUB_REPO,
            'slug'        => dirname( $basename ),
            'plugin'      => $basename,
            'new_version' => $release['version'],
            'url'         => $release['url'],
            'package'     => $release['package'],
        ];

        if ( version_compare( $release['version'], NSU_VERSION, '>' ) ) {
            $transient->response[ $basename ] = $item;
        } else {
            $transient->no_update[ $basename ] = $item;
        }
        return $transient;
    }

    public static function info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== dirname( plugin_basename( NSU_FILE ) ) ) return $result;
        $release = self::release();
        if ( ! $release ) return $result;

        return (object) [
            'name'          => 'Nublyx Studio Update',
            'slug'          => $args->slug,
            'version'       => $release['version'],
            'author'        => 'Nublyx Studio',
            'homepage'      => 'https://github.com/' . NSU_GITHUB_REPO,
            'download_link' => $release['package'],
            'sections'      => [ 'changelog' => nl2br( esc_html( $release['notes'] ) ) ],
        ];
    }

    public static function clear_cache() {
        delete_site_transient( self::CACHE );
    }
}

Nublyx_GitHub_Updater::init();
