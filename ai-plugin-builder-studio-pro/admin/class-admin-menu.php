<?php
/**
 * Admin menu registration and page rendering.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio\Admin;

use AIPluginBuilderStudio\Includes\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Admin_Menu
 */
class Admin_Menu {

    /**
     * Slug for the top-level admin page.
     */
    const MENU_SLUG = 'ai-plugin-builder-studio-pro';

    /**
     * Admin page hook suffix.
     *
     * @var string
     */
    protected $page_hook = '';

    /**
     * Available tab definitions.
     *
     * @var array
     */
    protected $tabs = [];

    /**
     * Connection handler instance.
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
     * Admin_Menu constructor.
     *
     * @param AI_Connection $connection AI connection handler.
     */
    public function __construct( AI_Connection $connection ) {
        $this->connection = $connection;
        $this->logger     = new Logger();

        $this->tabs = [
            'connect'  => [
                'title' => \__( 'Connect AI Model', 'ai-plugin-builder-studio' ),
                'view'  => 'dashboard.php',
            ],
            'create'   => [
                'title' => \__( 'Create New Plugin', 'ai-plugin-builder-studio' ),
                'view'  => 'create-plugin.php',
            ],
            'manage'   => [
                'title' => \__( 'Manage Plugins', 'ai-plugin-builder-studio' ),
                'view'  => 'manage-plugins.php',
            ],
            'settings' => [
                'title' => \__( 'Settings', 'ai-plugin-builder-studio' ),
                'view'  => 'settings.php',
            ],
            'help'     => [
                'title' => \__( 'Help / Documentation', 'ai-plugin-builder-studio' ),
                'view'  => 'help.php',
            ],
        ];

        \add_action( 'admin_menu', [ $this, 'register_menu' ] );
        \add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Register top-level menu and submenus.
     *
     * @return void
     */
    public function register_menu() {
        $capability = 'manage_options';

        $this->page_hook = \add_menu_page(
            \__( 'AI Plugin Builder Studio', 'ai-plugin-builder-studio' ),
            \__( 'AI Plugin Builder', 'ai-plugin-builder-studio' ),
            $capability,
            static::MENU_SLUG,
            [ $this, 'render_page' ],
            'dashicons-admin-plugins',
            58
        );

        foreach ( $this->tabs as $tab_key => $tab ) {
            \add_submenu_page(
                static::MENU_SLUG,
                $tab['title'],
                $tab['title'],
                $capability,
                static::MENU_SLUG . '&tab=' . $tab_key,
                [ $this, 'render_page' ]
            );
        }
    }

    /**
     * Enqueue admin assets exclusively on plugin pages.
     *
     * @param string $hook Current admin page hook.
     *
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->page_hook ) {
            return;
        }

        \wp_enqueue_style( 'wp-components' );
        \wp_enqueue_style( 'ai-pbs-admin', AI_PBS_PLUGIN_URL . 'assets/css/admin.css', [], AI_PBS_PLUGIN_VERSION );

        \wp_enqueue_script(
            'ai-pbs-admin',
            AI_PBS_PLUGIN_URL . 'assets/js/admin.js',
            [ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data' ],
            AI_PBS_PLUGIN_VERSION,
            true
        );

        $nonce = \wp_create_nonce( 'ai-pbs-admin' );

        $localization = [
            'ajaxUrl'        => \admin_url( 'admin-ajax.php' ),
            'nonce'          => $nonce,
            'currentTab'     => $this->get_current_tab_key(),
            'availableTabs'  => $this->format_tabs_for_js(),
            'connections'    => $this->connection->get_connection_data_for_js(),
            'settings'       => $this->get_settings_data(),
            'i18n'           => [
                'saveSuccess'  => \__( 'Settings saved successfully.', 'ai-plugin-builder-studio' ),
                'saveError'    => \__( 'Unable to process your request. Please try again.', 'ai-plugin-builder-studio' ),
                'confirmDelete'=> \__( 'Are you sure you want to delete this generated plugin? This action cannot be undone.', 'ai-plugin-builder-studio' ),
                'generating'   => \__( 'Generating plugin files...', 'ai-plugin-builder-studio' ),
                'activated'    => \__( 'Plugin successfully created and activated!', 'ai-plugin-builder-studio' ),
                'deactivated'  => \__( 'Plugin deactivated.', 'ai-plugin-builder-studio' ),
            ],
        ];

        \wp_localize_script( 'ai-pbs-admin', 'AI_PBS_APP', $localization );
    }

    /**
     * Render admin page markup.
     *
     * @return void
     */
    public function render_page() {
        $tab_key = $this->get_current_tab_key();

        if ( ! isset( $this->tabs[ $tab_key ] ) ) {
            $tab_key = 'connect';
        }

        $tabs        = $this->tabs;
        $current_tab = $tab_key;

        // Provide variables for partial.
        $currentTab = $current_tab;

        require AI_PBS_PLUGIN_DIR . 'admin/views/partials/header.php';

        $view = AI_PBS_PLUGIN_DIR . 'admin/views/' . $this->tabs[ $tab_key ]['view'];

        if ( file_exists( $view ) ) {
            \load_template( $view, true, [
                'tabs'       => $tabs,
                'currentTab' => $tab_key,
            ] );
        } else {
            \esc_html_e( 'View template missing.', 'ai-plugin-builder-studio' );
        }
    }

    /**
     * Retrieve active tab key from request.
     *
     * @return string
     */
    protected function get_current_tab_key() {
        $tab = isset( $_GET['tab'] ) ? \sanitize_key( \wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! isset( $this->tabs[ $tab ] ) ) {
            $tab = 'connect';
        }

        return $tab;
    }

    /**
     * Format tabs data for JavaScript app.
     *
     * @return array
     */
    protected function format_tabs_for_js() {
        $formatted = [];

        foreach ( $this->tabs as $key => $tab ) {
            $formatted[] = [
                'id'    => $key,
                'title' => $tab['title'],
            ];
        }

        return $formatted;
    }

    /**
     * Provide settings data for JS application.
     *
     * @return array
     */
    protected function get_settings_data() {
        return [
            'defaultModel'      => (string) \get_option( 'ai_pbs_default_model', 'cursor' ),
            'debugLogsEnabled'  => (bool) (int) \get_option( 'ai_pbs_debug_logs_enabled', 0 ),
            'allowLiveDocs'     => (bool) (int) \get_option( 'ai_pbs_allow_live_docs', 0 ),
        ];
    }
}
