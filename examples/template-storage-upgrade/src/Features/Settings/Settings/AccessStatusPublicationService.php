<?php
/** Explicit publication boundary. Working saves never write this channel. */
namespace MA_CO_AccessControlPro\Features\Settings\Settings;

use MA_CO_AccessControlPro\Database\AccessStatusStorage;
use MA_CO_AccessControlPro\Features\WooCommerce\AccessStatusTemplateRepository;
use MA_CO_AccessControlPro\Utilities\OptionMutex;

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/AccessStatusSnapshotPolicy.php';
require_once dirname( __DIR__, 3 ) . '/Database/AccessStatusStorage.php';
require_once dirname( __DIR__, 3 ) . '/Utilities/OptionMutex.php';

final class AccessStatusPublicationService {
	const VERSION = 1;
	const BACKUP_OPTION = 'ma_co_acp_access_publication_backup';
	private $repository;
	private $storage;

	public function __construct( $repository = null ) {
		$this->repository = $repository ?: new AccessStatusTemplateRepository();
		$this->storage = new AccessStatusStorage( true );
	}

	public static function exists() { return null !== get_option( AccessStatusStorage::PUBLISHED_OPTION, null ); }

	public function read() {
		$state = $this->storage->read();
		if ( ! is_wp_error( $state ) && self::VERSION !== ( $state['_publication']['version'] ?? null ) ) {
			return new \WP_Error( 'release_version', __( 'ساختار انتشار قابل خواندن نیست؛ نسخه اصلی حفظ شده است.', 'ma-co-access-control-pro' ) );
		}
		return $state;
	}

	private function locked( $callback ) {
		$mutex = new OptionMutex( 'ma_co_acp_access_status_studio_mutex', 120, 20, 50000, 'release_busy', __( 'عملیات دیگری در حال ذخیره است؛ دوباره تلاش کنید.', 'ma-co-access-control-pro' ) );
		$lock = $mutex->acquire();
		if ( is_wp_error( $lock ) ) { return $lock; }
		try { $this->storage->refresh(); return $callback(); }
		finally { $mutex->release( $lock ); }
	}

	/** Migration only: materialize the current public output before allowing working writes. */
	public function migrate() {
		return $this->locked( function () {
			if ( self::exists() ) { $state = $this->read(); return is_wp_error( $state ) ? $state : true; }
			if ( version_compare( (string) get_option( 'ma_co_acp_db_version', '0' ), '2.2.0', '>=' ) ) { return new \WP_Error( 'release_missing', __( 'مرجع انتشار حذف شده است؛ بازیابی دیتابیس لازم است و پیش‌نویس خودکار منتشر نمی‌شود.', 'ma-co-access-control-pro' ) ); }
			$state = $this->repository->get_state();
			if ( $this->repository->get_storage_error() ) { return $this->repository->get_storage_error(); }
			$backup = [ 'version' => self::VERSION, 'created_at' => time(), 'state' => $state ];
			if ( null === get_option( self::BACKUP_OPTION, null ) ) {
				$added = add_option( self::BACKUP_OPTION, $backup, '', 'no' );
				AccessStatusStorage::clear_option_cache( self::BACKUP_OPTION );
				if ( ! $added || get_option( self::BACKUP_OPTION ) !== $backup ) { return new \WP_Error( 'release_backup', __( 'نسخه پشتیبان انتشار ذخیره یا تأیید نشد.', 'ma-co-access-control-pro' ) ); }
			}
			$source = $state;
			$state['_publication'] = [ 'version' => self::VERSION, 'revision' => wp_generate_uuid4(), 'releases' => [] ];
			foreach ( $source['templates'] as $id => $template ) { $state['_publication']['releases'][ 'template:' . $id ] = $this->record( $this->fingerprint( $source, 'template', $id ) ); }
			foreach ( [ 'shared', 'default' ] as $scope ) { $state['_publication']['releases'][ $scope ] = $this->record( $this->fingerprint( $source, $scope ) ); }
			$result = $this->storage->write( $state, [] );
			if ( is_wp_error( $result ) ) { $this->discard_uncommitted( $created ); }
			return $result;
		} );
	}

