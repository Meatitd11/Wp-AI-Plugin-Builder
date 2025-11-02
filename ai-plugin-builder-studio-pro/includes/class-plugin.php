<?php
/**
 * Core plugin orchestrator.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio;

use AIPluginBuilderStudio\Admin\Admin_Menu;
use AIPluginBuilderStudio\Admin\AI_Connection;
use AIPluginBuilderStudio\Admin\Plugin_Generator;
use AIPluginBuilderStudio\Admin\Plugin_Manager;
use AIPluginBuilderStudio\Admin\Settings_Controller;
use AIPluginBuilderStudio\Includes\DB_Handler;
use AIPluginBuilderStudio\Includes\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Primary plugin bootstrap class.
 */
class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin
     */
    protected static $instance;

    /**
     * Admin menu handler.
     *
     * @var Admin_Menu
     */
    protected $admin_menu;

    /**
     * AI connection handler.
     *
     * @var AI_Connection
     */
    protected $connection;

    /**
     * Plugin generator handler.
     *
     * @var Plugin_Generator
     */
    protected $generator;

    /**
     * Plugin manager handler.
     *
     * @var Plugin_Manager
     */
    protected $manager;

    /**
     * Settings controller.
     *
     * @var Settings_Controller
     */
    protected $settings;

    /**
     * Retrieve singleton instance.
     *
     * @return Plugin
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Initialize plugin hooks and services.
     *
     * @return void
     */
    public function init() {
        add_action( 'init', [ $this, 'load_textdomain' ], 0 );
        add_action( 'init', [ $this, 'boot_components' ], 5 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    /**
     * Instantiate core plugin components after textdomain is available.
     *
     * @return void
     */
    public function boot_components() {
        if ( null !== $this->connection ) {
            return;
        }

        $this->connection = new AI_Connection();
        $this->generator  = new Plugin_Generator( $this->connection );
        $this->manager    = new Plugin_Manager( $this->connection );
        $this->admin_menu = new Admin_Menu( $this->connection );
        $this->settings   = new Settings_Controller( $this->connection );
    }

    /**
     * Register WordPress settings used by the plugin.
     *
     * @return void
     */
    public function register_settings() {
        register_setting( 'ai_pbs_settings', 'ai_pbs_default_model', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'cursor',
        ] );

        register_setting( 'ai_pbs_settings', 'ai_pbs_debug_logs_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => static function ( $value ) {
                return (bool) $value;
            },
            'default'           => false,
        ] );

        register_setting( 'ai_pbs_settings', 'ai_pbs_allow_live_docs', [
            'type'              => 'boolean',
            'sanitize_callback' => static function ( $value ) {
                return (bool) $value;
            },
            'default'           => false,
        ] );
    }

    /**
     * Load plugin textdomain for translations.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'ai-plugin-builder-studio', false, dirname( AI_PBS_PLUGIN_BASENAME ) . '/languages/' );
    }

    /**
     * Run activation routines.
     *
     * @return void
     */
    public static function activate() {
        DB_Handler::create_tables();
    }

    /**
     * Run deactivation routines.
     *
     * @return void
     */
    public static function deactivate() {
        // Reserved for potential cleanup. Ensure logger closes handles.
        $logger = new Logger();
        $logger->flush();
    }
}
