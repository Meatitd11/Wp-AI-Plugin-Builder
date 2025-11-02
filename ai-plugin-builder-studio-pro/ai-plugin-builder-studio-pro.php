<?php
/**
 * Plugin Name:       AI Plugin Builder Studio Pro
 * Plugin URI:        https://innovativetechdev.com/
 * Description:       Build professional WordPress plugins automatically with AI driven workflows directly inside your dashboard.
 * Version:           1.0.0
 * Author:            Innovative Tech Dev
 * Author URI:        https://innovativetechdev.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-plugin-builder-studio
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'AI_PBS_PLUGIN_VERSION', '1.0.0' );
define( 'AI_PBS_PLUGIN_FILE', __FILE__ );
define( 'AI_PBS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'AI_PBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_PBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Basic PSR-4 autoloader for plugin classes.
 *
 * @param string $class Class name to load.
 * @return void
 */
spl_autoload_register( static function ( $class ) {
    $prefix = 'AIPluginBuilderStudio\\';

    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }

    $relative_class = substr( $class, strlen( $prefix ) );
    $segments       = explode( '\\', $relative_class );
    $base_segment   = strtolower( array_shift( $segments ) );

    $base_dir = AI_PBS_PLUGIN_DIR . 'includes/';

    if ( 'admin' === $base_segment ) {
        $base_dir = AI_PBS_PLUGIN_DIR . 'admin/';
    } elseif ( 'includes' !== $base_segment ) {
        // Allow additional top-level namespaces kept under includes/<segment>.
        array_unshift( $segments, $base_segment );
        $base_segment = '';
    }

    $path_segments = $segments;
    $class_name    = array_pop( $path_segments );
    $file_name     = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

    $sub_path = '';

    foreach ( $path_segments as $segment ) {
        $sub_path .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
    }

    $path = $base_dir . $sub_path . $file_name;

    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

require_once AI_PBS_PLUGIN_DIR . 'includes/helpers.php';


/**
 * Run plugin bootstrap after plugins are loaded to ensure WordPress is ready.
 *
 * @return void
 */
function ai_pbs_bootstrap() {
    $plugin = AIPluginBuilderStudio\Plugin::get_instance();
    $plugin->init();
}

add_action( 'plugins_loaded', 'ai_pbs_bootstrap' );


register_activation_hook( __FILE__, [ 'AIPluginBuilderStudio\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'AIPluginBuilderStudio\\Plugin', 'deactivate' ] );
