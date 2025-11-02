<?php
/**
 * Settings controller for AI Plugin Builder Studio Pro.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio\Admin;

use AIPluginBuilderStudio\Includes\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Settings_Controller
 */
class Settings_Controller {

    /**
     * AI connection handler.
     *
     * @var AI_Connection
     */
    protected $connection;

    /**
     * Logger instance.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Settings_Controller constructor.
     *
     * @param AI_Connection $connection Connection handler.
     */
    public function __construct( AI_Connection $connection ) {
        $this->connection = $connection;
        $this->logger     = new Logger();

        \add_action( 'wp_ajax_ai_pbs_save_settings', [ $this, 'handle_save_settings' ] );
    }

    /**
     * Persist plugin settings.
     *
     * @return void
     */
    public function handle_save_settings() {
        $nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        $model          = isset( $_POST['defaultModel'] ) ? \sanitize_key( \wp_unslash( $_POST['defaultModel'] ) ) : 'cursor';
        $debug_logs     = isset( $_POST['debugLogsEnabled'] ) ? '1' === (string) $_POST['debugLogsEnabled'] : false;
        $allow_live_doc = isset( $_POST['allowLiveDocs'] ) ? '1' === (string) $_POST['allowLiveDocs'] : false;

        $models = $this->connection->get_supported_models();

        if ( ! isset( $models[ $model ] ) ) {
            \wp_send_json_error( [ 'message' => \__( 'Unknown default model supplied.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        \update_option( 'ai_pbs_default_model', $model, false );
        \update_option( 'ai_pbs_debug_logs_enabled', $debug_logs ? 1 : 0, false );
        \update_option( 'ai_pbs_allow_live_docs', $allow_live_doc ? 1 : 0, false );

        $this->logger->log( 'Settings updated', [
            'default_model' => $model,
            'debug_logs'    => $debug_logs,
            'live_docs'     => $allow_live_doc,
        ] );

        \wp_send_json_success(
            [
                'message' => \__( 'Settings saved successfully.', 'ai-plugin-builder-studio' ),
            ]
        );
    }
}