	private function record( $hash ) { return [ 'source_hash' => $hash, 'published_at' => time(), 'user_id' => get_current_user_id() ]; }
	private function conflict() { return new \WP_Error( 'release_conflict', __( 'نسخه کاری یا انتشار سایت تغییر کرده است؛ وضعیت تازه را دریافت و بازبینی کنید.', 'ma-co-access-control-pro' ) ); }

	/** Scope values contain only that scope, never another template's pending changes. */
	private function value( array $state, $scope, $id = '' ) {
		if ( 'shared' === $scope ) { return $state['shared_base'] ?? []; }
		if ( 'default' === $scope ) { return $state['default_template'] ?? 'classic'; }
		return [ 'template' => $state['templates'][ $id ] ?? null, 'visibility' => $state['template_visibility'][ $id ] ?? [] ];
	}

	private function put( array &$state, $scope, $id, $value ) {
		if ( 'shared' === $scope ) { $state['shared_base'] = $value; }
		elseif ( 'default' === $scope ) { $state['default_template'] = $value; }
		elseif ( null === $value['template'] ) { unset( $state['templates'][ $id ], $state['template_visibility'][ $id ] ); }
		else { $state['templates'][ $id ] = $value['template']; $state['template_visibility'][ $id ] = $value['visibility']; }
	}

	private function fingerprint( array $state, $scope, $id = '' ) {
		$value = $this->value( $state, $scope, $id );
		if ( 'template' === $scope && $value['template'] ) {
			foreach ( [ 'status', 'archived_at', 'archive_expires_at', 'usage_count' ] as $key ) { unset( $value['template'][ $key ] ); }
		}
		return AccessStatusStorage::hash( $value );
	}

	public function status() {
		$published = $this->read();
		if ( is_wp_error( $published ) ) { return $published; }
		$working = $this->repository->get_state();
		if ( $this->repository->get_storage_error() ) { return $this->repository->get_storage_error(); }
		$manifest = get_option( AccessStatusStorage::META_OPTION, [] );
		$result = [ 'revision' => $published['_publication']['revision'], 'default_template' => $published['default_template'], 'saved_at' => $manifest['_storage']['published_at'] ?? 0, 'templates' => [] ];
		foreach ( $working['templates'] as $id => $template ) {
			$record = $published['_publication']['releases'][ 'template:' . $id ] ?? [];
			$hash = $this->fingerprint( $working, 'template', $id );
			$result['templates'][ $id ] = [ 'published' => isset( $published['templates'][ $id ] ), 'dirty' => ! $hash || $hash !== ( $record['source_hash'] ?? '' ), 'source_hash' => $hash, 'published_at' => $record['published_at'] ?? 0, 'can_restore' => isset( $record['previous'] ) ];
		}
		foreach ( [ 'shared', 'default' ] as $scope ) {
			$record = $published['_publication']['releases'][ $scope ] ?? [];
			$result[ $scope ] = [ 'dirty' => $this->fingerprint( $working, $scope ) !== ( $record['source_hash'] ?? '' ), 'published_at' => $record['published_at'] ?? 0, 'can_restore' => isset( $record['previous'] ) ];
		}
		$history = $this->storage->history();
		if ( is_wp_error( $history ) ) { return $history; }
		$result['history'] = array_map( static function ( $item ) { return [ 'id' => $item['id'], 'created_at' => $item['created_at'], 'reason' => $item['reason'], 'user_id' => $item['user_id'] ]; }, $history );
		return $result;
	}

