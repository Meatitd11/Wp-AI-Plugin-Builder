<?php
/**
 * Handles AI-powered plugin generation workflows.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio\Admin;

use AIPluginBuilderStudio\Includes\DB_Handler;
use AIPluginBuilderStudio\Includes\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Plugin_Generator
 */
class Plugin_Generator {

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
     * Plugin_Generator constructor.
     *
     * @param AI_Connection $connection Connection handler.
     */
    public function __construct( AI_Connection $connection ) {
        $this->connection = $connection;
        $this->logger     = new Logger();

        \add_action( 'wp_ajax_ai_pbs_generate_plugin', [ $this, 'handle_generate_plugin' ] );
        \add_action( 'wp_ajax_ai_pbs_analyze_prompt', [ $this, 'handle_analyze_prompt' ] );
    }

    /**
     * Analyse prompt and return suggested features.
     *
     * @return void
     */
    public function handle_analyze_prompt() {
        $nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        $prompt = isset( $_POST['prompt'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['prompt'] ) ) : '';

        if ( '' === $prompt ) {
            \wp_send_json_error( [ 'message' => \__( 'Prompt is required.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        \wp_send_json_success(
            [
                'recommendations' => $this->suggest_features_from_prompt( $prompt ),
            ]
        );
    }

    /**
     * Handle plugin generation request.
     *
     * @return void
     */
    public function handle_generate_plugin() {
        $nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        $plugin_name = isset( $_POST['pluginName'] ) ? \sanitize_text_field( \wp_unslash( $_POST['pluginName'] ) ) : '';
        $description = isset( $_POST['description'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['description'] ) ) : '';
        $version     = isset( $_POST['version'] ) ? \sanitize_text_field( \wp_unslash( $_POST['version'] ) ) : '1.0.0';
        $prompt      = isset( $_POST['prompt'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['prompt'] ) ) : '';
        $slug_input  = isset( $_POST['slug'] ) ? \sanitize_title( \wp_unslash( $_POST['slug'] ) ) : '';
        $model       = isset( $_POST['model'] ) ? \sanitize_key( \wp_unslash( $_POST['model'] ) ) : \get_option( 'ai_pbs_default_model', 'cursor' );

        $features = isset( $_POST['features'] ) ? array_map( '\sanitize_key', (array) \wp_unslash( $_POST['features'] ) ) : [];
        $notes    = isset( $_POST['notes'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['notes'] ) ) : '';

        if ( '' === $plugin_name || '' === $prompt ) {
            \wp_send_json_error( [ 'message' => \__( 'Plugin name and prompt are required.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        $slug = $slug_input ? $slug_input : \ai_pbs_generate_slug( $plugin_name );

        $plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug;

        if ( file_exists( $plugin_dir ) ) {
            \wp_send_json_error( [ 'message' => \__( 'A plugin with this slug already exists. Please choose another name.', 'ai-plugin-builder-studio' ) ], 409 );
        }

        $payload = $this->build_generation_payload( $plugin_name, $description, $version, $prompt, $features, $notes );

        $manifest = $this->attempt_remote_generation( $model, $payload );

        if ( is_wp_error( $manifest ) ) {
            $this->logger->log( 'Remote generation unavailable, falling back to local scaffold', [
                'model'  => $model,
                'reason' => $manifest->get_error_message(),
            ] );

            $manifest = $this->generate_local_scaffold( $slug, $plugin_name, $description, $version, $features, $prompt );
        }

        if ( is_wp_error( $manifest ) || empty( $manifest['files'] ) ) {
            $message = is_wp_error( $manifest ) ? $manifest->get_error_message() : \__( 'Failed to generate plugin manifest.', 'ai-plugin-builder-studio' );
            \wp_send_json_error( [ 'message' => $message ], 500 );
        }

        $write_result = $this->create_plugin_files( $slug, $manifest['files'] );

        if ( is_wp_error( $write_result ) ) {
            \wp_send_json_error( [ 'message' => $write_result->get_error_message() ], 500 );
        }

        $plugin_file = $this->determine_plugin_file( $slug, $manifest );
        $activated   = false;

        if ( $plugin_file ) {
            $activation = $this->activate_generated_plugin( $plugin_file );
            $activated  = ! is_wp_error( $activation );

            if ( is_wp_error( $activation ) ) {
                $this->logger->log( 'Plugin activation encountered an error', [
                    'plugin' => $plugin_file,
                    'error'  => $activation->get_error_message(),
                ] );
            }
        }

        DB_Handler::upsert_project(
            [
                'plugin_name'    => $plugin_name,
                'plugin_slug'    => $slug,
                'plugin_version' => $version,
                'description'    => $description,
                'status'         => $activated ? 'active' : 'inactive',
                'ai_model'       => $model,
                'files_manifest' => \wp_json_encode( array_keys( $manifest['files'] ) ),
            ]
        );

        \wp_send_json_success(
            [
                'message'     => $activated ? \__( 'Plugin successfully created and activated!', 'ai-plugin-builder-studio' ) : \__( 'Plugin created. Activation required manually.', 'ai-plugin-builder-studio' ),
                'activated'   => $activated,
                'pluginSlug'  => $slug,
                'pluginFile'  => $plugin_file,
                'files'       => array_keys( $manifest['files'] ),
            ]
        );
    }

    /**
     * Build payload for remote submission.
     *
     * @param string $name        Plugin name.
     * @param string $description Description.
     * @param string $version     Version.
     * @param string $prompt      Prompt text.
     * @param array  $features    Selected features.
     * @param string $notes       Additional notes.
     *
     * @return array
     */
    protected function build_generation_payload( $name, $description, $version, $prompt, array $features, $notes ) {
        return [
            'plugin_name'        => $name,
            'plugin_description' => $description,
            'version'            => $version,
            'prompt'             => $prompt,
            'features'           => $features,
            'notes'              => $notes,
            'wordpress_version'  => \get_bloginfo( 'version' ),
            'php_version'        => PHP_VERSION,
            'site_url'           => \home_url(),
        ];
    }

    /**
     * Attempt remote generation.
     *
     * @param string $model   Model key.
     * @param array  $payload Payload data.
     *
     * @return array|WP_Error
     */
    protected function attempt_remote_generation( $model, array $payload ) {
        $credentials = $this->connection->get_connection_credentials( $model );

        if ( empty( $credentials['api_key'] ) ) {
            return new WP_Error( 'ai_missing_credentials', \__( 'No API credentials configured for the selected model.', 'ai-plugin-builder-studio' ) );
        }

        $endpoint = isset( $credentials['endpoint'] ) && '' !== $credentials['endpoint']
            ? $credentials['endpoint']
            : $this->get_default_endpoint_for_model( $model );

        if ( '' === $endpoint ) {
            return new WP_Error( 'ai_missing_endpoint', \__( 'No endpoint available for the selected model.', 'ai-plugin-builder-studio' ) );
        }

        $response = \wp_remote_post(
            $endpoint,
            [
                'method'  => 'POST',
                'timeout' => 60,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $credentials['api_key'],
                ],
                'body'    => \wp_json_encode(
                    [
                        'model'   => $model,
                        'payload' => $payload,
                    ]
                ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) \wp_remote_retrieve_response_code( $response );
        $body = \wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'ai_http_error', sprintf( 'HTTP %d: %s', $code, $body ) );
        }

        $decoded = json_decode( $body, true );

        if ( null === $decoded || empty( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
            return new WP_Error( 'ai_invalid_response', \__( 'AI service returned an invalid response.', 'ai-plugin-builder-studio' ) );
        }

        return [
            'files'     => $decoded['files'],
            'main_file' => isset( $decoded['main_file'] ) ? $decoded['main_file'] : '',
        ];
    }

    /**
     * Default endpoint helper.
     *
     * @param string $model Model key.
     *
     * @return string
     */
    protected function get_default_endpoint_for_model( $model ) {
        switch ( $model ) {
            case 'openai-gpt4':
            case 'openai-gpt5':
                return 'https://api.openai.com/v1/chat/completions';
            case 'anthropic-sonnet':
                return 'https://api.anthropic.com/v1/messages';
            case 'gemini':
                return 'https://generativelanguage.googleapis.com/v1beta/models';
            default:
                return '';
        }
    }

    /**
     * Write plugin files via WP_Filesystem.
     *
     * @param string $slug  Plugin slug.
     * @param array  $files Files to create.
     *
     * @return true|WP_Error
     */
    protected function create_plugin_files( $slug, array $files ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ( 'direct' !== \get_filesystem_method( [ 'path' => WP_PLUGIN_DIR ] ) ) {
            return new WP_Error( 'filesystem_method', \__( 'Direct filesystem access is required to generate plugins. Please adjust your filesystem method.', 'ai-plugin-builder-studio' ) );
        }

        if ( ! \WP_Filesystem( false, WP_PLUGIN_DIR, true ) ) {
            return new WP_Error( 'filesystem_init', \__( 'Unable to initialise the WordPress filesystem API.', 'ai-plugin-builder-studio' ) );
        }

        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            return new WP_Error( 'filesystem_unavailable', \__( 'Filesystem handler not available.', 'ai-plugin-builder-studio' ) );
        }

        $plugin_base = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';

        if ( $wp_filesystem->exists( $plugin_base ) ) {
            return new WP_Error( 'plugin_exists', \__( 'Plugin directory already exists.', 'ai-plugin-builder-studio' ) );
        }

        foreach ( $files as $relative_path => $contents ) {
            $relative = ltrim( $relative_path, '/' );

            if ( 0 === strpos( $relative, $slug . '/' ) ) {
                $relative = substr( $relative, strlen( $slug ) + 1 );
            }

            $target = $plugin_base . $relative;
            $dir    = dirname( $target );

            if ( ! \wp_mkdir_p( $dir ) ) {
                return new WP_Error( 'mkdir_failed', sprintf( \__( 'Unable to create directory: %s', 'ai-plugin-builder-studio' ), $dir ) );
            }

            if ( ! $wp_filesystem->put_contents( $target, $contents, FS_CHMOD_FILE ) ) {
                return new WP_Error( 'write_failed', sprintf( \__( 'Unable to write file: %s', 'ai-plugin-builder-studio' ), $relative ) );
            }
        }

        return true;
    }

    /**
     * Determine plugin file path for activation.
     *
     * @param string $slug     Plugin slug.
     * @param array  $manifest Manifest data.
     *
     * @return string
     */
    protected function determine_plugin_file( $slug, array $manifest ) {
        if ( ! empty( $manifest['main_file'] ) ) {
            return $manifest['main_file'];
        }

        return $slug . '/' . $slug . '.php';
    }

    /**
     * Attempt to activate generated plugin.
     *
     * @param string $plugin_file Plugin file path.
     *
     * @return true|WP_Error
     */
    protected function activate_generated_plugin( $plugin_file ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ( ! \current_user_can( 'activate_plugins' ) ) {
            return new WP_Error( 'insufficient_permissions', \__( 'You do not have permission to activate plugins.', 'ai-plugin-builder-studio' ) );
        }

        $activate = \activate_plugin( $plugin_file, '', false, true );

        if ( is_wp_error( $activate ) ) {
            return $activate;
        }

        return true;
    }

    /**
     * Generate simple local plugin scaffold.
     *
     * @param string $slug        Plugin slug.
     * @param string $name        Plugin name.
     * @param string $description Description.
     * @param string $version     Version.
     * @param array  $features    Features requested.
     * @param string $prompt      Original prompt.
     *
     * @return array
     */
    protected function generate_local_scaffold( $slug, $name, $description, $version, array $features, $prompt ) {
        $constant_prefix = strtoupper( str_replace( '-', '_', $slug ) );
        $class_prefix    = implode( '_', array_map( 'ucfirst', explode( '-', $slug ) ) );
        $text_domain     = sanitize_title( $slug );
        $bootstrap_fn    = str_replace( '-', '_', $slug ) . '_bootstrap';

        $feature_summary = empty( $features ) ? 'None specified.' : implode( ', ', $features );

        $main_template = <<<'PHP'
<?php
/**
 * Plugin Name: {PLUGIN_NAME}
 * Description: {DESCRIPTION}
 * Version: {VERSION}
 * Author: Generated via AI Plugin Builder Studio Pro
 * Text Domain: {TEXT_DOMAIN}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( '{CONST_PREFIX}_VERSION', '{VERSION}' );
define( '{CONST_PREFIX}_DIR', plugin_dir_path( __FILE__ ) );
define( '{CONST_PREFIX}_URL', plugin_dir_url( __FILE__ ) );

require_once {CONST_PREFIX}_DIR . 'includes/class-core.php';

function {BOOTSTRAP_FN}() {
    \{CLASS_PREFIX}_Core::get_instance()->init();
}

add_action( 'plugins_loaded', '{BOOTSTRAP_FN}' );

register_activation_hook( __FILE__, [ '\{CLASS_PREFIX}_Core', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\{CLASS_PREFIX}_Core', 'deactivate' ] );
PHP;

        $core_template = <<<'PHP'
<?php
/**
 * Core functionality for {CLASS_PREFIX} plugin.
 *
 * Prompt summary: {PROMPT}
 * Features requested: {FEATURES}
 */

class {CLASS_PREFIX}_Core {

    /**
     * Singleton instance.
     *
     * @var {CLASS_PREFIX}_Core
     */
    protected static $instance;

    /**
     * Retrieve instance.
     *
     * @return {CLASS_PREFIX}_Core
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Initialise hooks.
     *
     * @return void
     */
    public function init() {
        // TODO: Generated features can hook into WordPress here.
    }

    /**
     * Activation routine.
     *
     * @return void
     */
    public static function activate() {
        // TODO: Add activation tasks.
    }

    /**
     * Deactivation routine.
     *
     * @return void
     */
    public static function deactivate() {
        // TODO: Add deactivation cleanup.
    }
}
PHP;

        $main_file = strtr(
            $main_template,
            [
                '{PLUGIN_NAME}' => $name,
                '{DESCRIPTION}' => $description,
                '{VERSION}'     => $version,
                '{TEXT_DOMAIN}' => $text_domain,
                '{CONST_PREFIX}' => $constant_prefix,
                '{BOOTSTRAP_FN}' => $bootstrap_fn,
                '{CLASS_PREFIX}' => $class_prefix,
            ]
        );

        $core_file = strtr(
            $core_template,
            [
                '{CLASS_PREFIX}' => $class_prefix,
                '{PROMPT}'       => $prompt,
                '{FEATURES}'     => $feature_summary,
            ]
        );

        return [
            'files'     => [
                $slug . '/' . $slug . '.php'       => $main_file,
                $slug . '/includes/class-core.php' => $core_file,
            ],
            'main_file' => $slug . '/' . $slug . '.php',
        ];
    }

    /**
     * Suggest basic features based on prompt keywords.
     *
     * @param string $prompt Prompt text.
     *
     * @return array
     */
    protected function suggest_features_from_prompt( $prompt ) {
        $clean = strtolower( $prompt );

        return [
            [
                'id'       => 'admin-settings',
                'label'    => \__( 'Include an admin settings page', 'ai-plugin-builder-studio' ),
                'selected' => ( false !== strpos( $clean, 'admin' ) ) || ( false !== strpos( $clean, 'setting' ) ),
            ],
            [
                'id'       => 'shortcode',
                'label'    => \__( 'Provide a shortcode for output', 'ai-plugin-builder-studio' ),
                'selected' => ( false !== strpos( $clean, 'shortcode' ) ) || ( false !== strpos( $clean, 'embed' ) ),
            ],
            [
                'id'       => 'custom-post-type',
                'label'    => \__( 'Register a custom post type', 'ai-plugin-builder-studio' ),
                'selected' => ( false !== strpos( $clean, 'post type' ) ) || ( false !== strpos( $clean, 'catalog' ) ),
            ],
            [
                'id'       => 'database',
                'label'    => \__( 'Store data in a custom table', 'ai-plugin-builder-studio' ),
                'selected' => ( false !== strpos( $clean, 'record' ) ) || ( false !== strpos( $clean, 'booking' ) ),
            ],
        ];
    }
}
