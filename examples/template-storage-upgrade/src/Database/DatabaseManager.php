<?php

namespace MA_CO_AccessControlPro\Database;

use MA_CO_AccessControlPro\Utilities\OptionMutex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/AccessStatusSchema.php';
require_once __DIR__ . '/AccessStatusMigration.php';

/**
 * مدیریت پایگاه داده
 */
class DatabaseManager {

	const DB_VERSION = '2.4.0';

	/**
	 * نام جدول لاگ‌ها
	 *
	 * @var string
	 */
	private $logs_table;

	/**
	 * نام جدول session ها
	 *
	 * @var string
	 */
	private $sessions_table;

	/**
	 * @var string
	 */
	private $shortcode_locks_table;

	/**
	 * نام جدول محدودیت‌ها
	 *
	 * @var string
	 */
	private $restrictions_table;

	/**
	 * Request-local schema inspector.
	 *
	 * @var SchemaInspector|null
	 */
	private $schema_inspector;

	/**
	 * سازنده
	 */
	public function __construct() {
		global $wpdb;
		$this->logs_table            = $wpdb->prefix . 'ma_co_acp_logs';
		$this->sessions_table        = $wpdb->prefix . 'ma_co_acp_sessions';
		$this->shortcode_locks_table = $wpdb->prefix . 'ma_co_acp_shortcode_locks';
		$this->restrictions_table    = $wpdb->prefix . 'ma_co_acp_restrictions';
	}

	public function maybe_upgrade() {
		$installed_version = get_option( 'ma_co_acp_db_version', '0' );

		if ( version_compare( $installed_version, self::DB_VERSION, '>=' ) && (int) get_option( AccessStatusStorage::VERSION_OPTION, 0 ) >= AccessStatusStorage::VERSION && null !== get_option( AccessStatusStorage::PUBLISHED_OPTION, null ) ) {
			update_option( 'ma_co_acp_version', MA_CO_ACP_VERSION );
			return;
		}

		// A host/schema failure must not repeat DDL on every storefront request.
		// The explicit repair action always bypasses this retry backoff.
		if ( get_transient( 'ma_co_acp_database_upgrade_retry' ) ) { return; }
		$result = $this->run_manual_upgrade();
		if ( empty( $result['success'] ) ) {
			set_transient( 'ma_co_acp_database_upgrade_retry', 1, 5 * MINUTE_IN_SECONDS );
		}
		return $result;
	}

    /**
     * ایجاد جداول پایگاه داده
     * اصلاح شده برای پشتیبانی از Log Levels
     */
    public function create_tables( $update_version = true ) {
        global $wpdb;
		if ( $update_version ) { return $this->run_manual_upgrade(); }
		$errors = [];

		$this->schema_inspector = null;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // جدول لاگ‌ها - ستون log_level اضافه شد
        $sql = "CREATE TABLE {$this->logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			log_level VARCHAR(20) NOT NULL DEFAULT 'info',
			event_type VARCHAR(50) NOT NULL,
			component VARCHAR(40) NOT NULL DEFAULT '',
			request_id VARCHAR(36) NOT NULL DEFAULT '',
			flow_id VARCHAR(36) NOT NULL DEFAULT '',
			fingerprint VARCHAR(40) NOT NULL DEFAULT '',
			occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
			reason TEXT NOT NULL,
			message TEXT NULL,
			destination TEXT NULL,
			ip VARCHAR(45) NULL,
			user_agent TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY user_id (user_id),
			KEY log_level (log_level),
			KEY event_type (event_type),
			KEY component_created (component,created_at),
			KEY request_id (request_id),
			KEY flow_created (flow_id,created_at),
			KEY request_fingerprint (request_id,fingerprint),
			KEY created_at (created_at)
		) {$charset_collate};";

		$wpdb->last_error = '';
        dbDelta( $sql );
		if ( $wpdb->last_error ) { $errors[] = $wpdb->last_error; }

