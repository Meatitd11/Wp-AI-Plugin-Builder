<?php
/**
 * Manage AI-generated plugins within WordPress.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio\Admin;

use AIPluginBuilderStudio\Includes\DB_Handler;
use AIPluginBuilderStudio\Includes\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Plugin_Manager
 */
class Plugin_Manager {

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
     * Plugin_Manager constructor.
     *
     * @param AI_Connection $connection Connection handler (unused placeholder for future integrations).
     */
    public function __construct( AI_Connection $connection ) {
        $this->connection = $connection;
        $this->logger     = new Logger();

        \add_action( 'wp_ajax_ai_pbs_fetch_plugins', [ $this, 'handle_fetch_plugins' ] );
        \add_action( 'wp_ajax_ai_pbs_plugin_action', [ $this, 'handle_plugin_action' ] );
    }

    /**
     * Return list of generated plugins with statuses.
     *
     * @return void
     */
    public function handle_fetch_plugins() {
        $nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        \require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $projects = DB_Handler::get_projects();
        $data     = [];

        foreach ( (array) $projects as $project ) {
            $slug        = $project->plugin_slug;
            $plugin_file = $this->determine_plugin_file( $slug );
            $is_active   = '' !== $plugin_file ? \is_plugin_active( $plugin_file ) : false;
            $exists      = $this->plugin_exists( $slug );

            $data[] = [
                'id'          => (int) $project->id,
                'name'        => $project->plugin_name,
                'slug'        => $slug,
                'version'     => $project->plugin_version,
                'description' => $project->description,
                'status'      => $is_active ? 'active' : 'inactive',
                'aiModel'     => $project->ai_model,
                'pluginFile'  => $plugin_file,
                'exists'      => $exists,
                'createdAt'   => $project->created_at,
                'updatedAt'   => $project->updated_at,
            ];
        }

        \wp_send_json_success( [ 'plugins' => $data ] );
    }

