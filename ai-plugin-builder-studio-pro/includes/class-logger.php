<?php
/**
 * Logger utility for AI Plugin Builder Studio Pro.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lightweight file logger writing into uploads directory when enabled.
 */
class Logger {

    /**
     * Whether debug logging is enabled.
     *
     * @var bool
     */
    protected $enabled;

    /**
     * Logger constructor.
     */
    public function __construct() {
        $this->enabled = (bool) (int) \get_option( 'ai_pbs_debug_logs_enabled', 0 );
    }

    /**
     * Persist a debug entry if logging is enabled.
     *
     * @param string $message Log message.
     * @param array  $context Additional data.
     *
     * @return void
     */
    public function log( $message, array $context = [] ) {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $directory = \ai_pbs_get_upload_log_dir();

        if ( empty( $directory ) ) {
            return;
        }

        $entry = [
            'time'    => \current_time( 'mysql' ),
            'message' => $message,
            'context' => $context,
        ];

        $line = \wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        if ( false === $line ) {
            $line = $message;
        }

        $file = \trailingslashit( $directory ) . 'ai-plugin-builder-' . \gmdate( 'Y-m-d' ) . '.log';

        \file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
    }

    /**
     * Whether logging is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return (bool) $this->enabled;
    }

    /**
     * Flush resources.
     *
     * @return void
     */
    public function flush() {
        // Intentionally left empty for compatibility.
    }
}
