<?php
/**
 * Settings view.
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
        <h2><?php esc_html_e( 'Studio Settings', 'ai-plugin-builder-studio' ); ?></h2>
        <p><?php esc_html_e( 'Configure default AI providers, logging preferences, and advanced developer options.', 'ai-plugin-builder-studio' ); ?></p>
    </header>

    <div id="ai-pbs-settings-root" class="ai-pbs-react-root" aria-live="polite"></div>
</section>
