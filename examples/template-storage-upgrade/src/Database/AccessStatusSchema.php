<?php
/**
 * SQL contract for the access-status library.
 *
 * @package MA_CO_AccessControlPro\Database
 */
namespace MA_CO_AccessControlPro\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessStatusSchema {

	/** @return array<string,string> Current site's table names. */
	public static function tables() {
		global $wpdb;
		return [
			'access_status_templates' => $wpdb->prefix . 'ma_co_acp_access_status_templates',
			'access_status_template_history' => $wpdb->prefix . 'ma_co_acp_access_status_template_history',
		];
	}

	/** @return string[] dbDelta-compatible statements, with no engine requirement. */
	public static function statements() {
		global $wpdb;
		$tables = self::tables();
		$collate = $wpdb->get_charset_collate();
		return [
			"CREATE TABLE {$tables['access_status_templates']} (
				generation varchar(64) NOT NULL,
				template_id varchar(100) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				archive_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
				schema_version int(10) unsigned NOT NULL,
				sort_order int(10) unsigned NOT NULL DEFAULT 0,
				data longtext NOT NULL,
				created_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (generation,template_id),
				KEY archive_status (generation,status,archive_expires_at),
				KEY created_at (created_at)
			) {$collate};",
			"CREATE TABLE {$tables['access_status_template_history']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				history_set varchar(64) NOT NULL,
				snapshot_id varchar(100) NOT NULL,
				created_at bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reason varchar(64) NOT NULL,
				payload longtext NOT NULL,
				payload_hash varchar(64) NOT NULL,
				payload_bytes bigint(20) unsigned NOT NULL,
				stored_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY snapshot (history_set,snapshot_id),
				KEY history_order (history_set,id)
			) {$collate};",
		];
	}

	/** @return array<string,string[]> Required columns for health inspection. */
	public static function columns() {
		return [
			'access_status_templates' => [ 'generation', 'template_id', 'status', 'archive_expires_at', 'schema_version', 'sort_order', 'data', 'created_at' ],
			'access_status_template_history' => [ 'id', 'history_set', 'snapshot_id', 'created_at', 'user_id', 'reason', 'payload', 'payload_hash', 'payload_bytes', 'stored_at' ],
		];
	}

	/** @return array Required index definitions for health inspection. */
	public static function indexes() {
		return [
			'access_status_templates' => [
				'PRIMARY' => [ 'columns' => [ 'generation', 'template_id' ], 'unique' => true ],
				'archive_status' => [ 'columns' => [ 'generation', 'status', 'archive_expires_at' ], 'unique' => false ],
				'created_at' => [ 'columns' => [ 'created_at' ], 'unique' => false ],
			],
			'access_status_template_history' => [
				'PRIMARY' => [ 'columns' => [ 'id' ], 'unique' => true ],
				'snapshot' => [ 'columns' => [ 'history_set', 'snapshot_id' ], 'unique' => true ],
				'history_order' => [ 'columns' => [ 'history_set', 'id' ], 'unique' => false ],
			],
		];
	}
}
