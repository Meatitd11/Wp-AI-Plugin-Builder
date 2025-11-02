<?php
/**
 * Manages AI model connections and credentials.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio\Admin;

use AIPluginBuilderStudio\Includes\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class AI_Connection
 */
class AI_Connection {

    /**
     * Option name for stored connection credentials.
     */
    const OPTION_NAME = 'ai_pbs_ai_connections';

    /**
     * Logger instance.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Supported AI model definitions.
     *
     * @var array
     */
    protected $models = [];

    /**
     * AI_Connection constructor.
     */
    public function __construct() {
        $this->logger = new Logger();

        $this->models = [
            'cursor' => [
                'label'       => \__( 'Cursor AI (Default)', 'ai-plugin-builder-studio' ),
                'description' => \__( 'High-speed generative development model optimized for WordPress.', 'ai-plugin-builder-studio' ),
                'requiresEndpoint' => false,
            ],
            'openai-gpt4' => [
                'label'       => \__( 'OpenAI GPT-4 / GPT-5', 'ai-plugin-builder-studio' ),
                'description' => \__( 'Use OpenAI models via official API.', 'ai-plugin-builder-studio' ),
                'requiresEndpoint' => false,
            ],
            'anthropic-sonnet' => [
                'label'       => \__( 'Anthropic Sonnet / Opus', 'ai-plugin-builder-studio' ),
                'description' => \__( 'Claude Sonnet, Opus, and Composer via Anthropic API.', 'ai-plugin-builder-studio' ),
                'requiresEndpoint' => false,
            ],
            'gemini' => [
                'label'       => \__( 'Gemini API', 'ai-plugin-builder-studio' ),
                'description' => \__( 'Connect to Google Gemini for multimodal generation.', 'ai-plugin-builder-studio' ),
                'requiresEndpoint' => false,
            ],
            'custom' => [
                'label'       => \__( 'Custom API', 'ai-plugin-builder-studio' ),
                'description' => \__( 'Bring your own AI endpoint that accepts compatible payloads.', 'ai-plugin-builder-studio' ),
                'requiresEndpoint' => true,
            ],
        ];

        \add_action( 'wp_ajax_ai_pbs_save_connection', [ $this, 'handle_save_connection' ] );
        \add_action( 'wp_ajax_ai_pbs_disconnect_model', [ $this, 'handle_disconnect_model' ] );
    }

