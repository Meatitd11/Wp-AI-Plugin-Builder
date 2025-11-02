<?php
/**
 * Manage generated plugins view.
 *
 * @package AIPluginBuilderStudioPro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( isset( $args ) && is_array( $args ) ) {
    foreach ( $args as $key => $value ) {
        if ( ! isset( ${$key} ) ) {
            ${$key} = $value;
        }
    }
}

?>
<section class="ai-pbs-panel">
    <header class="ai-pbs-panel__header">
        <h2><?php esc_html_e( 'Manage AI-Built Plugins', 'ai-plugin-builder-studio' ); ?></h2>
        <p><?php esc_html_e( 'Review every plugin you generated, toggle activation, inspect code, or safely remove it.', 'ai-plugin-builder-studio' ); ?></p>
    </header>

    <div id="ai-pbs-manage-root" class="ai-pbs-react-root" aria-live="polite"></div>
</section>
