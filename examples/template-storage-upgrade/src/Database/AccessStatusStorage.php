<?php
/**
 * Verified, copy-on-write persistence for templates and recoverable history.
 *
 * @package MA_CO_AccessControlPro\Database
 */
namespace MA_CO_AccessControlPro\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/AccessStatusSchema.php';

final class AccessStatusStorage {

	const META_OPTION = 'ma_co_acp_access_status_library';
	const VERSION_OPTION = 'ma_co_acp_template_storage_version';
	const BACKUP_OPTION = 'ma_co_acp_template_storage_backup';
	const ERROR_OPTION = 'ma_co_acp_template_storage_error';
	const VERSION = 1;
	const PUBLISHED_OPTION = 'ma_co_acp_access_status_published';
	private $manifest_option;

	public function __construct( $published = false ) {
		$this->manifest_option = $published ? self::PUBLISHED_OPTION : self::META_OPTION;
	}
	/** @var array<string,array> Immutable generations cached within this request. */
	private static $read_cache = [];

	/** @return bool Any cutover marker prevents a silent fallback to the frozen options. */
	public function is_active() {
		return null !== get_option( $this->manifest_option, null )
			|| (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION
			|| version_compare( (string) get_option( 'ma_co_acp_db_version', '0' ), '2.1.0', '>=' );
	}

	/** Refresh after waiting for a writer; never check a conflict against cached options. */
	public function refresh() {
		foreach ( [ self::META_OPTION, self::PUBLISHED_OPTION, self::VERSION_OPTION, 'ma_co_acp_db_version', 'ma_co_acp_access_status_templates', 'ma_co_acp_access_status_template_history' ] as $key ) {
			self::clear_option_cache( $key );
		}
		self::$read_cache = [];
	}

	/** @return array|\WP_Error */
	public function read() {
		$meta = get_option( $this->manifest_option, null );
		if ( ! $this->valid_meta( $meta ) ) { return self::error( 'metadata', __( 'اطلاعات مرجع کتابخانه در دسترس نیست؛ سلامت دیتابیس را بررسی کنید.', 'ma-co-access-control-pro' ) ); }
		$key = $this->cache_key( $meta );
		if ( isset( self::$read_cache[ $key ] ) ) { return self::$read_cache[ $key ]; }
		$result = $this->read_generation( $meta );
		if ( ! is_wp_error( $result ) ) { self::$read_cache[ $key ] = $result; }
		return $result;
	}

	/**
	 * Stage immutable rows, verify them, then atomically publish their options manifest.
	 *
	 * @param array      $stored Compact, sanitized library state.
	 * @param array|null $migration_history Null on ordinary saves; raw snapshots on first migration.
	 * @return true|\WP_Error
	 */
	public function write( array $stored, $migration_history = null ) {
		global $wpdb;
		// read_generation appends templates after metadata; preserve that hash order.
		$templates = $stored['templates'];
		unset( $stored['templates'] );
		$stored['templates'] = $templates;
		$old = get_option( $this->manifest_option, null );
		if ( null === $migration_history && ! $this->valid_meta( $old ) ) {
			return self::error( 'metadata', __( 'ذخیره متوقف شد؛ اطلاعات مرجع کتابخانه معتبر نیست.', 'ma-co-access-control-pro' ) );
		}
		if ( null !== $migration_history && null !== $old ) {
			return self::error( 'conflict', __( 'کتابخانه قبلاً منتقل شده است؛ وضعیت تازه را دریافت کنید.', 'ma-co-access-control-pro' ) );
		}
		$generation = str_replace( '-', '', wp_generate_uuid4() );
		$meta = $stored;
		unset( $meta['templates'] );
		$meta['_storage'] = [
			'version' => self::VERSION,
			'generation' => $generation,
			'history_set' => null !== $migration_history ? $generation : $old['_storage']['history_set'],
			'count' => count( $stored['templates'] ),
			'hash' => self::hash( $stored ),
			'published_at' => time(),
		];
		if ( ! $meta['_storage']['hash'] ) { return self::error( 'encode', __( 'داده کتابخانه قابل تبدیل نیست.', 'ma-co-access-control-pro' ) ); }
		$table = AccessStatusSchema::tables()['access_status_templates'];
		$order = 0;
		foreach ( $stored['templates'] as $id => $record ) {
			$json = wp_json_encode( $record );
			if ( false === $json || false === $wpdb->insert( $table, [
				'generation' => $generation,
				'template_id' => (string) $id,
				'status' => $record['status'] ?? 'active',
				'archive_expires_at' => absint( $record['archive_expires_at'] ?? 0 ),
				'schema_version' => absint( $stored['schema_version'] ),
				'sort_order' => $order++,
				'data' => $json,
				'created_at' => time(),
			], [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d' ] ) ) {
				return self::error( 'write', __( 'ذخیره قالب کامل نشد؛ نسخه قبلی همچنان مرجع است.', 'ma-co-access-control-pro' ) );
			}
		}
		$verified = $this->read_generation( $meta );
		if ( is_wp_error( $verified ) ) { return $verified; }
		if ( null !== $migration_history ) {
			foreach ( array_reverse( $migration_history ) as $snapshot ) {
				$result = $this->insert_snapshot( $snapshot, $generation );
				if ( is_wp_error( $result ) ) { return $result; }
			}
			$history = $this->read_history_set( $generation );
			if ( is_wp_error( $history ) || self::hash( $history ) !== self::hash( array_values( $migration_history ) ) ) {
				return self::error( 'history_verify', __( 'تأیید انتقال تاریخچه ناموفق بود؛ داده قبلی حفظ شد.', 'ma-co-access-control-pro' ) );
			}
		}
		// A single compare-and-swap publishes rows and shared settings together.
		// No transaction across wp_options is required, including on MyISAM hosts.
		if ( null === $old ) {
			$published = add_option( $this->manifest_option, $meta, '', 'no' );
		} else {
			$published = 1 === $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $meta ), $this->manifest_option, maybe_serialize( $old )
			) );
		}
		self::clear_option_cache( $this->manifest_option );
		if ( ! $published ) { return self::error( 'conflict', __( 'انتشار نسخه جدید انجام نشد؛ نسخه قبلی حفظ شد. دوباره تلاش کنید.', 'ma-co-access-control-pro' ) ); }
		self::$read_cache = [];
		if ( get_option( $this->manifest_option, null ) !== $meta ) {
			return self::error( 'publication_verify', __( 'مرجع نسخه ذخیره‌شده تأیید نشد؛ وضعیت تازه کتابخانه را دریافت کنید.', 'ma-co-access-control-pro' ) );
		}
		return true;
	}