    /**
     * Handle AJAX request to save AI connection credentials.
     *
     * @return void
     */
    public function handle_save_connection() {
        $nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        $model    = isset( $_POST['model'] ) ? \sanitize_key( \wp_unslash( $_POST['model'] ) ) : 'cursor';
        $api_key  = isset( $_POST['apiKey'] ) ? \sanitize_text_field( \wp_unslash( $_POST['apiKey'] ) ) : '';
        $endpoint = isset( $_POST['endpoint'] ) ? \esc_url_raw( \wp_unslash( $_POST['endpoint'] ) ) : '';

        if ( ! isset( $this->models[ $model ] ) ) {
            \wp_send_json_error( [ 'message' => \__( 'Unknown AI model selected.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        if ( '' === $api_key ) {
            \wp_send_json_error( [ 'message' => \__( 'API key is required to connect.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        if ( $this->models[ $model ]['requiresEndpoint'] && '' === $endpoint ) {
            \wp_send_json_error( [ 'message' => \__( 'Endpoint is required for custom API connections.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        $connections = $this->get_connections();

        $connections[ $model ] = [
            'api_key'     => \ai_pbs_encrypt( $api_key ),
            'endpoint'    => $endpoint,
            'status'      => 'connected',
            'updated_at'  => \current_time( 'mysql', 1 ),
        ];

        \update_option( static::OPTION_NAME, $connections, false );

        $this->logger->log( 'AI connection saved', [ 'model' => $model, 'endpoint' => $endpoint ] );

        \wp_send_json_success(
            [
                'message'   => \__( 'Connected successfully!', 'ai-plugin-builder-studio' ),
                'model'     => $model,
                'connection'=> $this->prepare_connection_for_js( $model, $connections[ $model ] ),
            ]
        );
    }

    /**
     * Disconnect a stored AI model configuration.
     *
     * @return void
     */
    public function handle_disconnect_model() {
        $nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        $model = isset( $_POST['model'] ) ? \sanitize_key( \wp_unslash( $_POST['model'] ) ) : '';

        if ( '' === $model ) {
            \wp_send_json_error( [ 'message' => \__( 'Model identifier missing.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        $connections = $this->get_connections();

        if ( isset( $connections[ $model ] ) ) {
            unset( $connections[ $model ] );
            \update_option( static::OPTION_NAME, $connections, false );
            $this->logger->log( 'AI connection removed', [ 'model' => $model ] );
        }

        \wp_send_json_success(
            [
                'message' => \__( 'Connection removed.', 'ai-plugin-builder-studio' ),
            ]
        );
    }

    /**
     * Retrieve stored connections from database.
     *
     * @return array
     */
    public function get_connections() {
        $connections = \get_option( static::OPTION_NAME, [] );

        if ( ! is_array( $connections ) ) {
            return [];
        }

        return $connections;
    }

    /**
     * Prepare data for JS localization.
     *
     * @return array
     */
    public function get_connection_data_for_js() {
        $connections = $this->get_connections();
        $prepared    = [];

        foreach ( $this->models as $key => $model ) {
            $current = isset( $connections[ $key ] ) ? $connections[ $key ] : [];
            $prepared[] = [
                'id'          => $key,
                'label'       => $model['label'],
                'description' => $model['description'],
                'requiresEndpoint' => (bool) $model['requiresEndpoint'],
                'connection'  => $this->prepare_connection_for_js( $key, $current ),
            ];
        }

        return $prepared;
    }

    /**
     * Return sanitized connection representation for UI.
     *
     * @param string $model_key Model identifier.
     * @param array  $connection Raw connection data.
     *
     * @return array
     */
    protected function prepare_connection_for_js( $model_key, $connection ) {
        if ( empty( $connection ) ) {
            return [
                'connected' => false,
                'maskedKey' => '',
                'endpoint'  => '',
                'updatedAt' => '',
            ];
        }

        $plain_key  = isset( $connection['api_key'] ) ? \ai_pbs_decrypt( $connection['api_key'] ) : '';
        $masked_key = $this->mask_api_key( $plain_key );

        return [
            'connected' => true,
            'maskedKey' => $masked_key,
            'endpoint'  => isset( $connection['endpoint'] ) ? $connection['endpoint'] : '',
            'updatedAt' => isset( $connection['updated_at'] ) ? $connection['updated_at'] : '',
        ];
    }

    /**
     * Retrieve decrypted credentials for a given model.
     *
     * @param string $model_key Model identifier.
     *
     * @return array
     */
    public function get_connection_credentials( $model_key ) {
        $connections = $this->get_connections();

        if ( ! isset( $connections[ $model_key ] ) ) {
            return [];
        }

        return [
            'api_key'  => \ai_pbs_decrypt( $connections[ $model_key ]['api_key'] ),
            'endpoint' => isset( $connections[ $model_key ]['endpoint'] ) ? $connections[ $model_key ]['endpoint'] : '',
            'status'   => isset( $connections[ $model_key ]['status'] ) ? $connections[ $model_key ]['status'] : '',
        ];
    }

    /**
     * Mask API key for UI display.
     *
     * @param string $key Plain API key.
     *
     * @return string
     */
    protected function mask_api_key( $key ) {
        $key = (string) $key;

        if ( '' === $key ) {
            return '';
        }

        $length = \strlen( $key );

        if ( $length <= 4 ) {
            return str_repeat( '*', $length );
        }

        if ( $length <= 8 ) {
            return substr( $key, 0, 2 ) . str_repeat( '*', max( 0, $length - 4 ) ) . substr( $key, -2 );
        }

        return substr( $key, 0, 4 ) . str_repeat( '*', max( 0, $length - 8 ) ) . substr( $key, -4 );
    }

    /**
     * Retrieve supported models metadata.
     *
     * @return array
     */
    public function get_supported_models() {
        return $this->models;
    }
}
