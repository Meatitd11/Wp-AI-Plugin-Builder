<?php
/**
 * Help and documentation view.
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
<section class="ai-pbs-panel ai-pbs-panel--help">
    <header class="ai-pbs-panel__header">
        <h2><?php esc_html_e( 'Help & Documentation', 'ai-plugin-builder-studio' ); ?></h2>
        <p><?php esc_html_e( 'New to AI-first development? These guided steps will help you go from prompt to production plugin in minutes.', 'ai-plugin-builder-studio' ); ?></p>
    </header>

    <div class="ai-pbs-help-grid">
        <article class="ai-pbs-help-card">
            <h3><?php esc_html_e( '1. Connect an AI provider', 'ai-plugin-builder-studio' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Choose a provider such as Cursor, OpenAI GPT-4 / GPT-5, Anthropic Sonnet, or Google Gemini.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Paste the API key securely. Keys are encrypted using WordPress salts before storage.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Optional: add a custom endpoint if your provider requires a bespoke URL.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Click connect to verify and enable the model for future generations.', 'ai-plugin-builder-studio' ); ?></li>
            </ul>
        </article>

        <article class="ai-pbs-help-card">
            <h3><?php esc_html_e( '2. Describe your plugin', 'ai-plugin-builder-studio' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Provide a human-friendly plugin name and a short description for your team.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Use natural language to explain what the plugin should achieve.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Respond to AI follow-up questions to clarify features such as custom post types, settings pages, REST APIs, or shortcodes.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Review the summary and confirm when you are ready to generate.', 'ai-plugin-builder-studio' ); ?></li>
            </ul>
        </article>

        <article class="ai-pbs-help-card">
            <h3><?php esc_html_e( '3. Manage generated plugins', 'ai-plugin-builder-studio' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Activate or deactivate plugins directly without leaving the studio.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Inspect generated source code safely with the built-in viewer.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Delete plugins you no longer require?files and database records are removed for you.', 'ai-plugin-builder-studio' ); ?></li>
            </ul>
        </article>

        <article class="ai-pbs-help-card">
            <h3><?php esc_html_e( 'Troubleshooting & tips', 'ai-plugin-builder-studio' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Enable debug logs in Settings to capture AI prompts and responses for auditing.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'If filesystem permissions block generation, ensure the server allows direct writes to the plugins directory.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Use clear, goal-focused prompts?state the audience, key workflows, and success criteria.', 'ai-plugin-builder-studio' ); ?></li>
                <li><?php esc_html_e( 'Need custom integrations? Enable live documentation fetching so the AI can pull current API specs.', 'ai-plugin-builder-studio' ); ?></li>
            </ul>
        </article>
    </div>

    <div id="ai-pbs-help-root" class="ai-pbs-react-root" aria-live="polite"></div>
</section>
