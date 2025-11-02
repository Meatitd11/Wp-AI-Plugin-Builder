<?php
/**
 * Connect AI model view.
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
        <h2><?php esc_html_e( 'Connect Your AI Model', 'ai-plugin-builder-studio' ); ?></h2>
        <p><?php esc_html_e( 'Securely add API keys and choose the AI engine that will architect your plugins.', 'ai-plugin-builder-studio' ); ?></p>
    </header>

    <div id="ai-pbs-connect-root" class="ai-pbs-react-root" aria-live="polite"></div>
</section>
