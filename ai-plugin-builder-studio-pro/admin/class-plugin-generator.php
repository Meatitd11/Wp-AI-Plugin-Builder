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
     * Analyze prompt and return clarifying suggestions.
     *
     * @return void
     */
    public function handle_analyze_prompt() {
        $nonce  = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        $prompt = isset( $_POST['prompt'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['prompt'] ) ) : '';

        if ( '' === $prompt ) {
            \wp_send_json_error( [ 'message' => \__( 'Prompt is required.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        $recommendations = $this->suggest_features_from_prompt( $prompt );

        \wp_send_json_success(
            [
                'recommendations' => $recommendations,
            ]
        );
    }

    /**
     * Handle AJAX request to generate a plugin.
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

        $plugin_dir = \trailingslashit( WP_PLUGIN_DIR ) . $slug;

        if ( file_exists( $plugin_dir ) ) {
            \wp_send_json_error( [ 'message' => \__( 'A plugin with this slug already exists. Please choose another name.', 'ai-plugin-builder-studio' ) ], 409 );
        }

        $payload = $this->build_generation_payload(
            $plugin_name,
            $description,
            $version,
            $prompt,
            $features,
            $notes
        );

        $manifest = $this->attempt_remote_generation( $model, $payload );

        if ( \is_wp_error( $manifest ) ) {
            $this->logger->log( 'Remote generation unavailable, falling back to local scaffold', [
                'model'  => $model,
                'reason' => $manifest->get_error_message(),
            ] );

            $manifest = $this->generate_local_scaffold(
                $slug,
                $plugin_name,
                $description,
                $version,
                $prompt,
                $features
            );
        }

        if ( \is_wp_error( $manifest ) || empty( $manifest['files'] ) ) {
            $message = \is_wp_error( $manifest ) ? $manifest->get_error_message() : \__( 'Failed to generate plugin manifest.', 'ai-plugin-builder-studio' );
            \wp_send_json_error( [ 'message' => $message ], 500 );
        }

        $write_result = $this->create_plugin_files( $slug, $manifest['files'] );

        if ( \is_wp_error( $write_result ) ) {
            \wp_send_json_error( [ 'message' => $write_result->get_error_message() ], 500 );
        }

        $plugin_file = $this->determine_plugin_file( $slug, $manifest );

        $activated = false;
        if ( $plugin_file ) {
            $activation = $this->activate_generated_plugin( $plugin_file );
            $activated  = ! \is_wp_error( $activation );

            if ( \is_wp_error( $activation ) ) {
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

        $response = [
            'message'     => $activated ? \__( 'Plugin successfully created and activated!', 'ai-plugin-builder-studio' ) : \__( 'Plugin created. Activation required manually.', 'ai-plugin-builder-studio' ),
            'activated'   => $activated,
            'pluginSlug'  => $slug,
            'pluginFile'  => $plugin_file,
            'files'       => array_keys( $manifest['files'] ),
        ];

        \wp_send_json_success( $response );
    }

    /**
     * Build payload to send to AI model.
     *
     * @param string $name        Plugin name.
     * @param string $description Plugin description.
     * @param string $version     Desired version.
     * @param string $prompt      User prompt.
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
            'features'           => array_map( 'sanitize_key', $features ),
            'notes'              => $notes,
            'wordpress_version'  => \get_bloginfo( 'version' ),
            'php_version'        => PHP_VERSION,
            'site_url'           => \home_url(),
        ];
    }

    /**
     * Attempt to call configured AI service.
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

        $args = [
            'method'      => 'POST',
            'timeout'     => 60,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $credentials['api_key'],
            ],
            'body'        => \wp_json_encode( $request_body ),
            'data_format' => 'body',
        ];

        $response = \wp_remote_post( $endpoint, $args );

        if ( \is_wp_error( $response ) ) {
            return $response;
        }

        $code = \wp_remote_retrieve_response_code( $response );
        $body = \wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'ai_http_error', sprintf( 'HTTP %d: %s', $code, $body ) );
        }

        $decoded = json_decode( $body, true );

        if ( null === $decoded || ! isset( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
            return new WP_Error( 'ai_invalid_response', \__( 'AI service returned an invalid response.', 'ai-plugin-builder-studio' ) );
        }

        return [
            'files'       => $decoded['files'],
            'main_file'   => isset( $decoded['main_file'] ) ? $decoded['main_file'] : '',
            'instructions'=> isset( $decoded['instructions'] ) ? $decoded['instructions'] : '',
        ];
    }

    /**
     * Provide default API endpoint for supported models.
     *
     * @param string $model Model identifier.
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
     * Generate plugin files locally when remote AI is unavailable.
     *
     * @param string $slug         Plugin slug.
     * @param string $name         Plugin name.
     * @param string $description  Description.
     * @param string $version      Version.
     * @param string $prompt       User prompt.
     * @param array  $features     Selected features.
     *
     * @return array
     */
    protected function generate_local_scaffold( $slug, $name, $description, $version, $prompt, array $features ) {
        $class_prefix = $this->generate_class_prefix( $slug );
        $files        = [];

        $files[ $slug . '/' . $slug . '.php' ] = $this->render_local_main_file( $slug, $name, $description, $version, $class_prefix );
        $files[ $slug . '/includes/class-' . $slug . '.php' ] = $this->render_local_core_class( $slug, $class_prefix, $features, $prompt, $description );

        $has_admin = in_array( 'admin-settings', $features, true );
        $has_form  = in_array( 'frontend-form', $features, true );
        $has_short = in_array( 'shortcode', $features, true );

        if ( $has_admin ) {
            $files[ $slug . '/assets/css/admin.css' ] = $this->render_admin_css();
            $files[ $slug . '/assets/js/admin.js' ]  = $this->render_admin_js( $slug );
        }

        if ( in_array( 'database', $features, true ) ) {
            $files[ $slug . '/includes/class-' . $slug . '-database.php' ] = $this->render_database_handler( $slug, $class_prefix );
        }

        if ( in_array( 'rest-api', $features, true ) ) {
            $files[ $slug . '/includes/class-' . $slug . '-rest.php' ] = $this->render_rest_controller( $slug, $class_prefix );
        }

        if ( $has_form || $has_short ) {
            $files[ $slug . '/assets/css/frontend.css' ] = $this->render_frontend_css( $slug );
            $files[ $slug . '/assets/js/frontend.js' ]  = $this->render_frontend_js( $slug );
        }

        if ( $has_form ) {
            $files[ $slug . '/partials/form.php' ] = $this->render_frontend_form( $slug );
        }

        $files[ $slug . '/readme.txt' ] = $this->render_readme( $name, $description, $version, $prompt );
        $files[ $slug . '/uninstall.php' ] = $this->render_uninstall_file( $slug );

        return [
            'files'     => $files,
            'main_file' => $slug . '/' . $slug . '.php',
        ];
    }

    /**
     * Create plugin files on disk using WP filesystem.
     *
     * @param string $slug  Plugin slug.
     * @param array  $files File array path => contents.
     *
     * @return true|WP_Error
     */
    protected function create_plugin_files( $slug, array $files ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $access_type = \get_filesystem_method( [ 'path' => WP_PLUGIN_DIR ] );
        if ( 'direct' !== $access_type ) {
            return new WP_Error( 'filesystem_method', \__( 'Direct filesystem access is required to generate plugins. Please adjust your filesystem method.', 'ai-plugin-builder-studio' ) );
        }

        if ( ! \WP_Filesystem( false, WP_PLUGIN_DIR, true ) ) {
            return new WP_Error( 'filesystem_init', \__( 'Unable to initialize WordPress filesystem API.', 'ai-plugin-builder-studio' ) );
        }

        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            return new WP_Error( 'filesystem_unavailable', \__( 'Filesystem handler not available.', 'ai-plugin-builder-studio' ) );
        }

        $plugin_base = \trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';

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

            $write = $wp_filesystem->put_contents( $target, $contents, FS_CHMOD_FILE );

            if ( ! $write ) {
                return new WP_Error( 'write_failed', sprintf( \__( 'Unable to write file: %s', 'ai-plugin-builder-studio' ), $relative_path ) );
            }
        }

        return true;
    }

    /**
     * Determine plugin file to activate.
     *
     * @param string $slug     Plugin slug.
     * @param array  $manifest Manifest data.
     *
     * @return string
     */
    protected function determine_plugin_file( $slug, array $manifest ) {
        if ( isset( $manifest['main_file'] ) && '' !== $manifest['main_file'] ) {
            return $manifest['main_file'];
        }

        return $slug . '/' . $slug . '.php';
    }

    /**
     * Activate generated plugin using WordPress API.
     *
     * @param string $plugin_file Plugin file relative path.
     *
     * @return true|WP_Error
     */
    protected function activate_generated_plugin( $plugin_file ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ( ! \current_user_can( 'activate_plugins' ) ) {
            return new WP_Error( 'insufficient_permissions', \__( 'You do not have permission to activate plugins.', 'ai-plugin-builder-studio' ) );
        }

        $activate = \activate_plugin( $plugin_file, '', false, true );

        if ( \is_wp_error( $activate ) ) {
            return $activate;
        }

        return true;
    }

    /**
     * Suggest features based on prompt keywords.
     *
     * @param string $prompt Prompt text.
     *
     * @return array
     */
    protected function suggest_features_from_prompt( $prompt ) {
        $prompt_lower = strtolower( $prompt );

        $suggestions = [
            [
                'id'       => 'admin-settings',
                'label'    => \__( 'Include an admin settings dashboard', 'ai-plugin-builder-studio' ),
                'selected' => strpos( $prompt_lower, 'admin' ) !== false || strpos( $prompt_lower, 'setting' ) !== false,
            ],
            [
                'id'       => 'shortcode',
                'label'    => \__( 'Provide a shortcode for front-end rendering', 'ai-plugin-builder-studio' ),
                'selected' => strpos( $prompt_lower, 'shortcode' ) !== false || strpos( $prompt_lower, 'embed' ) !== false,
            ],
            [
                'id'       => 'custom-post-type',
                'label'    => \__( 'Register a custom post type', 'ai-plugin-builder-studio' ),
                'selected' => strpos( $prompt_lower, 'post type' ) !== false || strpos( $prompt_lower, 'catalog' ) !== false,
            ],
            [
                'id'       => 'database',
                'label'    => \__( 'Create dedicated database tables', 'ai-plugin-builder-studio' ),
                'selected' => strpos( $prompt_lower, 'record' ) !== false || strpos( $prompt_lower, 'booking' ) !== false || strpos( $prompt_lower, 'inventory' ) !== false,
            ],
            [
                'id'       => 'rest-api',
                'label'    => \__( 'Expose a REST API endpoint', 'ai-plugin-builder-studio' ),
                'selected' => strpos( $prompt_lower, 'api' ) !== false || strpos( $prompt_lower, 'integrat' ) !== false,
            ],
            [
                'id'       => 'frontend-form',
                'label'    => \__( 'Include a front-end submission form', 'ai-plugin-builder-studio' ),
                'selected' => strpos( $prompt_lower, 'form' ) !== false || strpos( $prompt_lower, 'submission' ) !== false,
            ],
        ];

        return $suggestions;
    }

    /**
     * Generate PHP class prefix based on slug.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function generate_class_prefix( $slug ) {
        $parts = explode( '-', $slug );
        $parts = array_map( 'ucfirst', $parts );

        return implode( '_', $parts );
    }

    /**
     * Render main plugin loader file.
     *
     * @param string $slug         Plugin slug.
     * @param string $name         Plugin name.
     * @param string $description  Description.
     * @param string $version      Version.
     * @param string $class_prefix Class prefix.
     *
     * @return string
     */
    protected function render_local_main_file( $slug, $name, $description, $version, $class_prefix ) {
        $text_domain    = \sanitize_title( $slug );
        $constant_prefix = strtoupper( str_replace( '-', '_', $slug ) );

        $function_name = str_replace( '-', '_', $slug ) . '_run';

        $template = <<<PHP
<?php
/**
 * Plugin Name: {$name}
 * Description: {$description}
 * Version: {$version}
 * Author: Generated via AI Plugin Builder Studio Pro
 * Text Domain: {$text_domain}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( '{$constant_prefix}_VERSION', '{$version}' );
define( '{$constant_prefix}_DIR', plugin_dir_path( __FILE__ ) );
define( '{$constant_prefix}_URL', plugin_dir_url( __FILE__ ) );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-{$slug}.php';

function {$function_name}() {
    \{$class_prefix}::get_instance()->init();
}

add_action( 'plugins_loaded', '{$function_name}' );

register_activation_hook( __FILE__, [ '\\{$class_prefix}', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\\{$class_prefix}', 'deactivate' ] );
PHP;

        return $template;
    }

    /**
     * Render core class file with feature hooks.
     *
     * @param string $slug         Plugin slug.
     * @param string $class_prefix Class prefix.
     * @param array  $features     Selected features.
     * @param string $prompt       Prompt description.
     * @param string $description  Plugin description.
     *
     * @return string
     */
    protected function render_local_core_class( $slug, $class_prefix, array $features, $prompt, $description ) {
        $has_admin     = in_array( 'admin-settings', $features, true );
        $has_shortcode = in_array( 'shortcode', $features, true );
        $has_cpt       = in_array( 'custom-post-type', $features, true );
        $has_db        = in_array( 'database', $features, true );
        $has_rest      = in_array( 'rest-api', $features, true );
        $has_form      = in_array( 'frontend-form', $features, true );

        $constant_prefix = strtoupper( str_replace( '-', '_', $slug ) );

        $methods = [];
        $hooks   = [];

        if ( $has_admin ) {
            $hooks[] = "add_action( 'admin_menu', [ \$this, 'register_admin_menu' ] );";
            $hooks[] = "add_action( 'admin_init', [ \$this, 'register_settings' ] );";
            $hooks[] = "add_action( 'admin_enqueue_scripts', [ \$this, 'enqueue_admin_assets' ] );";
            $methods[] = $this->render_method_register_admin_menu( $slug );
            $methods[] = $this->render_method_register_settings( $slug );
            $methods[] = $this->render_method_enqueue_admin_assets( $slug, $constant_prefix );
        }

        if ( $has_shortcode ) {
            $hooks[] = "add_shortcode( '{$slug}_display', [ \$this, 'render_shortcode' ] );";
            $methods[] = $this->render_method_shortcode( $slug, $has_form, $constant_prefix );
        }

        if ( $has_form || $has_shortcode ) {
            $hooks[] = "add_action( 'wp_enqueue_scripts', [ \$this, 'enqueue_frontend_assets' ] );";
        }

        if ( $has_form ) {
            $hooks[] = "add_action( 'init', [ \$this, 'maybe_handle_form_submission' ] );";
            $methods[] = $this->render_method_enqueue_front_assets( $slug, $constant_prefix );
            $methods[] = $this->render_method_handle_form_submission( $slug, $has_db );
        } elseif ( $has_shortcode ) {
            $methods[] = $this->render_method_enqueue_front_assets( $slug, $constant_prefix );
        }

        if ( $has_cpt ) {
            $hooks[] = "add_action( 'init', [ \$this, 'register_custom_post_type' ] );";
            $methods[] = $this->render_method_register_cpt( $slug );
        }

        if ( $has_db ) {
            $hooks[] = "register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );"; // Already registered in main but ensures table creation.
            $methods[] = $this->render_method_get_table_name( $slug );
            $methods[] = $this->render_method_create_table( $slug );
            if ( $has_form ) {
                $methods[] = $this->render_method_insert_submission( $slug );
            }
        }

        if ( $has_rest ) {
            $hooks[] = "add_action( 'rest_api_init', [ \$this, 'register_rest_routes' ] );";
            $methods[] = $this->render_method_register_rest_routes( $slug, $has_db );
        }

        if ( empty( $hooks ) ) {
            $hooks_code = "        // Hooks are added automatically based on selected features.\n";
        } else {
            $hooks_code = '';
            foreach ( $hooks as $hook ) {
                $hooks_code .= "        {$hook}\n";
            }
        }
        $methods_code = implode( "\n\n", $methods );

        $doc_prompt = str_replace( '*/', '* /', $prompt );
        $doc_description = str_replace( '*/', '* /', $description );

        $template = <<<PHP
<?php
/**
 * Core functionality for {$class_prefix}.
 *
 * Generated based on the prompt: {$doc_prompt}
 * Plugin description: {$doc_description}
 */

class {$class_prefix} {

    /**
     * Singleton instance.
     *
     * @var {$class_prefix}
     */
    protected static $instance;

    /**
     * Retrieve singleton instance.
     *
     * @return {$class_prefix}
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    public function init() {
{$hooks_code}    }

    /**
     * Activation routine.
     *
     * @return void
     */
    public static function activate() {
        if ( method_exists( __CLASS__, 'create_database_table' ) ) {
            static::create_database_table();
        }
    }

    /**
     * Deactivation routine.
     *
     * @return void
     */
    public static function deactivate() {
        // Reserved for cleanup actions.
    }

{$methods_code}
}
PHP;

        return $template;
    }

    /**
     * Render admin menu method.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_method_register_admin_menu( $slug ) {
        $page_title = ucwords( str_replace( '-', ' ', $slug ) );

        return <<<PHP
    /**
     * Register admin menu page.
     *
     * @return void
     */
    public function register_admin_menu() {
        add_menu_page(
            esc_html__( '{$page_title}', '{$slug}' ),
            esc_html__( '{$page_title}', '{$slug}' ),
            'manage_options',
            '{$slug}',
            [ \$this, 'render_admin_page' ],
            'dashicons-admin-generic',
            56
        );
    }

    /**
     * Render admin settings page.
     *
     * @return void
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="wrap"><h1>' . esc_html__( '{$page_title} Settings', '{$slug}' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( '{$slug}_settings' );
        do_settings_sections( '{$slug}_settings' );
        submit_button();
        echo '</form></div>';
    }
PHP;
    }

    /**
     * Render settings registration method.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_method_register_settings( $slug ) {
        return <<<PHP
    /**
     * Register plugin settings fields.
     *
     * @return void
     */
    public function register_settings() {
        register_setting( '{$slug}_settings', '{$slug}_options', [
            'type'              => 'array',
            'sanitize_callback' => [ \$this, 'sanitize_options' ],
            'default'           => [
                'enabled' => true,
            ],
        ] );

        add_settings_section(
            '{$slug}_main_section',
            esc_html__( 'General Settings', '{$slug}' ),
            function () {
                echo '<p>' . esc_html__( 'Configure the primary behaviours for this plugin.', '{$slug}' ) . '</p>';
            },
            '{$slug}_settings'
        );

        add_settings_field(
            '{$slug}_enabled',
            esc_html__( 'Enable functionality', '{$slug}' ),
            function () {
                $options = get_option( '{$slug}_options', [] );
                $checked = isset( $options['enabled'] ) ? (bool) $options['enabled'] : true;
                echo '<label><input type="checkbox" name="{$slug}_options[enabled]" value="1"' . checked( true, $checked, false ) . '/> ' . esc_html__( 'Active', '{$slug}' ) . '</label>';
            },
            '{$slug}_settings',
            '{$slug}_main_section'
        );
    }

    /**
     * Sanitize plugin options.
     *
     * @param array $options Raw options.
     *
     * @return array
     */
    public function sanitize_options( $options ) {
        $options = is_array( $options ) ? $options : [];
        $options['enabled'] = isset( $options['enabled'] ) ? (bool) $options['enabled'] : false;

        return $options;
    }
PHP;
    }

    /**
     * Render admin asset enqueue method.
     *
     * @param string $slug            Plugin slug.
     * @param string $constant_prefix Constant prefix.
     *
     * @return string
     */
    protected function render_method_enqueue_admin_assets( $slug, $constant_prefix ) {
        return <<<PHP
    /**
     * Enqueue admin assets on relevant screens.
     *
     * @param string $hook Current admin page hook.
     *
     * @return void
     */
    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( $hook, '{$slug}' ) ) {
            return;
        }

        wp_enqueue_style( '{$slug}-admin', {$constant_prefix}_URL . 'assets/css/admin.css', [], {$constant_prefix}_VERSION );
        wp_enqueue_script( '{$slug}-admin', {$constant_prefix}_URL . 'assets/js/admin.js', [ 'jquery' ], {$constant_prefix}_VERSION, true );
    }
PHP;
    }

    /**
     * Render shortcode method.
     *
     * @param string $slug     Plugin slug.
     * @param bool   $has_form If front end form should render.
     *
     * @return string
     */
    protected function render_method_shortcode( $slug, $has_form, $constant_prefix ) {
        $form_markup = $has_form
            ? "        if ( function_exists( 'wp_enqueue_script' ) ) {\n            wp_enqueue_style( '{$slug}-frontend', {$constant_prefix}_URL . 'assets/css/frontend.css', [], {$constant_prefix}_VERSION );\n        }\n        ob_start();\n        include plugin_dir_path( __FILE__ ) . '../partials/form.php';\n        return ob_get_clean();"
            : "        return '<div class=\"{$slug}-output\">' . esc_html__( 'Generated by {$slug} plugin.', '{$slug}' ) . '</div>';";

        return <<<PHP
    /**
     * Render shortcode output.
     *
     * @return string
     */
    public function render_shortcode() {
{$form_markup}
    }
PHP;
    }

    /**
     * Render method to enqueue front-end assets.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_method_enqueue_front_assets( $slug, $constant_prefix ) {
        return <<<PHP
    /**
     * Enqueue front-end assets.
     *
     * @return void
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style( '{$slug}-frontend', {$constant_prefix}_URL . 'assets/css/frontend.css', [], {$constant_prefix}_VERSION );
        wp_enqueue_script( '{$slug}-frontend', {$constant_prefix}_URL . 'assets/js/frontend.js', [ 'jquery' ], {$constant_prefix}_VERSION, true );
    }
PHP;
    }

    /**
     * Render method to handle form submission.
     *
     * @param string $slug   Plugin slug.
     * @param bool   $has_db Whether database storage exists.
     *
     * @return string
     */
    protected function render_method_handle_form_submission( $slug, $has_db ) {
        $storage = $has_db
            ? "\n        if ( ! empty( \\$_POST['{$slug}_field'] ) ) {\n            static::insert_submission( sanitize_text_field( wp_unslash( \\$_POST['{$slug}_field'] ) ) );\n        }"
            : '';

        return <<<PHP
    /**
     * Process form submissions.
     *
     * @return void
     */
    public function maybe_handle_form_submission() {
        if ( ! isset( \\$_POST['{$slug}_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( \\$_POST['{$slug}_nonce'] ) ), '{$slug}_submit' ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }
        {$storage}
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url() );
        exit;
    }
PHP;
    }

    /**
     * Render custom post type method.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_method_register_cpt( $slug ) {
        $label = ucwords( str_replace( '-', ' ', $slug ) );

        return <<<PHP
    /**
     * Register custom post type.
     *
     * @return void
     */
    public function register_custom_post_type() {
        register_post_type( '{$slug}_item', [
            'label'               => esc_html__( '{$label} Item', '{$slug}' ),
            'public'              => true,
            'has_archive'         => true,
            'show_in_rest'        => true,
            'supports'            => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
            'rewrite'             => [ 'slug' => '{$slug}-item' ],
        ] );
    }
PHP;
    }

    /**
     * Render helper method returning table name.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_method_get_table_name( $slug ) {
        return <<<PHP
    /**
     * Retrieve database table name.
     *
     * @return string
     */
    protected static function get_table_name() {
        global $wpdb;

        return $wpdb->prefix . '{$slug}_records';
    }
PHP;
    }

    /**
     * Render database table creation method.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_method_create_table( $slug ) {
        return <<<PHP
    /**
     * Create database table for submissions.
     *
     * @return void
     */
    protected static function create_database_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name      = static::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_value TEXT NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }
PHP;
    }

    /**
     * Render insert submission helper.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_method_insert_submission( $slug ) {
        return <<<PHP
    /**
     * Store submission in database.
     *
     * @param string $value Submission value.
     *
     * @return void
     */
    protected static function insert_submission( $value ) {
        global $wpdb;

        $wpdb->insert(
            static::get_table_name(),
            [
                'entry_value' => $value,
                'user_id'     => get_current_user_id(),
                'created_at'  => current_time( 'mysql', 1 ),
            ],
            [ '%s', '%d', '%s' ]
        );
    }
PHP;
    }

    /**
     * Render REST API registration method.
     *
     * @param string $slug   Plugin slug.
     * @param bool   $has_db Database availability.
     *
     * @return string
     */
    protected function render_method_register_rest_routes( $slug, $has_db ) {
        if ( $has_db ) {
            $callback = "                global \\$wpdb;\n                \\$records = \\$wpdb->get_results( 'SELECT * FROM ' . static::get_table_name() . ' ORDER BY created_at DESC', ARRAY_A );";
        } else {
            $callback = "                \\$records = [];";
        }

        return <<<PHP
    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_rest_routes() {
        register_rest_route( '{$slug}/v1', '/records', [
            'methods'             => 'GET',
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'callback'            => function () {
{$callback}
                return rest_ensure_response( \$records );
            },
        ] );
    }

    /**
     * Render default frontend CSS stylesheet.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_frontend_css( $slug ) {
        return <<<CSS
.{$slug}-form-wrapper {
    border: 1px solid #e2e8f0;
    padding: 20px;
    border-radius: 6px;
    background: #ffffff;
}

.{$slug}-form-wrapper label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
}

.{$slug}-form-wrapper input[type="text"],
.{$slug}-form-wrapper textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    margin-bottom: 12px;
}
CSS;
    }

    /**
     * Render default frontend JavaScript file.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_frontend_js( $slug ) {
        return <<<JS
( function ( document ) {
    document.addEventListener( 'DOMContentLoaded', function () {
        var forms = document.querySelectorAll( '.{$slug}-form-wrapper form' );
        forms.forEach( function ( form ) {
            form.addEventListener( 'submit', function () {
                form.classList.add( '{$slug}-form-submitted' );
            } );
        } );
    } );
} )( document );
JS;
    }

    /**
     * Render frontend form partial.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_frontend_form( $slug ) {
        $nonce_field = $slug . '_nonce';

        return <<<PHP
<?php
/**
 * Front-end submission form.
 */
?>
<div class="{$slug}-form-wrapper">
    <form method="post">
        <?php wp_nonce_field( '{$slug}_submit', '{$nonce_field}' ); ?>
        <label for="{$slug}_field"><?php esc_html_e( 'Enter Details', '{$slug}' ); ?></label>
        <textarea id="{$slug}_field" name="{$slug}_field" rows="4" required></textarea>
        <button type="submit" class="button button-primary"><?php esc_html_e( 'Submit', '{$slug}' ); ?></button>
    </form>
</div>
PHP;
    }

    /**
     * Render database handler file.
     *
     * @param string $slug         Plugin slug.
     * @param string $class_prefix Class prefix.
     *
     * @return string
     */
    protected function render_database_handler( $slug, $class_prefix ) {
        return <<<PHP
<?php
/**
 * Database handler for {$class_prefix} plugin.
 */

namespace {$class_prefix}\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Manager {
    // Reserved for extended database logic.
}
PHP;
    }

    /**
     * Render REST controller template placeholder.
     *
     * @param string $slug         Plugin slug.
     * @param string $class_prefix Class prefix.
     *
     * @return string
     */
    protected function render_rest_controller( $slug, $class_prefix ) {
        return <<<PHP
<?php
/**
 * REST controller for {$class_prefix} plugin.
 */

namespace {$class_prefix}\Rest;

use WP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Controller extends WP_REST_Controller {
    // Placeholder for advanced REST logic.
}
PHP;
    }

    /**
     * Render admin CSS placeholder.
     *
     * @return string
     */
    protected function render_admin_css() {
        return <<<CSS
.ai-builder-admin-card {
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    padding: 24px;
    margin-bottom: 24px;
}

.ai-builder-admin-card h2 {
    font-size: 20px;
    margin-bottom: 16px;
}
CSS;
    }

    /**
     * Render admin JS placeholder.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_admin_js( $slug ) {
        return <<<JS
( function ( wp ) {
    if ( ! wp ) {
        return;
    }

    wp.domReady( function () {
        console.info( 'Admin scripts loaded for {$slug}' );
    } );
} )( window.wp );
JS;
    }

    /**
     * Render readme file contents.
     *
     * @param string $name        Plugin name.
     * @param string $description Description.
     * @param string $version     Version.
     * @param string $prompt      Prompt.
     *
     * @return string
     */
    protected function render_readme( $name, $description, $version, $prompt ) {
        $escaped_prompt = str_replace( [ "\r\n", "\r", "\n" ], ' ', $prompt );

        return <<<TXT
=== {$name} ===
Contributors: generated-by-ai
Requires at least: 5.8
Tested up to: 6.5
Stable tag: {$version}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

{$description}

== Description ==
This plugin was generated using AI Plugin Builder Studio Pro based on the following idea:

{$escaped_prompt}

== Installation ==
1. Upload the plugin to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==
= {$version} =
* Initial release generated by AI Plugin Builder Studio Pro.
TXT;
    }

    /**
     * Render uninstall file.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function render_uninstall_file( $slug ) {
        return <<<PHP
<?php
/**
 * Uninstall routine for the generated plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( '{$slug}_options' );
PHP;
    }
}
