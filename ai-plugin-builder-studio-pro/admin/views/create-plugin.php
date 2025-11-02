<?php
/**
 * Create new plugin wizard view.
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
        <h2><?php esc_html_e( 'Create a New Plugin', 'ai-plugin-builder-studio' ); ?></h2>
        <p><?php esc_html_e( 'Describe your idea and let the AI architect, code, and launch a fully functional WordPress plugin.', 'ai-plugin-builder-studio' ); ?></p>
    </header>

    <div id="ai-pbs-create-root" class="ai-pbs-react-root" aria-live="polite"></div>
</section>