        // جدول شورت‌کدها (بدون تغییر نسبت به قبل برای اطمینان از سلامت ساختار)
        $sql_shortcode_locks = "CREATE TABLE {$this->shortcode_locks_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			lock_id VARCHAR(100) NOT NULL,
			who VARCHAR(50) NOT NULL DEFAULT 'everyone',
			restriction_mode VARCHAR(20) NOT NULL DEFAULT 'allow',
			roles TEXT NULL,
			meta_key VARCHAR(255) NULL,
			meta_compare VARCHAR(20) NULL DEFAULT '=',
			meta_value TEXT NULL,
			wc_products TEXT NULL,
			deny_action VARCHAR(50) NOT NULL DEFAULT 'message',
			deny_message TEXT NULL,
			deny_custom_html TEXT NULL,
			sort_order INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY lock_id (lock_id)
		) {$charset_collate};";

		$wpdb->last_error = '';
        dbDelta( $sql_shortcode_locks );
		if ( $wpdb->last_error ) { $errors[] = $wpdb->last_error; }

        // جدول محدودیت‌های اصلی
        $sql_restrictions = "CREATE TABLE {$this->restrictions_table} (
		    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		    post_id BIGINT UNSIGNED NOT NULL,
		    enabled TINYINT(1) NOT NULL DEFAULT 0,
		    data LONGTEXT NOT NULL,
		    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		    updated_at DATETIME NULL,
		    PRIMARY KEY  (id),
		    UNIQUE KEY post_id (post_id),
		    KEY enabled (enabled)
		) {$charset_collate};";

		$wpdb->last_error = '';
        dbDelta( $sql_restrictions );
		if ( $wpdb->last_error ) { $errors[] = $wpdb->last_error; }
		foreach ( AccessStatusSchema::statements() as $statement ) {
			$wpdb->last_error = '';
			dbDelta( $statement );
			if ( $wpdb->last_error ) { $errors[] = $wpdb->last_error; }
		}
		return $errors;
    }

	public function install() {
		return $this->run_manual_upgrade();
	}

	public function run_manual_upgrade() {
		$mutex = new OptionMutex( 'ma_co_acp_database_upgrade_mutex', 120, 1, 0,
			'ma_co_acp_database_busy', __( 'ارتقای دیتابیس در درخواست دیگری در حال اجراست؛ دوباره تلاش کنید.', 'ma-co-access-control-pro' ) );
		$lock = $mutex->acquire();
		if ( is_wp_error( $lock ) ) {
			if ( function_exists( 'do_action' ) ) { do_action( 'ma_co_acp_database_upgrade_result', [ 'success' => false, 'errors' => [ $lock->get_error_message() ] ] ); }
			return [ 'success' => false, 'errors' => [ $lock->get_error_message() ], 'status' => $this->get_schema_status() ];
		}
		try {
			AccessStatusStorage::clear_option_cache( 'ma_co_acp_db_version' );
			$result = $this->run_database_upgrade( get_option( 'ma_co_acp_db_version', '0' ) );
			if ( function_exists( 'do_action' ) ) { do_action( 'ma_co_acp_database_upgrade_result', $result ); }
			return $result;
		} finally { $mutex->release( $lock ); }
	}

	public function get_required_tables() {
		return AccessStatusSchema::tables() + [
			'logs'            => $this->logs_table,
			'shortcode_locks' => $this->shortcode_locks_table,
			'restrictions'    => $this->restrictions_table,
		];
	}

	public function get_required_columns() {
		return AccessStatusSchema::columns() + [
			'logs' => [
				'id',
				'post_id',
				'user_id',
				'log_level',
				'event_type',
				'component',
				'request_id',
				'flow_id',
				'fingerprint',
				'occurrence_count',
				'reason',
				'message',
				'destination',
				'ip',
				'user_agent',
				'created_at',
			],
			'shortcode_locks' => [
				'id',
				'post_id',
				'lock_id',
				'who',
				'restriction_mode',
				'roles',
				'meta_key',
				'meta_compare',
				'meta_value',
				'wc_products',
				'deny_action',
				'deny_message',
				'deny_custom_html',
				'sort_order',
				'created_at',
				'updated_at',
			],
			'restrictions' => [
				'id',
				'post_id',
				'enabled',
				'data',
				'created_at',
				'updated_at',
			],
		];
	}

	public function get_required_indexes() {
		return AccessStatusSchema::indexes() + [
			'logs' => [
				'PRIMARY'    => [ 'columns' => [ 'id' ], 'unique' => true ],
				'post_id'    => [ 'columns' => [ 'post_id' ], 'unique' => false ],
				'user_id'    => [ 'columns' => [ 'user_id' ], 'unique' => false ],
				'log_level'  => [ 'columns' => [ 'log_level' ], 'unique' => false ],
				'event_type' => [ 'columns' => [ 'event_type' ], 'unique' => false ],
				'component_created' => [ 'columns' => [ 'component', 'created_at' ], 'unique' => false ],
				'request_id' => [ 'columns' => [ 'request_id' ], 'unique' => false ],
				'flow_created' => [ 'columns' => [ 'flow_id', 'created_at' ], 'unique' => false ],
				'request_fingerprint' => [ 'columns' => [ 'request_id', 'fingerprint' ], 'unique' => false ],
				'created_at' => [ 'columns' => [ 'created_at' ], 'unique' => false ],
			],
			'shortcode_locks' => [
				'PRIMARY' => [ 'columns' => [ 'id' ], 'unique' => true ],
				'post_id' => [ 'columns' => [ 'post_id' ], 'unique' => false ],
				'lock_id' => [ 'columns' => [ 'lock_id' ], 'unique' => false ],
			],
			'restrictions' => [
				'PRIMARY' => [ 'columns' => [ 'id' ], 'unique' => true ],
				'post_id' => [ 'columns' => [ 'post_id' ], 'unique' => true ],
				'enabled' => [ 'columns' => [ 'enabled' ], 'unique' => false ],
			],
		];
	}

	public function get_schema_status() {
		if ( null === $this->schema_inspector ) {
			$this->schema_inspector = new SchemaInspector();
		}

		$status = $this->schema_inspector->inspect(
			$this->get_required_tables(),
			$this->get_required_columns(),
			$this->get_required_indexes(),
			get_option( 'ma_co_acp_db_version', '0' ),
			self::DB_VERSION,
			MA_CO_ACP_VERSION
		);
		$storage = new AccessStatusStorage();
		$error = null;
		$complete = (int) get_option( AccessStatusStorage::VERSION_OPTION, 0 ) >= AccessStatusStorage::VERSION;
		if ( $storage->is_active() ) {
			$state = $storage->read();
			$history = is_wp_error( $state ) ? $state : $storage->history();
			$error = is_wp_error( $history ) ? $history : null;
		}
		$publication = new \MA_CO_AccessControlPro\Features\Settings\Settings\AccessStatusPublicationService();
		if ( ! $publication::exists() ) { $complete = false; }
		elseif ( ! $error ) { $published = $publication->read(); if ( is_wp_error( $published ) ) { $error = $published; } }
		$previous_error = get_option( AccessStatusStorage::ERROR_OPTION, [] );
		$status['migration'] = [
			'complete' => $complete && ! $error,
			'message' => $error ? $error->get_error_message() : ( $complete ? __( 'انتقال کتابخانه و تاریخچه کامل شده است.', 'ma-co-access-control-pro' ) : ( $previous_error['message'] ?? __( 'انتقال کتابخانه هنوز کامل نشده است.', 'ma-co-access-control-pro' ) ) ),
		];
		$status['is_current'] = $status['is_current'] && $status['migration']['complete'];
		return $status;
	}

	private function run_database_upgrade( $installed_version ) {
		global $wpdb;

		$errors         = [];
		$before_version = (string) $installed_version;
		if ( version_compare( $before_version, self::DB_VERSION, '>' ) ) {
			return [ 'success' => false, 'before_version' => $before_version, 'after_version' => $before_version,
				'target_version' => self::DB_VERSION, 'status' => $this->get_schema_status(),
				'errors' => [ __( 'دیتابیس متعلق به نسخه جدیدتر افزونه است؛ کاهش نسخه ساختار انجام نمی‌شود.', 'ma-co-access-control-pro' ) ] ];
		}
		$storage = new AccessStatusStorage();
		if ( $storage->is_active() ) {
			$before = $this->get_schema_status();
			$missing_data_tables = array_intersect( $before['missing_tables'], array_values( AccessStatusSchema::tables() ) );
			if ( $missing_data_tables ) {
				return [ 'success' => false, 'before_version' => $before_version, 'after_version' => $before_version,
					'target_version' => self::DB_VERSION, 'status' => $before,
					'errors' => [ __( 'جدول داده کتابخانه منتقل‌شده موجود نیست؛ ابتدا نسخه پشتیبان دیتابیس را بازیابی کنید. جدول خالی جایگزین داده نمی‌شود.', 'ma-co-access-control-pro' ) ] ];
			}
		}

		$wpdb->last_error = '';
		$errors = array_merge( $errors, $this->create_tables( false ) );

		if ( ! empty( $wpdb->last_error ) ) {
			$errors[] = $wpdb->last_error;
		}

		// dbDelta already upgrades every legacy column; version publication has one owner.

		$status = $this->get_schema_status();

		if ( ! empty( $status['missing_tables'] ) ) {
			$errors[] = sprintf(
				'Missing database tables: %s',
				implode( ', ', array_map( 'sanitize_text_field', $status['missing_tables'] ) )
			);
		}

		if ( ! empty( $status['missing_columns'] ) ) {
			$errors[] = sprintf(
				'Missing database columns: %s',
				implode( ', ', array_map( 'sanitize_text_field', $status['missing_columns'] ) )
			);
		}

		if ( ! empty( $status['missing_indexes'] ) ) {
			$errors[] = sprintf(
				'Missing database indexes: %s',
				implode( ', ', array_map( 'sanitize_text_field', $status['missing_indexes'] ) )
			);
		}

		if ( empty( $errors ) ) {
			$migration = ( new AccessStatusMigration() )->run();
			if ( is_wp_error( $migration ) ) { $errors[] = $migration->get_error_message(); }
		}
		if ( empty( $errors ) ) {
			$migration = ( new \MA_CO_AccessControlPro\Features\Settings\Settings\AccessStatusPublicationService() )->migrate();
			if ( is_wp_error( $migration ) ) { $errors[] = $migration->get_error_message(); }
		}
		if ( empty( $errors ) ) {
			update_option( 'ma_co_acp_db_version', self::DB_VERSION );
			AccessStatusStorage::clear_option_cache( 'ma_co_acp_db_version' );
			if ( get_option( 'ma_co_acp_db_version', '0' ) !== self::DB_VERSION ) {
				$errors[] = __( 'ثبت نسخه دیتابیس کامل نشد؛ ارتقا را دوباره اجرا کنید.', 'ma-co-access-control-pro' );
			}
			update_option( 'ma_co_acp_version', MA_CO_ACP_VERSION );
			$this->schema_inspector = null;
		}

		$status = $this->get_schema_status();
		if ( empty( $errors ) ) { delete_transient( 'ma_co_acp_database_upgrade_retry' ); }
		return [
			'success'        => empty( $errors ),
			'before_version' => $before_version,
			'after_version'  => get_option( 'ma_co_acp_db_version', '0' ),
			'target_version' => self::DB_VERSION,
			'errors'         => array_values( array_unique( array_filter( $errors ) ) ),
			'status'         => $status,
		];
	}


	/**
	 * دریافت نام جدول لاگ‌ها
	 *
	 * @return string
	 */
	public function get_logs_table() {
		return $this->logs_table;
	}

	/**
	 * دریافت نام جدول session ها
	 *
	 * @return string
	 */
	public function get_sessions_table() {
		return $this->sessions_table;
	}

	/**
	 * Get the shortcode locks table name.
	 *
	 * @return string
	 */
	public function get_shortcode_locks_table(): string {
		return $this->shortcode_locks_table;
	}

	/**
	 * دریافت نام جدول محدودیت‌ها
	 *
	 * @return string
	 */
	public function get_restrictions_table(): string {
		return $this->restrictions_table;
	}

	/**
	 * حذف جداول (برای uninstall)
	 */
	public function drop_tables() {
		global $wpdb;

		$this->schema_inspector = null;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->logs_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->sessions_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->shortcode_locks_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$this->restrictions_table}" );
		foreach ( AccessStatusSchema::tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
		}
		foreach ( [ AccessStatusStorage::META_OPTION, AccessStatusStorage::PUBLISHED_OPTION, 'ma_co_acp_access_publication_backup', AccessStatusStorage::VERSION_OPTION, AccessStatusStorage::BACKUP_OPTION, AccessStatusStorage::ERROR_OPTION ] as $option ) { delete_option( $option ); }
		delete_option( 'ma_co_acp_db_version' );
		delete_option( 'ma_co_acp_version' );
	}
}
