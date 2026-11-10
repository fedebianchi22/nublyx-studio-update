<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Nublyx_Hub_Updater {

    public function __construct() {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    /**
     * Actualiza todos los plugins que tienen update disponible.
     * Preserva el estado activo/inactivo de cada plugin sin importar el resultado.
     */
    public function update_plugins() {
        // Fuerza chequeo fresco (incluye la release del propio plugin en GitHub).
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        $update_plugins = get_site_transient( 'update_plugins' );
        $results        = [];

        if ( empty( $update_plugins->response ) ) {
            return [
                'updated' => [],
                'skipped' => [],
                'errors'  => [],
                'message' => 'No hay plugins para actualizar.',
            ];
        }

        // Guardar la lista completa de plugins a actualizar ANTES de empezar
        // para no perderla cuando el transient se invalide entre upgrades
        $plugins_to_update = $update_plugins->response;

        // Snapshot de plugins activos antes de cualquier actualización
        $active_plugins_before = get_option( 'active_plugins', [] );

        // El propio plugin va último: su upgrade reemplaza el código que está corriendo.
        $self = plugin_basename( NSU_FILE );
        if ( isset( $plugins_to_update[ $self ] ) ) {
            $own = $plugins_to_update[ $self ];
            unset( $plugins_to_update[ $self ] );
            $plugins_to_update[ $self ] = $own;
        }

        foreach ( $plugins_to_update as $plugin_slug => $plugin_data ) {
            $plugin_info = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_slug );
            $plugin_name = $plugin_info['Name'] ?: $plugin_slug;
            $was_active  = in_array( $plugin_slug, $active_plugins_before );

            // Restaurar el plugin en el transient antes de cada upgrade
            // Esto evita que upgrades previos invaliden el contexto del siguiente
            $current_transient = get_site_transient( 'update_plugins' );
            if ( empty( $current_transient->response[ $plugin_slug ] ) ) {
                if ( ! is_object( $current_transient ) ) {
                    $current_transient = new stdClass();
                }
                if ( ! isset( $current_transient->response ) ) {
                    $current_transient->response = [];
                }
                $current_transient->response[ $plugin_slug ] = $plugin_data;
                set_site_transient( 'update_plugins', $current_transient );
            }

            // Skin y upgrader nuevos por cada plugin
            $skin     = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );

            $result      = $upgrader->upgrade( $plugin_slug );
            $skin_output = implode( ' ', $skin->get_upgrade_messages() );

            // Restaurar estado activo si el plugin fue desactivado durante el upgrade
            if ( $was_active && ! is_plugin_active( $plugin_slug ) ) {
                activate_plugin( $plugin_slug, '', false, true );
            }

            if ( is_wp_error( $result ) ) {
                $results['errors'][] = [
                    'slug'   => $plugin_slug,
                    'name'   => $plugin_name,
                    'error'  => $result->get_error_message(),
                    'reason' => $this->classify_error( $result->get_error_message() ),
                ];
            } elseif ( $result === true || is_array( $result ) ) {
                $results['updated'][] = [
                    'slug'        => $plugin_slug,
                    'name'        => $plugin_name,
                    'new_version' => $plugin_data->new_version ?? '—',
                ];
            } elseif ( $result === false ) {
                if ( $this->skin_indicates_success( $skin_output ) ) {
                    $results['updated'][] = [
                        'slug'        => $plugin_slug,
                        'name'        => $plugin_name,
                        'new_version' => $plugin_data->new_version ?? '—',
                    ];
                } elseif ( $this->skin_indicates_already_latest( $skin_output ) ) {
                    $results['skipped'][] = [
                        'slug' => $plugin_slug,
                        'name' => $plugin_name,
                        'note' => 'Ya estaba en la última versión.',
                    ];
                } else {
                    $error_msg = $skin_output ?: 'El upgrade falló sin mensaje de error (posible plugin nulled o sin licencia).';
                    $results['errors'][] = [
                        'slug'   => $plugin_slug,
                        'name'   => $plugin_name,
                        'error'  => $error_msg,
                        'reason' => $this->classify_error( $error_msg ),
                    ];
                }
            } else {
                $results['skipped'][] = [
                    'slug' => $plugin_slug,
                    'name' => $plugin_name,
                    'note' => 'Ya actualizado o sin cambios.',
                ];
            }
        }

        // Verificación final — reactivar cualquier plugin que haya quedado desactivado
        $active_plugins_after = get_option( 'active_plugins', [] );
        foreach ( $active_plugins_before as $slug ) {
            if ( ! in_array( $slug, $active_plugins_after ) ) {
                activate_plugin( $slug, '', false, true );
            }
        }

        return $results;
    }

    /**
     * Actualiza todos los temas con updates disponibles.
     */
    public function update_themes() {
        wp_update_themes();

        $update_themes = get_site_transient( 'update_themes' );
        $results       = [];

        if ( empty( $update_themes->response ) ) {
            return [
                'updated' => [],
                'errors'  => [],
                'message' => 'No hay temas para actualizar.',
            ];
        }

        $themes_to_update = $update_themes->response;

        foreach ( $themes_to_update as $theme_slug => $theme_data ) {
            $theme = wp_get_theme( $theme_slug );

            // Restaurar el tema en el transient antes de cada upgrade
            $current_transient = get_site_transient( 'update_themes' );
            if ( empty( $current_transient->response[ $theme_slug ] ) ) {
                if ( ! is_object( $current_transient ) ) {
                    $current_transient = new stdClass();
                }
                if ( ! isset( $current_transient->response ) ) {
                    $current_transient->response = [];
                }
                $current_transient->response[ $theme_slug ] = $theme_data;
                set_site_transient( 'update_themes', $current_transient );
            }

            $skin     = new Automatic_Upgrader_Skin();
            $upgrader = new Theme_Upgrader( $skin );

            $result      = $upgrader->upgrade( $theme_slug );
            $skin_output = implode( ' ', $skin->get_upgrade_messages() );

            if ( is_wp_error( $result ) ) {
                $results['errors'][] = [
                    'slug'   => $theme_slug,
                    'name'   => $theme->get( 'Name' ) ?: $theme_slug,
                    'error'  => $result->get_error_message(),
                    'reason' => $this->classify_error( $result->get_error_message() ),
                ];
            } elseif ( $result === true || is_array( $result ) ) {
                $results['updated'][] = [
                    'slug'        => $theme_slug,
                    'name'        => $theme->get( 'Name' ) ?: $theme_slug,
                    'new_version' => $theme_data['new_version'] ?? '—',
                ];
            } elseif ( $result === false ) {
                if ( $this->skin_indicates_success( $skin_output ) || $this->skin_indicates_already_latest( $skin_output ) ) {
                    $results['updated'][] = [
                        'slug'        => $theme_slug,
                        'name'        => $theme->get( 'Name' ) ?: $theme_slug,
                        'new_version' => $theme_data['new_version'] ?? '—',
                    ];
                } else {
                    $error_msg = $skin_output ?: 'El upgrade del tema falló (posible tema nulled o sin licencia).';
                    $results['errors'][] = [
                        'slug'   => $theme_slug,
                        'name'   => $theme->get( 'Name' ) ?: $theme_slug,
                        'error'  => $error_msg,
                        'reason' => $this->classify_error( $error_msg ),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Actualiza WordPress core si hay una versión disponible.
     */
    public function update_core() {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        wp_version_check();
        $update_core = get_site_transient( 'update_core' );

        if ( empty( $update_core->updates ) ) {
            return [
                'status'  => 'up_to_date',
                'message' => 'WordPress ya está en la última versión.',
            ];
        }

        $update = null;
        foreach ( $update_core->updates as $candidate ) {
            if ( $candidate->response === 'upgrade' ) {
                $update = $candidate;
                break;
            }
        }

        if ( ! $update ) {
            return [
                'status'  => 'up_to_date',
                'message' => 'No hay actualizaciones de core disponibles.',
            ];
        }

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader( $skin );
        $result   = $upgrader->upgrade( $update );

        if ( is_wp_error( $result ) ) {
            return [
                'status'  => 'error',
                'error'   => $result->get_error_message(),
                'version' => $update->current ?? '—',
            ];
        }

        return [
            'status'      => 'updated',
            'new_version' => $update->current ?? '—',
            'message'     => 'WordPress core actualizado correctamente.',
        ];
    }

    /**
     * Detecta si el mensaje del skin indica éxito real.
     */
    private function skin_indicates_success( $skin_output ) {
        $output_lower = strtolower( $skin_output );
        return strpos( $output_lower, 'updated successfully' ) !== false;
    }

    /**
     * Detecta si el plugin ya estaba en la última versión.
     */
    private function skin_indicates_already_latest( $skin_output ) {
        $output_lower = strtolower( $skin_output );
        return strpos( $output_lower, 'at the latest version' ) !== false
            || strpos( $output_lower, 'no update required' ) !== false;
    }

    /**
     * Clasifica el tipo de error para el informe.
     */
    private function classify_error( $message ) {
        $message_lower = strtolower( $message );

        if ( strpos( $message_lower, 'download failed' ) !== false ||
             strpos( $message_lower, '403' ) !== false ||
             strpos( $message_lower, 'unauthorized' ) !== false ||
             strpos( $message_lower, 'license' ) !== false ||
             strpos( $message_lower, 'subscription' ) !== false ) {
            return 'nulled_or_unlicensed';
        }

        if ( strpos( $message_lower, 'destination folder' ) !== false ||
             strpos( $message_lower, 'could not copy' ) !== false ) {
            return 'filesystem_error';
        }

        if ( strpos( $message_lower, 'connection' ) !== false ||
             strpos( $message_lower, 'timeout' ) !== false ) {
            return 'connection_error';
        }

        return 'unknown';
    }
}