    /**
     * Handle plugin actions (activate, deactivate, delete, view_code).
     *
     * @return void
     */
    public function handle_plugin_action() {
        $nonce = isset( $_POST['nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['nonce'] ) ) : '';
        \ai_pbs_verify_nonce( $nonce, 'ai-pbs-admin' );

        \require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $action = isset( $_POST['actionType'] ) ? \sanitize_key( \wp_unslash( $_POST['actionType'] ) ) : '';
        $slug   = isset( $_POST['pluginSlug'] ) ? \sanitize_title( \wp_unslash( $_POST['pluginSlug'] ) ) : '';

        if ( '' === $action || '' === $slug ) {
            \wp_send_json_error( [ 'message' => \__( 'Invalid plugin action request.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        $plugin_file = $this->determine_plugin_file( $slug );

        switch ( $action ) {
            case 'activate':
                $this->activate_plugin( $plugin_file, $slug );
                break;
            case 'deactivate':
                $this->deactivate_plugin( $plugin_file, $slug );
                break;
            case 'delete':
                $this->delete_plugin( $plugin_file, $slug );
                break;
            case 'view_code':
                $payload = $this->get_plugin_code_preview( $slug );
                \wp_send_json_success( [ 'codePreview' => $payload ] );
                return;
            default:
                \wp_send_json_error( [ 'message' => \__( 'Unsupported action.', 'ai-plugin-builder-studio' ) ], 400 );
        }

        $this->handle_fetch_plugins();
    }

    /**
     * Activate plugin via WordPress API.
     *
     * @param string $plugin_file Plugin file relative path.
     * @param string $slug        Plugin slug.
     *
     * @return void
     */
    protected function activate_plugin( $plugin_file, $slug ) {
        if ( '' === $plugin_file ) {
            \wp_send_json_error( [ 'message' => \__( 'Plugin files missing. Unable to activate.', 'ai-plugin-builder-studio' ) ], 404 );
        }

        $result = \activate_plugin( $plugin_file );

        if ( \is_wp_error( $result ) ) {
            \wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        DB_Handler::update_status( $slug, 'active' );
        $this->logger->log( 'Plugin activated', [ 'plugin' => $plugin_file ] );
    }

    /**
     * Deactivate plugin.
     *
     * @param string $plugin_file Plugin main file.
     * @param string $slug        Plugin slug.
     *
     * @return void
     */
    protected function deactivate_plugin( $plugin_file, $slug ) {
        if ( '' === $plugin_file ) {
            \wp_send_json_error( [ 'message' => \__( 'Plugin files missing. Unable to deactivate.', 'ai-plugin-builder-studio' ) ], 404 );
        }

        \deactivate_plugins( $plugin_file, false, false );
        DB_Handler::update_status( $slug, 'inactive' );
        $this->logger->log( 'Plugin deactivated', [ 'plugin' => $plugin_file ] );
    }

    /**
     * Delete plugin and database entry.
     *
     * @param string $plugin_file Plugin file relative path.
     * @param string $slug        Plugin slug.
     *
     * @return void
     */
    protected function delete_plugin( $plugin_file, $slug ) {
        if ( '' === $plugin_file || ! $this->plugin_exists( $slug ) ) {
            DB_Handler::delete_project( $slug );
            \wp_send_json_success( [ 'message' => \__( 'Plugin already removed.', 'ai-plugin-builder-studio' ) ] );
        }

        \deactivate_plugins( $plugin_file, false, false );
        $result = \delete_plugins( [ $plugin_file ] );

        if ( \is_wp_error( $result ) ) {
            \wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        DB_Handler::delete_project( $slug );
        $this->logger->log( 'Plugin deleted', [ 'plugin' => $plugin_file ] );
    }

    /**
     * Determine plugin file path for slug.
     *
     * @param string $slug Plugin slug.
     *
     * @return string
     */
    protected function determine_plugin_file( $slug ) {
        if ( '' === $slug ) {
            return '';
        }

        $base_dir = \trailingslashit( WP_PLUGIN_DIR ) . $slug;

        if ( ! is_dir( $base_dir ) ) {
            return '';
        }

        \require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins = \get_plugins( '/' . $slug );

        if ( ! empty( $plugins ) ) {
            $files = array_keys( $plugins );
            return $slug . '/' . $files[0];
        }

        $default = $slug . '/' . $slug . '.php';
        if ( file_exists( \trailingslashit( WP_PLUGIN_DIR ) . $default ) ) {
            return $default;
        }

        return '';
    }

    /**
     * Check whether plugin directory exists.
     *
     * @param string $slug Plugin slug.
     *
     * @return bool
     */
    protected function plugin_exists( $slug ) {
        $path = \trailingslashit( WP_PLUGIN_DIR ) . $slug;

        return is_dir( $path );
    }

    /**
     * Retrieve a code preview for the plugin limited to key files.
     *
     * @param string $slug Plugin slug.
     *
     * @return array
     */
    protected function get_plugin_code_preview( $slug ) {
        $base_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug;

        if ( ! is_dir( $base_dir ) ) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $base_dir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info->isFile() ) {
                continue;
            }

            $extension = strtolower( $file_info->getExtension() );
            if ( ! in_array( $extension, [ 'php', 'js', 'css', 'md', 'txt' ], true ) ) {
                continue;
            }

            $relative = ltrim( str_replace( $base_dir, '', $file_info->getPathname() ), '/' );
            $contents = @file_get_contents( $file_info->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

            if ( false === $contents ) {
                $contents = '';
            }

            $preview = function_exists( 'mb_substr' ) ? mb_substr( $contents, 0, 2000 ) : substr( $contents, 0, 2000 );

            $files[] = [
                'path'    => $relative,
                'content' => $preview,
            ];

            if ( count( $files ) >= 10 ) {
                break;
            }
        }

        return $files;
    }
}
