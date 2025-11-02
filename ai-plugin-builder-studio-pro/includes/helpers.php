<?php
/**
 * Helper utilities for AI Plugin Builder Studio Pro.
 *
 * @package AIPluginBuilderStudioPro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'ai_pbs_array_get' ) ) {
    /**
     * Safely retrieve a value from an array using a key with a default fallback.
     *
     * @param array  $array   Source array.
     * @param string $key     Key to retrieve.
     * @param mixed  $default Default value when key does not exist.
     *
     * @return mixed
     */
    function ai_pbs_array_get( $array, $key, $default = '' ) {
        if ( is_array( $array ) && array_key_exists( $key, $array ) ) {
            return $array[ $key ];
        }

        return $default;
    }
}

if ( ! function_exists( 'ai_pbs_generate_slug' ) ) {
    /**
     * Generate a sanitized plugin slug from a provided string.
     *
     * @param string $value Raw slug value.
     *
     * @return string
     */
    function ai_pbs_generate_slug( $value ) {
        $value = sanitize_title( $value );
        return preg_replace( '/[^a-z0-9\-]/', '-', strtolower( $value ) );
    }
}

if ( ! function_exists( 'ai_pbs_get_encryption_key' ) ) {
    /**
     * Build an encryption key using WordPress salts.
     *
     * @return string
     */
    function ai_pbs_get_encryption_key() {
        $salt = wp_salt( 'auth' );
        return hash( 'sha256', $salt . 'ai-plugin-builder-studio-pro', true );
    }
}

if ( ! function_exists( 'ai_pbs_encrypt' ) ) {
    /**
     * Encrypt arbitrary text for secure storage.
     *
     * @param string $plaintext Value to encrypt.
     *
     * @return string
     */
    function ai_pbs_encrypt( $plaintext ) {
        $plaintext = (string) $plaintext;

        if ( '' === $plaintext ) {
            return '';
        }

        $cipher    = 'aes-256-cbc';
        $key       = ai_pbs_get_encryption_key();
        $iv_length = openssl_cipher_iv_length( $cipher );
        $iv        = openssl_random_pseudo_bytes( $iv_length );

        $encrypted = openssl_encrypt( $plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $encrypted ) {
            return '';
        }

        return base64_encode( $iv . $encrypted );
    }
}

if ( ! function_exists( 'ai_pbs_decrypt' ) ) {
    /**
     * Decrypt encrypted text stored by ai_pbs_encrypt().
     *
     * @param string $ciphertext Encrypted string.
     *
     * @return string
     */
    function ai_pbs_decrypt( $ciphertext ) {
        $ciphertext = (string) $ciphertext;

        if ( '' === $ciphertext ) {
            return '';
        }

        $cipher    = 'aes-256-cbc';
        $key       = ai_pbs_get_encryption_key();
        $decoded   = base64_decode( $ciphertext, true );

        if ( false === $decoded ) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length( $cipher );
        $iv        = substr( $decoded, 0, $iv_length );
        $payload   = substr( $decoded, $iv_length );

        if ( strlen( $iv ) !== $iv_length ) {
            return '';
        }

        $decrypted = openssl_decrypt( $payload, $cipher, $key, OPENSSL_RAW_DATA, $iv );

        if ( false === $decrypted ) {
            return '';
        }

        return $decrypted;
    }
}

if ( ! function_exists( 'ai_pbs_sanitize_array' ) ) {
    /**
     * Recursively sanitize an array of mixed inputs.
     *
     * @param array $data Data to sanitize.
     *
     * @return array
     */
    function ai_pbs_sanitize_array( $data ) {
        $sanitized = [];

        foreach ( (array) $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $sanitized[ sanitize_key( $key ) ] = ai_pbs_sanitize_array( $value );
            } else {
                $sanitized[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
            }
        }

        return $sanitized;
    }
}

if ( ! function_exists( 'ai_pbs_get_upload_log_dir' ) ) {
    /**
     * Retrieve or create the log directory path inside uploads.
     *
     * @return string
     */
    function ai_pbs_get_upload_log_dir() {
        $uploads = wp_upload_dir();
        $path    = trailingslashit( $uploads['basedir'] ) . 'ai-builder-logs/';

        if ( ! wp_mkdir_p( $path ) ) {
            return '';
        }

        return $path;
    }
}

if ( ! function_exists( 'ai_pbs_verify_nonce' ) ) {
    /**
     * Verify nonce and current user capability for admin requests.
     *
     * @param string $nonce  Nonce value.
     * @param string $action Expected action.
     *
     * @return void
     */
    function ai_pbs_verify_nonce( $nonce, $action ) {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, $action ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'Security check failed. Please refresh and try again.', 'ai-plugin-builder-studio' ),
                ],
                403
            );
        }
    }
}
