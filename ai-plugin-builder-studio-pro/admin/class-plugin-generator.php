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
     * Analyse a prompt and provide feature suggestions.
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
     * Build structured payload for AI calls.
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
     * Attempt to call the configured remote AI service.
     *
     * @param string $model   Model identifier.
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

        $request_body = [
            'model'   => $model,
            'payload' => $payload,
        ];

        $response = \wp_remote_post(
            $endpoint,
            [
                'method'  => 'POST',
                'timeout' => 60,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $credentials['api_key'],
                ],
                'body'    => \wp_json_encode( $request_body ),
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
     * Provide default endpoint guesses for supported models.
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
     * Create plugin files using WP_Filesystem.
     *
     * @param string $slug  Plugin slug.
     * @param array  $files Array of relative path => contents.
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
     * Determine plugin file for activation.
     *
     * @param string $slug     Plugin slug.
     * @param array  $manifest Manifest array.
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
     * @param string $plugin_file Plugin path.
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
     * Generate a basic plugin locally when remote generation is unavailable.
     *
     * @param string $slug        Plugin slug.
     * @param string $name        Plugin name.
     * @param string $description Description.
     * @param string $version     Version.
     * @param array  $features    Selected features.
     * @param string $prompt      Original prompt text.
     *
     * @return array|WP_Error
     */
    protected function generate_local_scaffold( $slug, $name, $description, $version, array $features, $prompt ) {
        $constant_prefix = strtoupper( str_replace( '-', '_', $slug ) );
        $class_prefix    = implode( '_', array_map( 'ucfirst', explode( '-', $slug ) ) );
        $text_domain     = sanitize_title( $slug );

        $main_file = sprintf(
            "<?php\n" .
            "/**\n" .
            " * Plugin Name: %1\$s\n" .
            " * Description: %2\$s\n" .
            " * Version: %3\$s\n" .
            " * Author: Generated via AI Plugin Builder Studio Pro\n" .
            " * Text Domain: %4\$s\n" .
            " */\n\n" .
            "if ( ! defined( 'ABSPATH' ) ) {\n    exit;\n}\n\n" .
            "define( '%5\$s_VERSION', '%3\$s' );\n" .
            "define( '%5\$s_DIR', plugin_dir_path( __FILE__ ) );\n" .
            "define( '%5\$s_URL', plugin_dir_url( __FILE__ ) );\n\n" .
            "require_once %5\$s_DIR . 'includes/class-core.php';\n\n" .
            "function %6\$s_bootstrap() {\n    \%7\$s_Core::get_instance()->init();\n}\n" .
            "add_action( 'plugins_loaded', '%6\$s_bootstrap' );\n\n" .
            "register_activation_hook( __FILE__, [ '\\\%7\$s_Core', 'activate' ] );\n" .
            "register_deactivation_hook( __FILE__, [ '\\\%7\$s_Core', 'deactivate' ] );\n",
            $name,
            $description,
            $version,
            $text_domain,
            $constant_prefix,
            str_replace( '-', '_', $slug ),
            $class_prefix
        );

        $core_class = $this->prepare_core_class( $slug, $class_prefix, $constant_prefix, $features, $prompt );

        $files = [
            $slug . '/' . $slug . '.php'               => $main_file,
            $slug . '/includes/class-core.php'         => $core_class,
            $slug . '/readme.txt'                      => $this->prepare_readme( $name, $description, $version, $prompt ),
            $slug . '/uninstall.php'                   => $this->prepare_uninstall_file( $slug ),
            $slug . '/assets/css/admin.css'            => $this->prepare_admin_css(),
        ];

        if ( in_array( 'frontend-form', $features, true ) || in_array( 'shortcode', $features, true ) ) {
            $files[ $slug . '/assets/css/frontend.css' ] = $this->prepare_frontend_css( $slug );
            $files[ $slug . '/assets/js/frontend.js' ]  = $this->prepare_frontend_js( $slug );
        }

        if ( in_array( 'frontend-form', $features, true ) ) {
            $files[ $slug . '/partials/form.php' ] = $this->prepare_frontend_form( $slug );
        }

        return [
            'files'     => $files,
            'main_file' => $slug . '/' . $slug . '.php',
        ];
    }

    /**
     * Build core class contents.
     *
     * @param string $slug            Slug.
     * @param string $class_prefix    Class prefix.
     * @param string $constant_prefix Constant prefix.
     * @param array  $features        Feature list.
     * @param string $prompt          Original prompt.
     *
     * @return string
     */
    protected function prepare_core_class( $slug, $class_prefix, $constant_prefix, array $features, $prompt ) {
        $init_lines = [];
        $methods    = [];

        if ( in_array( 'admin-settings', $features, true ) ) {
            $init_lines[] = "add_action( 'admin_menu', [ \$this, 'register_admin_menu' ] );";
            $init_lines[] = "add_action( 'admin_init', [ \$this, 'register_settings' ] );";

            $methods[] = sprintf(
                "    public function register_admin_menu() {\n        add_menu_page(\n            esc_html__( '%1\$s Settings', '%2\$s' ),\n            esc_html__( '%1\$s', '%2\$s' ),\n            'manage_options',\n            '%2\$s',\n            [ \$this, 'render_settings_page' ],\n            'dashicons-admin-generic',\n            58\n        );\n    }\n\n    public function render_settings_page() {\n        if ( ! current_user_can( 'manage_options' ) ) {\n            return;\n        }\n\n        echo '<div class=\"wrap\">';\n        echo '<h1>' . esc_html__( '%1\$s', '%2\$s' ) . '</h1>';\n        echo '<form method=\"post\" action=\"options.php\">';\n        settings_fields( '%2\$s_settings' );\n        do_settings_sections( '%2\$s_settings' );\n        submit_button();\n        echo '</form></div>';\n    }\n\n    public function register_settings() {\n        register_setting( '%2\$s_settings', '%2\$s_options', [\n            'type'              => 'array',\n            'sanitize_callback' => [ \$this, 'sanitize_options' ],\n            'default'           => [ 'enabled' => true ],\n        ] );\n\n        add_settings_section(\n            '%2\$s_main_section',\n            esc_html__( 'General Settings', '%2\$s' ),\n            function () {\n                echo '<p>' . esc_html__( 'Configure the core behaviours for this plugin.', '%2\$s' ) . '</p>';\n            },\n            '%2\$s_settings'\n        );\n\n        add_settings_field(\n            '%2\$s_enabled',\n            esc_html__( 'Enable functionality', '%2\$s' ),\n            function () {\n                $options = get_option( '%2\$s_options', [] );\n                $checked = isset( $options['enabled'] ) ? (bool) $options['enabled'] : true;\n                echo '<label><input type=\"checkbox\" name=\"%2\$s_options[enabled]\" value=\"1\"' . checked( true, $checked, false ) . '/> ' . esc_html__( 'Active', '%2\$s' ) . '</label>';\n            },\n            '%2\$s_settings',\n            '%2\$s_main_section'\n        );\n    }\n\n    public function sanitize_options( $options ) {\n        $options = is_array( $options ) ? $options : [];\n        $options['enabled'] = isset( $options['enabled'] ) ? (bool) $options['enabled'] : false;\n\n        return $options;\n    }",
                $class_prefix,
                $slug
            );
        }

        if ( in_array( 'shortcode', $features, true ) ) {
            $init_lines[] = "add_shortcode( '{$slug}_display', [ \$this, 'render_shortcode' ] );";

            $methods[] = sprintf(
                "    public function render_shortcode() {\n        ob_start();\n        echo '<div class="%1\$s-output">' . esc_html__( 'Generated by %1\$s plugin.', '%1\$s' ) . '</div>';\n        return ob_get_clean();\n    }",
                $slug
            );
        }

        if ( in_array( 'frontend-form', $features, true ) ) {
            $init_lines[] = "add_shortcode( '{$slug}_form', [ \$this, 'render_frontend_form' ] );";
            $init_lines[] = "add_action( 'wp_enqueue_scripts', [ \$this, 'enqueue_frontend_assets' ] );";
            $init_lines[] = "add_action( 'init', [ \$this, 'maybe_handle_form_submission' ] );";

            $methods[] = sprintf(
                "    public function enqueue_frontend_assets() {\n        wp_enqueue_style( '%1\$s-frontend', %2\$s_URL . 'assets/css/frontend.css', [], %2\$s_VERSION );\n        wp_enqueue_script( '%1\$s-frontend', %2\$s_URL . 'assets/js/frontend.js', [ 'jquery' ], %2\$s_VERSION, true );\n    }",
                $slug,
                $constant_prefix
            );

            if ( in_array( 'database', $features, true ) ) {
                $insert_line = "        if ( ! empty( \\$_POST['{$slug}_field'] ) ) {\n            static::insert_submission( sanitize_text_field( wp_unslash( \\$_POST['{$slug}_field'] ) ) );\n        }";
            } else {
                $insert_line = '';
            }

            $methods[] = sprintf(
                "    public function maybe_handle_form_submission() {\n        if ( ! isset( \\$_POST['%1\$s_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( \\$_POST['%1\$s_nonce'] ) ), '%1\$s_submit' ) ) {\n            return;\n        }\n\n        %3\$s\n        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url() );\n        exit;\n    }\n\n    public function render_frontend_form() {\n        ob_start();\n        include %2\$s_DIR . 'partials/form.php';\n        return ob_get_clean();\n    }",
                $slug,
                $constant_prefix,
                $insert_line
            );
        }

        if ( in_array( 'custom-post-type', $features, true ) ) {
            $init_lines[] = "add_action( 'init', [ \$this, 'register_custom_post_type' ] );";

            $methods[] = sprintf(
                "    public function register_custom_post_type() {\n        register_post_type( '%1\$s_item', [\n            'label'        => esc_html__( '%2\$s Item', '%1\$s' ),\n            'public'       => true,\n            'show_in_rest' => true,\n            'supports'     => [ 'title', 'editor', 'thumbnail' ],\n        ] );\n    }",
                $slug,
                ucwords( str_replace( '-', ' ', $slug ) )
            );
        }

        if ( in_array( 'database', $features, true ) ) {
            $methods[] = sprintf(
                "    protected static function get_table_name() {\n        global $wpdb;\n\n        return $wpdb->prefix . '%1\$s_records';\n    }\n\n    public static function activate() {\n        static::create_database_table();\n    }\n\n    protected static function create_database_table() {\n        global $wpdb;\n        require_once ABSPATH . 'wp-admin/includes/upgrade.php';\n\n        $table_name      = static::get_table_name();\n        $charset_collate = $wpdb->get_charset_collate();\n\n        $sql = "CREATE TABLE {$table_name} (\n            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n            entry_value TEXT NOT NULL,\n            user_id BIGINT UNSIGNED DEFAULT NULL,\n            created_at DATETIME NOT NULL,\n            PRIMARY KEY (id)\n        ) {$charset_collate};";\n\n        dbDelta( $sql );\n    }\n\n    protected static function insert_submission( $value ) {\n        global $wpdb;\n\n        $wpdb->insert(\n            static::get_table_name(),\n            [\n                'entry_value' => $value,\n                'user_id'     => get_current_user_id(),\n                'created_at'  => current_time( 'mysql', 1 ),\n            ],\n            [ '%s', '%d', '%s' ]\n        );\n    }",
                $slug
            );
        } else {
            $methods[] = "    public static function activate() {}";
        }

        if ( in_array( 'rest-api', $features, true ) ) {
            $init_lines[] = "add_action( 'rest_api_init', [ \$this, 'register_rest_routes' ] );";

            $callback_body = in_array( 'database', $features, true )
                ? "                global \\$wpdb;\n                \\V$records = \\V$wpdb->get_results( 'SELECT * FROM ' . static::get_table_name() . ' ORDER BY created_at DESC', ARRAY_A );"
                : "                \\V$records = [];";

            $callback_body = str_replace( '\\V', '$', $callback_body );

            $methods[] = sprintf(
                "    public function register_rest_routes() {\n        register_rest_route( '%1\$s/v1', '/records', [\n            'methods'             => 'GET',\n            'permission_callback' => function () {\n                return current_user_can( 'manage_options' );\n            },\n            'callback'            => function () {\n%2\$s\n                return rest_ensure_response( $records );\n            },\n        ] );\n    }",
                $slug,
                $callback_body
            );
        }

        if ( empty( $init_lines ) ) {
            $init_lines[] = '// No feature-specific hooks registered.';
        }

        $class_template = "<?php\n/**\n * Core functionality for %1\$s plugin.\n *\n * Generated from prompt: %2\$s\n */\n\nclass %3\$s_Core {\n\n    protected static $instance;\n\n    public static function get_instance() {\n        if ( null === static::$instance ) {\n            static::$instance = new static();\n        }\n\n        return static::$instance;\n    }\n\n    public function init() {\n        %4\$s\n    }\n\n    public static function deactivate() {}\n\n%5\$s\n}\n";

        return sprintf(
            $class_template,
            $class_prefix,
            addslashes( $prompt ),
            $class_prefix,
            implode( "\n        ", $init_lines ),
            implode( "\n\n", $methods )
        );
    }

    /**
     * Build default admin CSS.
     *
     * @return string
     */
    protected function prepare_admin_css() {
        return ".ai-pbs-generated-card {\n    background: #ffffff;\n    border: 1px solid #e2e8f0;\n    border-radius: 8px;\n    padding: 20px;\n    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);\n}\n";
    }

    /**
     * Build frontend CSS.
     *

