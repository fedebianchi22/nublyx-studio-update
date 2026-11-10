<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Nublyx_Hub_Auth {

    /**
     * Valida el token enviado en el header o query param.
     * Retorna true si es válido, WP_Error si no.
     */
    public static function validate( WP_REST_Request $request ) {
        $stored_token = get_option( 'nsu_token', '' );

        if ( empty( $stored_token ) ) {
            return new WP_Error(
                'nsu_no_token',
                'El plugin no tiene un token configurado.',
                [ 'status' => 500 ]
            );
        }

        // Aceptar token por header X-Nublyx-Token o por query param hub_token
        $token = $request->get_header( 'X-Nublyx-Token' );
        if ( empty( $token ) ) {
            $token = $request->get_param( 'hub_token' );
        }

        if ( empty( $token ) ) {
            return new WP_Error(
                'nsu_missing_token',
                'Token no proporcionado. Envialo en el header X-Nublyx-Token.',
                [ 'status' => 401 ]
            );
        }

        // Comparación segura contra timing attacks
        if ( ! hash_equals( $stored_token, $token ) ) {
            return new WP_Error(
                'nsu_invalid_token',
                'Token inválido.',
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Callback de permisos para usar en register_rest_route.
     */
    public static function permission_callback( WP_REST_Request $request ) {
        return self::validate( $request );
    }
}