	/** @return array|\WP_Error */
	private function read_generation( array $meta ) {
		global $wpdb;
		$table = AccessStatusSchema::tables()['access_status_templates'];
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT template_id, data FROM {$table} WHERE generation = %s ORDER BY sort_order ASC",
			$meta['_storage']['generation']
		), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) !== (int) $meta['_storage']['count'] ) {
			return self::error( 'read', __( 'خواندن قالب‌ها کامل نشد؛ داده قدیمی یا پیش‌فرض جایگزین نمی‌شود.', 'ma-co-access-control-pro' ) );
		}
		$stored = $meta;
		unset( $stored['_storage'] );
		$stored['templates'] = [];
		foreach ( $rows as $row ) {
			$record = json_decode( $row['data'], true );
			if ( ! is_array( $record ) || JSON_ERROR_NONE !== json_last_error() || ( $record['id'] ?? null ) !== $row['template_id'] ) {
				return self::error( 'corrupt', __( 'یک رکورد قالب نامعتبر است؛ بازیابی دیتابیس لازم است.', 'ma-co-access-control-pro' ) );
			}
			$stored['templates'][ $row['template_id'] ] = $record;
		}
		if ( ! hash_equals( $meta['_storage']['hash'], self::hash( $stored ) ) ) {
			return self::error( 'checksum', __( 'داده قالب‌ها با نسخه ثبت‌شده یکسان نیست؛ ذخیره متوقف شد.', 'ma-co-access-control-pro' ) );
		}
		return $stored;
	}

	/** @return array|\WP_Error */
	public function history() {
		$meta = get_option( $this->manifest_option, null );
		if ( ! $this->valid_meta( $meta ) ) { return self::error( 'metadata', __( 'مرجع تاریخچه در دسترس نیست.', 'ma-co-access-control-pro' ) ); }
		return $this->read_history_set( $meta['_storage']['history_set'] );
	}

	/** Delete one snapshot from the active history set. @return true|\WP_Error */
	public function delete_snapshot( $snapshot_id ) {
		global $wpdb;
		$meta = get_option( $this->manifest_option, null );
		if ( ! $this->valid_meta( $meta ) ) { return self::error( 'metadata', __( 'مرجع تاریخچه در دسترس نیست.', 'ma-co-access-control-pro' ) ); }
		$snapshot_id = sanitize_text_field( (string) $snapshot_id );
		if ( '' === $snapshot_id ) { return self::error( 'history_delete', __( 'شناسه نسخه بازیابی معتبر نیست.', 'ma-co-access-control-pro' ) ); }
		$table = AccessStatusSchema::tables()['access_status_template_history'];
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE history_set = %s AND snapshot_id = %s", $meta['_storage']['history_set'], $snapshot_id ) );
		return false === $result ? self::error( 'history_delete', __( 'حذف نسخه بازیابی از تاریخچه انجام نشد.', 'ma-co-access-control-pro' ) ) : true;
	}

	/** Clear every snapshot from the active history set. @return true|\WP_Error */
	public function clear_history() {
		global $wpdb;
		$meta = get_option( $this->manifest_option, null );
		if ( ! $this->valid_meta( $meta ) ) { return self::error( 'metadata', __( 'مرجع تاریخچه در دسترس نیست.', 'ma-co-access-control-pro' ) ); }
		$table = AccessStatusSchema::tables()['access_status_template_history'];
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE history_set = %s", $meta['_storage']['history_set'] ) );
		return false === $result ? self::error( 'history_clear', __( 'پاک‌کردن نسخه‌های بازیابی انجام نشد.', 'ma-co-access-control-pro' ) ) : true;
	}

	/** Remove snapshots older than a Unix timestamp. @return int|\WP_Error */
	public function prune_snapshots_before( $cutoff ) {
		global $wpdb;
		$meta = get_option( $this->manifest_option, null );
		if ( ! $this->valid_meta( $meta ) ) { return self::error( 'metadata', __( 'مرجع تاریخچه در دسترس نیست.', 'ma-co-access-control-pro' ) ); }
		$table = AccessStatusSchema::tables()['access_status_template_history'];
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE history_set = %s AND created_at > 0 AND created_at <= %d", $meta['_storage']['history_set'], absint( $cutoff ) ) );
		return false === $result ? self::error( 'history_prune', __( 'پاک‌سازی نسخه‌های بازیابی قدیمی انجام نشد.', 'ma-co-access-control-pro' ) ) : (int) $result;
	}

	/** @return array|\WP_Error Raw snapshots, newest insertion first. */
	public function read_history_set( $set ) {
		global $wpdb;
		$table = AccessStatusSchema::tables()['access_status_template_history'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT snapshot_id, payload, payload_hash FROM {$table} WHERE history_set = %s ORDER BY id DESC", $set ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) { return self::error( 'history_read', __( 'خواندن تاریخچه ناموفق بود.', 'ma-co-access-control-pro' ) ); }
		$result = [];
		foreach ( $rows as $row ) {
			$snapshot = json_decode( $row['payload'], true );
			if ( ! is_array( $snapshot ) || JSON_ERROR_NONE !== json_last_error() || ( $snapshot['id'] ?? null ) !== $row['snapshot_id'] || ! is_array( $snapshot['state'] ?? null ) || ! hash_equals( $row['payload_hash'], hash( 'sha256', $row['payload'] ) ) ) {
				return self::error( 'history_corrupt', __( 'یک نسخه تاریخچه نامعتبر است؛ عملیات متوقف شد.', 'ma-co-access-control-pro' ) );
			}
			$result[] = $snapshot;
		}
		return $result;
	}

	/**
	 * Called under the studio mutex; preserve the existing history size/count policy.
	 *
	 * @param array $snapshot Raw recoverable state and historical metadata.
	 * @param int   $count_limit Maximum retained snapshots.
	 * @param int   $byte_limit Maximum encoded size of the retained collection.
	 * @return true|\WP_Error
	 */
	public function push_snapshot( array $snapshot, $count_limit, $byte_limit ) {
		global $wpdb;
		$history = $this->history();
		if ( is_wp_error( $history ) ) { return $history; }
		array_unshift( $history, $snapshot );
		$history = array_slice( $history, 0, $count_limit );
		while ( count( $history ) > 1 && strlen( (string) wp_json_encode( $history ) ) > $byte_limit ) { array_pop( $history ); }
		$json = wp_json_encode( $history );
		if ( false === $json || strlen( $json ) > $byte_limit ) { return self::error( 'history_size', __( 'حجم نسخه بازیابی از حد مجاز بیشتر است؛ تغییرات ذخیره نشد.', 'ma-co-access-control-pro' ) ); }
		$meta = get_option( $this->manifest_option );
		$set = $meta['_storage']['history_set'];
		$inserted = $this->insert_snapshot( $snapshot, $set );
		if ( is_wp_error( $inserted ) ) { return $inserted; }
		$table = AccessStatusSchema::tables()['access_status_template_history'];
		$ids = array_column( $history, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE history_set = %s AND snapshot_id NOT IN ({$placeholders})",
			array_merge( [ $set ], $ids )
		) );
		if ( false === $deleted ) { return self::error( 'history_prune', __( 'نگهداری تاریخچه کامل نشد؛ تغییر کتابخانه متوقف شد.', 'ma-co-access-control-pro' ) ); }
		return true;
	}

	/** @return true|\WP_Error Insert and verify the raw payload before allowing a state mutation. */
	public function insert_snapshot( array $snapshot, $set ) {
		global $wpdb;
		$table = AccessStatusSchema::tables()['access_status_template_history'];
		$json = wp_json_encode( $snapshot );
		if ( false === $json || false === $wpdb->insert( $table, [
			'history_set' => $set,
			'snapshot_id' => $snapshot['id'],
			'created_at' => absint( $snapshot['created_at'] ?? 0 ),
			'user_id' => absint( $snapshot['user_id'] ?? 0 ),
			'reason' => (string) ( $snapshot['reason'] ?? 'save' ),
			'payload' => $json,
			'payload_hash' => hash( 'sha256', (string) $json ),
			'payload_bytes' => strlen( (string) $json ),
			'stored_at' => time(),
		], [ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d' ] ) ) {
			return self::error( 'history_write', __( 'نسخه بازیابی ذخیره نشد؛ تغییرات کتابخانه اعمال نشد.', 'ma-co-access-control-pro' ) );
		}
		$actual = $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$table} WHERE history_set = %s AND snapshot_id = %s", $set, $snapshot['id'] ) );
		if ( $wpdb->last_error || $actual !== $json ) { return self::error( 'history_verify', __( 'درستی نسخه بازیابی پس از ذخیره تأیید نشد؛ تغییرات اعمال نشد.', 'ma-co-access-control-pro' ) ); }
		return true;
	}

	/** Under the studio mutex: retire old generations after an in-flight-reader grace period. */
	public function cleanup_retired_generations() {
		global $wpdb;
		// Both channels own immutable generations and independent history sets.
		$other = get_option( self::PUBLISHED_OPTION === $this->manifest_option ? self::META_OPTION : self::PUBLISHED_OPTION, null );
		if ( null !== $other && ! $this->valid_meta( $other ) ) { return self::error( 'metadata', __( 'مرجع انتشار نامعتبر است؛ پاکسازی متوقف شد.', 'ma-co-access-control-pro' ) ); }
		$meta = get_option( $this->manifest_option, null );
		if ( ! $this->valid_meta( $meta ) ) { return self::error( 'metadata', __( 'مرجع کتابخانه برای نگهداری معتبر نیست.', 'ma-co-access-control-pro' ) ); }
		$table = AccessStatusSchema::tables()['access_status_templates'];
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE generation NOT IN (%s,%s) AND created_at < %d", $meta['_storage']['generation'], $other['_storage']['generation'] ?? '', time() - DAY_IN_SECONDS ) );
		if ( false === $result ) { return self::error( 'cleanup', __( 'پاکسازی نسخه‌های موقت کامل نشد؛ دوباره تلاش کنید.', 'ma-co-access-control-pro' ) ); }
		$table = AccessStatusSchema::tables()['access_status_template_history'];
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE history_set NOT IN (%s,%s) AND history_set NOT LIKE %s AND stored_at < %d", $meta['_storage']['history_set'], $other['_storage']['history_set'] ?? '', 'steps-%', time() - DAY_IN_SECONDS ) );
		return false === $result ? self::error( 'cleanup', __( 'پاکسازی تاریخچه موقت کامل نشد؛ دوباره تلاش کنید.', 'ma-co-access-control-pro' ) ) : true;
	}

	/** @return bool Validate before indexing/casting potentially corrupt option data. */
	private function valid_meta( $meta ) {
		return is_array( $meta ) && is_array( $meta['_storage'] ?? null )
			&& self::VERSION === ( $meta['_storage']['version'] ?? null )
			&& is_string( $meta['_storage']['generation'] ?? null ) && preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/', $meta['_storage']['generation'] )
			&& is_string( $meta['_storage']['history_set'] ?? null ) && preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/', $meta['_storage']['history_set'] )
			&& is_string( $meta['_storage']['hash'] ?? null ) && preg_match( '/\A[a-f0-9]{64}\z/', $meta['_storage']['hash'] )
			&& is_int( $meta['_storage']['count'] ?? null ) && $meta['_storage']['count'] >= 0;
	}

	/** @return string Includes the site prefix to isolate switch_to_blog() contexts. */
	private function cache_key( array $meta ) {
		global $wpdb;
		return $wpdb->prefix . '|' . $this->manifest_option . '|' . self::hash( $meta );
	}

	/** @return string Empty on encoding failure. */
	public static function hash( $value ) {
		$json = wp_json_encode( $value );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	/** Invalidate options touched by SQL compare-and-swap, including persistent object caches. */
	public static function clear_option_cache( $key ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) { return; }
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** @return \WP_Error The caller supplies a literal translated message. */
	public static function error( $code, $message ) {
		return new \WP_Error( 'ma_co_acp_storage_' . $code, $message );
	}
}
