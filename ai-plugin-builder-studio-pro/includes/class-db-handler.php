<?php
/**
 * Database handler for AI Plugin Builder Studio Pro.
 *
 * @package AIPluginBuilderStudioPro
 */

namespace AIPluginBuilderStudio\Includes;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DB_Handler
 */
class DB_Handler {

    /**
     * Table suffix for storing generated plugins metadata.
     */
    const TABLE_SUFFIX = 'ai_plugin_builder_projects';

    /**
     * Create necessary tables via dbDelta.
     *
     * @return void
     */
    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name      = static::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_name VARCHAR(191) NOT NULL,
            plugin_slug VARCHAR(191) NOT NULL,
            plugin_version VARCHAR(50) NOT NULL DEFAULT '1.0.0',
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'inactive',
            ai_model VARCHAR(100) DEFAULT '' NOT NULL,
            files_manifest LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY plugin_slug (plugin_slug)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Retrieve full table name including WordPress prefix.
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;

        return $wpdb->prefix . static::TABLE_SUFFIX;
    }

    /**
     * Insert or update a project entry.
     *
     * @param array $data Project data.
     *
     * @return int|false Project ID or false on failure.
     */
    public static function upsert_project( array $data ) {
        global $wpdb;

        $table = static::get_table_name();
        $now   = current_time( 'mysql', 1 );

        $defaults = [
            'plugin_name'     => '',
            'plugin_slug'     => '',
            'plugin_version'  => '1.0.0',
            'description'     => '',
            'status'          => 'inactive',
            'ai_model'        => '',
            'files_manifest'  => '',
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $data = wp_parse_args( $data, $defaults );

        $existing = static::get_project_by_slug( $data['plugin_slug'] );

        if ( $existing ) {
            $data['updated_at'] = $now;
            $updated            = $wpdb->update(
                $table,
                [
                    'plugin_name'    => $data['plugin_name'],
                    'plugin_version' => $data['plugin_version'],
                    'description'    => $data['description'],
                    'status'         => $data['status'],
                    'ai_model'       => $data['ai_model'],
                    'files_manifest' => $data['files_manifest'],
                    'updated_at'     => $data['updated_at'],
                ],
                [ 'plugin_slug' => $data['plugin_slug'] ],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                ],
                [ '%s' ]
            );

            return false !== $updated ? (int) $existing->id : false;
        }

        $inserted = $wpdb->insert(
            $table,
            $data,
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get a project by slug.
     *
     * @param string $slug Plugin slug.
     *
     * @return object|null
     */
    public static function get_project_by_slug( $slug ) {
        global $wpdb;

        $table = static::get_table_name();

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE plugin_slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Retrieve all projects.
     *
     * @return array
     */
    public static function get_projects() {
        global $wpdb;

        $table = static::get_table_name();

        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Update project status by slug.
     *
     * @param string $slug   Plugin slug.
     * @param string $status Status string.
     *
     * @return bool
     */
    public static function update_status( $slug, $status ) {
        global $wpdb;

        $table = static::get_table_name();

        $updated = $wpdb->update(
            $table,
            [
                'status'     => $status,
                'updated_at' => current_time( 'mysql', 1 ),
            ],
            [ 'plugin_slug' => $slug ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        return false !== $updated;
    }

    /**
     * Delete a project record.
     *
     * @param string $slug Plugin slug to delete.
     *
     * @return bool
     */
    public static function delete_project( $slug ) {
        global $wpdb;

        $table = static::get_table_name();

        $deleted = $wpdb->delete( $table, [ 'plugin_slug' => $slug ], [ '%s' ] );

        return false !== $deleted;
    }
}
