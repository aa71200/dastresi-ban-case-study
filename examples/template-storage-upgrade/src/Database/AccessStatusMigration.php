<?php
/**
 * Non-destructive migration of legacy options into verified table generations.
 *
 * @package MA_CO_AccessControlPro\Database
 */
namespace MA_CO_AccessControlPro\Database;

use MA_CO_AccessControlPro\Features\WooCommerce\AccessStatusTemplateRepository;
use MA_CO_AccessControlPro\Features\Settings\Settings\AccessStatusStudioService;
use MA_CO_AccessControlPro\Utilities\OptionMutex;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/AccessStatusStorage.php';
require_once dirname( __DIR__ ) . '/Utilities/OptionMutex.php';

final class AccessStatusMigration {

	/** @return true|\WP_Error Verified migration result, under the shared writer mutex. */
	public function run() {
		$mutex = new OptionMutex( 'ma_co_acp_access_status_studio_mutex', 120, 20, 50000,
			'ma_co_acp_storage_busy', __( 'انتقال کتابخانه منتظر پایان ذخیره جاری است؛ دوباره تلاش کنید.', 'ma-co-access-control-pro' ) );
		$lock = $mutex->acquire();
		if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			$storage = new AccessStatusStorage();
			$storage->refresh();
			$result = $this->migrate_locked( $storage );
			if ( is_wp_error( $result ) ) {
				update_option( AccessStatusStorage::ERROR_OPTION, [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'time' => time() ], false );
			} else {
				delete_option( AccessStatusStorage::ERROR_OPTION );
			}
			return $result;
		} finally {
			$mutex->release( $lock );
		}
	}

	/** @return true|\WP_Error */
	private function migrate_locked( AccessStatusStorage $storage ) {
		if ( $storage->is_active() ) {
			$state = $storage->read();
			if ( is_wp_error( $state ) ) { return $state; }
			$history = $storage->history();
			if ( is_wp_error( $history ) ) { return $history; }
			return $this->mark_complete();
		}
		foreach ( [ AccessStatusTemplateRepository::OPTION_NAME, AccessStatusStudioService::HISTORY_OPTION ] as $key ) {
			AccessStatusStorage::clear_option_cache( $key );
		}
		$raw = get_option( AccessStatusTemplateRepository::OPTION_NAME, null );
		$history = get_option( AccessStatusStudioService::HISTORY_OPTION, null );
		// The immutable backup also records missing options; no normalization runs first.
		$backup = [ 'created_at' => time(), 'templates' => $raw, 'history' => $history ];
		if ( null === get_option( AccessStatusStorage::BACKUP_OPTION, null ) ) {
			if ( ! add_option( AccessStatusStorage::BACKUP_OPTION, $backup, '', 'no' ) ) {
				return AccessStatusStorage::error( 'backup', __( 'نسخه خام اطلاعات ذخیره نشد؛ انتقال متوقف شد.', 'ma-co-access-control-pro' ) );
			}
			AccessStatusStorage::clear_option_cache( AccessStatusStorage::BACKUP_OPTION );
			if ( get_option( AccessStatusStorage::BACKUP_OPTION, null ) !== $backup ) {
				return AccessStatusStorage::error( 'backup_verify', __( 'نسخه خام اطلاعات پس از ذخیره تأیید نشد.', 'ma-co-access-control-pro' ) );
			}
		}
		$repository = new AccessStatusTemplateRepository();
		if ( null !== $raw && ! $this->valid_state( $raw ) ) {
			return AccessStatusStorage::error( 'legacy_invalid', __( 'ساختار کتابخانه قدیمی معتبر نیست؛ داده اصلی و نسخه خام حفظ شدند.', 'ma-co-access-control-pro' ) );
		}
		$payload = null === $raw ? [
			'schema_version' => AccessStatusTemplateRepository::SCHEMA_VERSION,
			'factory_revision' => AccessStatusTemplateRepository::FACTORY_REVISION,
			'default_template' => 'classic',
			'templates' => $repository->get_factory_templates(),
		] : $raw;
		$stored = $repository->compact_for_storage( $payload );
		if ( is_wp_error( $stored ) ) { return $stored; }
		if ( isset( $payload['default_template'] ) && $payload['default_template'] !== $stored['default_template'] ) {
			return AccessStatusStorage::error( 'legacy_default', __( 'قالب پیش‌فرض قدیمی قابل انتقال نیست؛ برای جلوگیری از تغییر انتخاب شما، انتقال متوقف شد.', 'ma-co-access-control-pro' ) );
		}
		foreach ( (array) ( $payload['templates'] ?? [] ) as $id => $template ) {
			if ( ! is_array( $template ) || ! isset( $stored['templates'][ $id ] ) || ( isset( $template['id'] ) && $template['id'] !== (string) $id ) ) {
				return AccessStatusStorage::error( 'legacy_identity', __( 'انتقال باعث تغییر شناسه یک قالب می‌شود؛ عملیات متوقف شد.', 'ma-co-access-control-pro' ) );
			}
		}
		if ( null !== $history && ! is_array( $history ) ) {
			return AccessStatusStorage::error( 'legacy_history', __( 'ساختار تاریخچه قدیمی معتبر نیست؛ اطلاعات حفظ شدند.', 'ma-co-access-control-pro' ) );
		}
		$seen = [];
		foreach ( (array) $history as $snapshot ) {
			$id = is_array( $snapshot ) ? ( $snapshot['id'] ?? null ) : null;
			if ( ! is_string( $id ) || '' === $id || strlen( $id ) > 100 || isset( $seen[ $id ] ) || ! $this->valid_state( $snapshot['state'] ?? null ) ) {
				return AccessStatusStorage::error( 'legacy_history', __( 'شناسه یا داده یک نسخه تاریخچه معتبر نیست؛ انتقال متوقف شد.', 'ma-co-access-control-pro' ) );
			}
			$validated = $repository->sanitize_payload( $snapshot['state'] );
			if ( is_wp_error( $validated ) ) { return $validated; }
			$seen[ $id ] = true;
		}
		$result = $storage->write( $stored, array_values( (array) $history ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return $this->mark_complete();
	}

	/** @return bool Check source shape before the existing sanitizer can supply defaults. */
	private function valid_state( $state ) {
		if ( ! is_array( $state ) || ! is_array( $state['templates'] ?? null ) ) { return false; }
		$version = $state['schema_version'] ?? null;
		if ( ! is_int( $version ) && ( ! is_string( $version ) || ! preg_match( '/\A[0-9]+\z/', $version ) ) ) { return false; }
		if ( ! in_array( (int) $version, range( 1, AccessStatusTemplateRepository::SCHEMA_VERSION ), true ) ) { return false; }
		if ( (int) ( $state['factory_revision'] ?? 0 ) > AccessStatusTemplateRepository::FACTORY_REVISION ) { return false; }
		foreach ( [ 'shared_base', 'template_visibility' ] as $key ) {
			if ( isset( $state[ $key ] ) && ! is_array( $state[ $key ] ) ) { return false; }
		}
		foreach ( $state['templates'] as $id => $template ) {
			if ( ! is_array( $template ) || ( isset( $template['id'] ) && $template['id'] !== (string) $id ) ) { return false; }
			foreach ( [ 'blocks', 'code', 'styles', 'overrides', 'states', 'orientations', 'elementor' ] as $key ) {
				if ( isset( $template[ $key ] ) && ! is_array( $template[ $key ] ) ) { return false; }
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function mark_complete() {
		update_option( AccessStatusStorage::VERSION_OPTION, AccessStatusStorage::VERSION, false );
		AccessStatusStorage::clear_option_cache( AccessStatusStorage::VERSION_OPTION );
		return (int) get_option( AccessStatusStorage::VERSION_OPTION, 0 ) === AccessStatusStorage::VERSION
			? true : AccessStatusStorage::error( 'version', __( 'ثبت نسخه ذخیره‌سازی کامل نشد؛ دوباره تلاش کنید.', 'ma-co-access-control-pro' ) );
	}
}