	public function command( $operation, $scope, $id, $working_hash, $revision, $source_hash = '' ) {
		if ( ! \MA_CO_AccessControlPro\Utilities\PluginAccess::can( 'wc_duration' ) ) { return new \WP_Error( 'forbidden', __( 'اجازه انتشار ندارید.', 'ma-co-access-control-pro' ) ); }
		if ( ! in_array( $scope, [ 'template', 'shared', 'default' ], true ) || ! in_array( $operation, [ 'publish', 'restore', 'remove' ], true ) || ( 'remove' === $operation && 'template' !== $scope ) ) {
			return new \WP_Error( 'release_command', __( 'فرمان انتشار معتبر نیست.', 'ma-co-access-control-pro' ) );
		}
		return $this->locked( function () use ( $operation, $scope, $id, $working_hash, $revision, $source_hash ) {
			$state = $this->read();
			if ( is_wp_error( $state ) ) { return $state; }
			if ( $revision !== $state['_publication']['revision'] || $working_hash !== $this->repository->get_state_hash() ) { return $this->conflict(); }
			$working = $this->repository->get_state();
			$id = sanitize_key( $id );
			$key = 'template' === $scope ? 'template:' . $id : $scope;
			$old = $state['_publication']['releases'][ $key ] ?? [];
			$previous = [ 'value' => $this->value( $state, $scope, $id ), 'record' => array_diff_key( $old, [ 'previous' => true ] ) ];
			$next = $state;
			if ( 'restore' === $operation ) {
				if ( ! isset( $old['previous'] ) ) { return new \WP_Error( 'release_no_previous', __( 'انتشار قبلی موجود نیست.', 'ma-co-access-control-pro' ) ); }
				$this->put( $next, $scope, $id, $old['previous']['value'] );
				$record = $old['previous']['record'];
				$record['published_at'] = time(); $record['user_id'] = get_current_user_id();
			} elseif ( 'remove' === $operation ) {
				if ( ( $working['templates'][ $id ]['status'] ?? '' ) !== 'archived' || ( $working['templates'][ $id ]['origin'] ?? '' ) !== 'custom' || $state['default_template'] === $id ) {
					return new \WP_Error( 'release_remove', __( 'ابتدا قالب سفارشی را آرشیو و پیش‌فرض سایت را تغییر دهید.', 'ma-co-access-control-pro' ) );
				}
				$this->put( $next, $scope, $id, [ 'template' => null, 'visibility' => [] ] );
				$record = $this->record( '' );
			} elseif ( 'default' === $scope ) {
				if ( ! isset( $state['templates'][ $id ] ) || 'archived' === ( $working['templates'][ $id ]['status'] ?? '' ) ) { return new \WP_Error( 'release_default', __( 'فقط قالب منتشرشده و فعال می‌تواند پیش‌فرض سایت باشد.', 'ma-co-access-control-pro' ) ); }
				$next['default_template'] = $id;
				$record = $this->record( AccessStatusStorage::hash( $id ) );
			} else {
				if ( 'template' === $scope && ( ! isset( $working['templates'][ $id ] ) || 'archived' === $working['templates'][ $id ]['status'] ) ) { return new \WP_Error( 'release_missing', __( 'قالب قابل انتشار نیست.', 'ma-co-access-control-pro' ) ); }
				$value = $this->value( $working, $scope, $id );
				$hash = $this->fingerprint( $working, $scope, $id );
				if ( 'template' === $scope && ( ! $source_hash || $source_hash !== $hash ) ) { return $this->conflict(); }
				if ( $hash === ( $old['source_hash'] ?? '' ) ) { return $this->status(); }
				$this->put( $next, $scope, $id, $value );
				$record = $this->record( $hash );
			}
			if ( ! isset( $next['templates'][ $next['default_template'] ] ) ) { return new \WP_Error( 'release_default_missing', __( 'این عملیات قالب پیش‌فرض سایت را حذف می‌کند؛ ابتدا پیش‌فرض را تغییر دهید.', 'ma-co-access-control-pro' ) ); }
			$record['previous'] = $previous;
			$next['_publication']['releases'][ $key ] = $record;
			$next['_publication']['revision'] = wp_generate_uuid4();
			$snapshot = [ 'id' => wp_generate_uuid4(), 'created_at' => time(), 'user_id' => get_current_user_id(), 'reason' => $operation . ':' . $key, 'state' => $state ];
			$saved = $this->storage->push_snapshot( $snapshot, 10, 8388608 );
			if ( ! is_wp_error( $saved ) ) { $saved = $this->storage->write( $next ); }
			if ( is_wp_error( $saved ) ) { return $saved; }
			do_action( 'ma_co_acp_access_status_published', $scope, $id, $next['_publication']['revision'], $operation );
			return $this->status();
		} );
	}

	public function prune_history() { return $this->storage->prune_snapshots_before( AccessStatusSnapshotPolicy::cleanup_cutoff() ); }
}
