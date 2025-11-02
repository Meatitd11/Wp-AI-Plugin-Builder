<?php
/**
 * Shared header and navigation for AI Plugin Builder Studio Pro admin pages.
 *
 * @package AIPluginBuilderStudioPro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Support WordPress load_template argument passing.
if ( isset( $args ) && is_array( $args ) ) {
    foreach ( $args as $key => $value ) {
        if ( ! isset( ${$key} ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
            ${$key} = $value;
        }
    }
}

$tabs       = isset( $tabs ) && is_array( $tabs ) ? $tabs : [];
$currentTab = isset( $currentTab ) ? sanitize_key( $currentTab ) : 'connect';

?>
<div class="ai-pbs-wrap">
    <h1 class="ai-pbs-title"><?php esc_html_e( 'AI Plugin Builder Studio Pro', 'ai-plugin-builder-studio' ); ?></h1>
    <p class="ai-pbs-subtitle"><?php esc_html_e( 'Let AI architect, develop, and launch production-ready WordPress plugins without leaving your dashboard.', 'ai-plugin-builder-studio' ); ?></p>

    <?php if ( ! empty( $tabs ) ) : ?>
        <nav class="ai-pbs-tab-nav" aria-label="<?php esc_attr_e( 'AI Plugin Builder navigation', 'ai-plugin-builder-studio' ); ?>">
            <?php foreach ( $tabs as $tab_key => $tab ) :
                $url   = add_query_arg(
                    [
                        'page' => 'ai-plugin-builder-studio-pro',
                        'tab'  => $tab_key,
                    ],
                    admin_url( 'admin.php' )
                );
                $is_active = $currentTab === $tab_key ? 'is-active' : '';
                ?>
                <a class="ai-pbs-tab <?php echo esc_attr( $is_active ); ?>" href="<?php echo esc_url( $url ); ?>">
                    <?php echo esc_html( $tab['title'] ); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>
