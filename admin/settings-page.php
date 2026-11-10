<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'nsu_admin_menu' );
add_action( 'admin_init', 'nsu_handle_actions' );

function nsu_admin_menu() {
    add_options_page(
        'Nublyx Hub',
        'Nublyx Hub',
        'manage_options',
        'nublyx-studio-update',
        'nsu_settings_page'
    );
}

function nsu_handle_actions() {
    if ( ! isset( $_POST['nsu_action'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! check_admin_referer( 'nsu_action' ) ) return;

    if ( $_POST['nsu_action'] === 'regenerate_token' ) {
        update_option( 'nsu_token', wp_generate_password( 48, false ) );
        add_settings_error( 'nsu', 'token_regenerated', 'Token regenerado correctamente.', 'updated' );
    }

    if ( $_POST['nsu_action'] === 'save_token' && ! empty( $_POST['nsu_token'] ) ) {
        update_option( 'nsu_token', sanitize_text_field( $_POST['nsu_token'] ) );
        add_settings_error( 'nsu', 'token_saved', 'Token guardado correctamente.', 'updated' );
    }
}

function nsu_settings_page() {
    $token    = get_option( 'nsu_token', '' );
    $site_url = get_bloginfo( 'url' );
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span style="background:#290C49;color:white;padding:4px 12px;border-radius:6px;font-size:14px;">Nublyx</span>
            Hub — Configuración
        </h1>

        <?php settings_errors( 'nsu' ); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;max-width:900px;">

            <!-- Token -->
            <div style="background:white;border:1px solid #ddd;border-radius:8px;padding:20px;">
                <h2 style="margin-top:0;font-size:16px;color:#290C49;">🔑 Token de Autenticación</h2>
                <p style="color:#666;font-size:13px;">Este token debe cargarse en el sites.json del orquestador (GitHub Actions). Guardalo en un lugar seguro.</p>

                <div style="background:#f8f5ff;border:1px solid #c5b8e8;border-radius:6px;padding:12px;margin:12px 0;word-break:break-all;font-family:monospace;font-size:13px;color:#290C49;">
                    <?php echo esc_html( $token ); ?>
                </div>

                <form method="post" style="margin-bottom:12px;">
                    <?php wp_nonce_field( 'nsu_action' ); ?>
                    <input type="hidden" name="nsu_action" value="regenerate_token">
                    <button type="submit" class="button button-secondary" onclick="return confirm('¿Regenerar el token? Deberás actualizarlo en el secret SITES_JSON de GitHub.')">
                        🔄 Regenerar Token
                    </button>
                </form>

                <form method="post">
                    <?php wp_nonce_field( 'nsu_action' ); ?>
                    <input type="hidden" name="nsu_action" value="save_token">
                    <p style="font-size:12px;color:#666;">O ingresá un token personalizado:</p>
                    <input type="text" name="nsu_token" class="regular-text" placeholder="Token personalizado..." style="width:100%;">
                    <button type="submit" class="button button-primary" style="margin-top:8px;">Guardar Token</button>
                </form>
            </div>

            <!-- Endpoints -->
            <div style="background:white;border:1px solid #ddd;border-radius:8px;padding:20px;">
                <h2 style="margin-top:0;font-size:16px;color:#290C49;">🔌 Endpoints disponibles</h2>
                <p style="color:#666;font-size:13px;">Usar header <code>X-Nublyx-Token: [token]</code> en cada request.</p>

                <?php
                $endpoints = [
                    [ 'GET',  '/wp-json/nublyx-studio-update/v1/status',          'Estado del sitio y updates pendientes' ],
                    [ 'POST', '/wp-json/nublyx-studio-update/v1/backup',           'Generar backup completo' ],
                    [ 'GET',  '/wp-json/nublyx-studio-update/v1/backup/list',      'Listar backups disponibles' ],
                    [ 'GET',  '/wp-json/nublyx-studio-update/v1/backup/download',  'Descargar archivo de backup (base64)' ],
                    [ 'POST', '/wp-json/nublyx-studio-update/v1/update/plugins',   'Actualizar plugins' ],
                    [ 'POST', '/wp-json/nublyx-studio-update/v1/update/themes',    'Actualizar temas' ],
                    [ 'POST', '/wp-json/nublyx-studio-update/v1/update/core',      'Actualizar WP Core' ],
                    [ 'POST', '/wp-json/nublyx-studio-update/v1/update/all',       'Actualizar todo (plugins+temas+core)' ],
                ];
                $method_colors = [ 'GET' => '#2e7d32', 'POST' => '#1565c0' ];
                ?>

                <table style="width:100%;font-size:12px;border-collapse:collapse;">
                    <?php foreach ( $endpoints as [$method, $path, $desc] ) : ?>
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:6px 4px;white-space:nowrap;">
                            <span style="background:<?php echo $method_colors[$method]; ?>;color:white;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:11px;">
                                <?php echo $method; ?>
                            </span>
                        </td>
                        <td style="padding:6px 6px;font-family:monospace;color:#444;font-size:11px;"><?php echo esc_html( $path ); ?></td>
                        <td style="padding:6px 4px;color:#666;"><?php echo esc_html( $desc ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- Info del sitio -->
            <div style="background:white;border:1px solid #ddd;border-radius:8px;padding:20px;">
                <h2 style="margin-top:0;font-size:16px;color:#290C49;">📋 Datos para sites.json</h2>
                <p style="color:#666;font-size:13px;">Copiá este bloque dentro del arreglo de sites.json.</p>

                <?php
                global $wp_version;
                $rows = [
                    [ 'URL del sitio',   $site_url ],
                    [ 'Endpoint base',   $site_url . '/wp-json/nublyx-studio-update/v1' ],
                    [ 'WP Version',      $wp_version ],
                    [ 'PHP Version',     PHP_VERSION ],
                    [ 'Plugin Version',  NSU_VERSION ],
                    [ 'Directorio backup', str_replace( ABSPATH, '/', NSU_BACKUP_DIR ) ],
                ];
                ?>
                <pre style="background:#f8f5ff;border:1px solid #c5b8e8;border-radius:6px;padding:10px;font-size:12px;overflow:auto;"><?php echo esc_html( wp_json_encode( [ 'name' => get_bloginfo( 'name' ), 'url' => $site_url, 'hub_token' => $token ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <?php foreach ( $rows as [$label, $value] ) : ?>
                    <tr style="border-bottom:1px solid #f5f0ff;">
                        <td style="padding:6px 4px;color:#666;white-space:nowrap;"><?php echo esc_html( $label ); ?></td>
                        <td style="padding:6px 4px;font-family:monospace;color:#290C49;word-break:break-all;"><?php echo esc_html( $value ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- Backups -->
            <div style="background:white;border:1px solid #ddd;border-radius:8px;padding:20px;">
                <h2 style="margin-top:0;font-size:16px;color:#290C49;">💾 Backups almacenados</h2>
                <?php
                $backup_obj = new Nublyx_Hub_Backup();
                $backups    = $backup_obj->list_backups();
                if ( empty( $backups ) ) : ?>
                    <p style="color:#999;font-size:13px;">No hay backups generados todavía.</p>
                <?php else : ?>
                    <table style="width:100%;font-size:13px;border-collapse:collapse;">
                        <tr style="background:#f8f5ff;">
                            <th style="padding:6px;text-align:left;color:#554095;">Nombre</th>
                            <th style="padding:6px;text-align:right;color:#554095;">Tamaño</th>
                            <th style="padding:6px;text-align:right;color:#554095;">Archivos</th>
                        </tr>
                        <?php foreach ( $backups as $b ) : ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:6px;font-family:monospace;font-size:11px;"><?php echo esc_html( $b['name'] ); ?></td>
                            <td style="padding:6px;text-align:right;color:#666;"><?php echo esc_html( $b['size_mb'] ); ?> MB</td>
                            <td style="padding:6px;text-align:right;color:#666;"><?php echo count( $b['files'] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php
}
