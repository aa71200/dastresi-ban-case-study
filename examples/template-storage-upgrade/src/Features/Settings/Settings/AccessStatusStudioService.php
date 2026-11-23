<?php
/**
 * Persistence commands for the access-status template studio.
 *
 * @package MA_CO_AccessControlPro\Features\Settings\Settings
 */

namespace MA_CO_AccessControlPro\Features\Settings\Settings;

use MA_CO_AccessControlPro\Features\WooCommerce\AccessStatusTemplateRepository;
use MA_CO_AccessControlPro\Utilities\OptionMutex;
use MA_CO_AccessControlPro\Database\AccessStatusStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( OptionMutex::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/Utilities/OptionMutex.php';
}
require_once __DIR__ . '/AccessStatusWorkspaceTrait.php';
require_once __DIR__ . '/AccessStatusSnapshotPolicy.php';
require_once __DIR__ . '/AccessStatusPublicationService.php';
require_once dirname( __DIR__, 3 ) . '/Database/AccessStatusStorage.php';

/**
 * Keeps destructive/history operations outside the renderer repository.
 *
 * The repository remains the canonical sanitizer and persistence boundary;
 * this service adds optimistic concurrency, recoverable history and usage-aware
 * template lifecycle commands around it.
 */
final class AccessStatusStudioService {
	use AccessStatusWorkspaceTrait;

	const HISTORY_OPTION = 'ma_co_acp_access_status_template_history';
	const BACKUP_OPTION  = 'ma_co_acp_access_status_templates_pre_v4_backup';
	const MUTEX_OPTION   = 'ma_co_acp_access_status_studio_mutex';
	const HISTORY_LIMIT  = 10;
	const HISTORY_MAX_BYTES = 2097152;
	const ARCHIVE_DAYS   = 30;
	const USAGE_LIMIT    = 100;
	const USAGE_PAGE_SIZE = 100;
	const MUTEX_TTL       = 120;
	const MUTEX_ATTEMPTS  = 20;
	const MUTEX_RETRY_DELAY = 50000;

	/** @var AccessStatusTemplateRepository */
	private $repository;

	/** @var OptionMutex */
	private $mutation_mutex;

	/** @param AccessStatusTemplateRepository|null $repository Template repository. */
	public function __construct( AccessStatusTemplateRepository $repository = null ) {
		$this->repository = $repository ?: new AccessStatusTemplateRepository();
		$this->mutation_mutex = new OptionMutex(
			self::MUTEX_OPTION,
			self::MUTEX_TTL,
			self::MUTEX_ATTEMPTS,
			self::MUTEX_RETRY_DELAY,
			'ma_co_acp_studio_write_busy',
			__( 'تغییر دیگری در قالب‌ها در حال ذخیره است. چند لحظه دیگر دوباره تلاش کنید.', 'ma-co-access-control-pro' )
		);
	}

	/**
	 * Preserve the unpublished pre-v4 payload once before normalization.
	 *
	 * @return void
	 */
	public static function ensure_pre_v4_backup() {
		if ( false !== get_option( self::BACKUP_OPTION, false ) ) {
			return;
		}

		$raw = get_option( AccessStatusTemplateRepository::OPTION_NAME, null );
		if ( ! is_array( $raw ) || absint( $raw['schema_version'] ?? 0 ) >= 4 ) {
			return;
		}

		add_option(
			self::BACKUP_OPTION,
			[
				'created_at' => time(),
				'payload'    => $raw,
			],
			'',
			'no'
		);
	}

	/** @return string */
	public function get_state_hash() {
		if ( method_exists( $this->repository, 'get_state_hash' ) ) {
			return (string) $this->repository->get_state_hash();
		}

		return $this->hash_state( $this->repository->get_state() );
	}

	/** Surface storage failures separately while keeping existing array response contracts. */
	public function get_storage_error() {
		$this->repository->get_state();
		if ( method_exists( $this->repository, 'get_storage_error' ) && $this->repository->get_storage_error() ) {
			return $this->repository->get_storage_error();
		}
		$history = $this->read_history();
		return is_wp_error( $history ) ? $history : null;
	}

	/**
	 * Save a complete studio state after an optimistic-lock check.
	 *
	 * @param mixed  $payload Raw state.
	 * @param string $expected_hash Hash supplied by the editor.
	 * @param string $reason History reason.
	 * @return array|\WP_Error
	 */
	public function save( $payload, $expected_hash, $reason = 'save' ) {
		return $this->run_locked_mutation(
			function () use ( $payload, $expected_hash, $reason ) {
				return $this->save_locked( $payload, $expected_hash, $reason );
			}
		);
	}

	/** @return array|\WP_Error */
	private function save_locked( $payload, $expected_hash, $reason ) {
		$lock = $this->check_expected_hash( $expected_hash );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$clean = $this->repository->sanitize_payload( $payload );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( class_exists( \MA_CO_AccessControlPro\Utilities\OptionalDependencies::class ) && ! \MA_CO_AccessControlPro\Utilities\OptionalDependencies::usable( 'elementor' ) ) {
			$references = static function ( $value ) use ( &$references ) {
				$ids = [];
				if ( is_string( $value ) && preg_match_all( '/\[elementor-template\b[^\]]*\bid\s*=\s*[\x22\x27]?(\d+)/i', $value, $matches ) ) { return array_map( 'absint', $matches[1] ); }
				if ( ! is_array( $value ) ) { return $ids; }
				if ( 'elementor_template' === ( $value['type'] ?? '' ) ) { $ids[] = absint( $value['settings']['template_id'] ?? $value['template_id'] ?? 0 ); }
				foreach ( $value as $child ) { $ids = array_merge( $ids, $references( $child ) ); }
				return array_unique( $ids );
			};
			if ( array_diff( $references( $clean ), $references( $this->repository->get_state() ) ) ) {
				return new \WP_Error( 'elementor_unavailable', \MA_CO_AccessControlPro\Utilities\OptionalDependencies::status( 'elementor' )['message'] );
			}
		}
		$current = $this->repository->get_state();
		if ( $this->hash_state( $current ) === $this->hash_state( $clean ) ) {
			return $this->result( $current, __( 'تغییری برای ذخیره وجود نداشت.', 'ma-co-access-control-pro' ) );
		}

		$snapshot = $this->push_snapshot( $current, $reason );
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		$saved = $this->repository->save_payload( $clean );
		if ( is_wp_error( $saved ) ) { return $saved; }

		return $this->result( $saved, __( 'پیش‌نویس ذخیره شد.', 'ma-co-access-control-pro' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function get_history() {
		$history = $this->read_history();
		if ( is_wp_error( $history ) ) { return []; }
		$history = is_array( $history ) ? array_values( $history ) : [];
		$output  = [];

		foreach ( $history as $snapshot ) {
			if ( ! is_array( $snapshot ) || empty( $snapshot['id'] ) || empty( $snapshot['state'] ) ) {
				continue;
			}
			$created_at = absint( $snapshot['created_at'] ?? 0 );
			$expires_at = AccessStatusSnapshotPolicy::expires_at( $created_at );
			if ( $expires_at > 0 && $expires_at <= time() ) {
				continue;
			}
			$state     = is_array( $snapshot['state'] ) ? $snapshot['state'] : [];
			$templates = isset( $state['templates'] ) && is_array( $state['templates'] ) ? $state['templates'] : [];
			$output[] = [
				'id'             => sanitize_text_field( (string) $snapshot['id'] ),
				'created_at'     => $created_at,
				'expires_at'     => $expires_at,
				'user_id'        => absint( $snapshot['user_id'] ?? 0 ),
				'user_name'      => sanitize_text_field( (string) ( $snapshot['user_name'] ?? '' ) ),
				'reason'         => sanitize_key( (string) ( $snapshot['reason'] ?? 'save' ) ),
				'hash'           => sanitize_text_field( (string) ( $snapshot['hash'] ?? '' ) ),
				'template_count' => count( $templates ),
			];
		}

		return $output;
	}

	/**
	 * Restore one server snapshot while first snapshotting the current state.
	 *
	 * @return array|\WP_Error
	 */
	public function restore_history( $snapshot_id, $expected_hash ) {
		return $this->run_locked_mutation(
			function () use ( $snapshot_id, $expected_hash ) {
				return $this->restore_history_locked( $snapshot_id, $expected_hash );
			}
		);
	}

	/** @return array|\WP_Error */
	private function restore_history_locked( $snapshot_id, $expected_hash ) {
		$lock = $this->check_expected_hash( $expected_hash );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$snapshot = $this->find_snapshot( $snapshot_id );
		if ( ! $snapshot ) {
			return new \WP_Error( 'ma_co_acp_studio_history_missing', __( 'نسخه تاریخی موردنظر پیدا نشد.', 'ma-co-access-control-pro' ) );
		}

		$clean = $this->repository->sanitize_payload( $snapshot['state'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$snapshot_backup = $this->push_snapshot( $this->repository->get_state(), 'before_restore' );
		if ( is_wp_error( $snapshot_backup ) ) { return $snapshot_backup; }
		$saved = $this->repository->save_payload( $clean );
		if ( is_wp_error( $saved ) ) { return $saved; }

		return $this->result( $saved, __( 'نسخه انتخاب‌شده بازیابی شد.', 'ma-co-access-control-pro' ) );
	}

	/** Delete one server snapshot without changing the current library. @return array|\WP_Error */
	public function delete_history( $snapshot_id ) {
		return $this->run_locked_mutation( function () use ( $snapshot_id ) {
			$snapshot_id = sanitize_text_field( (string) $snapshot_id );
			if ( '' === $snapshot_id || ! $this->find_snapshot( $snapshot_id ) ) {
				return new \WP_Error( 'ma_co_acp_studio_history_missing', __( 'نسخه بازیابی پیدا نشد یا مدت نگهداری آن تمام شده است.', 'ma-co-access-control-pro' ) );
			}
			$result = $this->delete_snapshot_locked( $snapshot_id );
			if ( is_wp_error( $result ) ) { return $result; }
			return [ 'message' => __( 'نسخه بازیابی انتخاب‌شده پاک شد.', 'ma-co-access-control-pro' ), 'history' => $this->get_history(), 'state_hash' => $this->get_state_hash() ];
		} );
	}

	/** Clear every recoverable Studio snapshot. @return array|\WP_Error */
	public function clear_history() {
		return $this->run_locked_mutation( function () {
			$storage = new AccessStatusStorage();
			if ( $storage->is_active() ) {
				$result = $storage->clear_history();
				if ( is_wp_error( $result ) ) { return $result; }
			} else {
				if ( false === delete_option( self::HISTORY_OPTION ) && false !== get_option( self::HISTORY_OPTION, false ) ) {
					return new \WP_Error( 'ma_co_acp_studio_history_clear_failed', __( 'حذف نسخه‌های بازیابی انجام نشد.', 'ma-co-access-control-pro' ) );
				}
			}
			return [ 'message' => __( 'همه نسخه‌های بازیابی پاک شدند.', 'ma-co-access-control-pro' ), 'history' => [], 'state_hash' => $this->get_state_hash() ];
		} );
	}

	/** @return true|\WP_Error */
	private function delete_snapshot_locked( $snapshot_id ) {
		$storage = new AccessStatusStorage();
		if ( $storage->is_active() ) {
			return $storage->delete_snapshot( $snapshot_id );
		}
		$history = $this->read_history();
		if ( is_wp_error( $history ) ) { return $history; }
		$filtered = array_values( array_filter( (array) $history, static function ( $snapshot ) use ( $snapshot_id ) {
			return ! is_array( $snapshot ) || ! hash_equals( (string) ( $snapshot['id'] ?? '' ), (string) $snapshot_id );
		} ) );
		if ( count( $filtered ) === count( (array) $history ) ) { return true; }
		return update_option( self::HISTORY_OPTION, $filtered, false ) || get_option( self::HISTORY_OPTION, [] ) === $filtered
			? true
			: new \WP_Error( 'ma_co_acp_studio_history_delete_failed', __( 'حذف نسخه بازیابی انجام نشد.', 'ma-co-access-control-pro' ) );
	}

	/** @return array|\WP_Error */
	public function archive_template( $template_id, $expected_hash ) {
		return $this->run_locked_mutation(
			function () use ( $template_id, $expected_hash ) {
				return $this->archive_template_locked( $template_id, $expected_hash );
			}
		);
	}

	/** @return array|\WP_Error */
	private function archive_template_locked( $template_id, $expected_hash ) {
		$lock = $this->check_expected_hash( $expected_hash );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$state       = $this->repository->get_state();
		$template_id = sanitize_key( (string) $template_id );
		$template    = $state['templates'][ $template_id ] ?? null;

		if ( ! is_array( $template ) || 'custom' !== ( $template['origin'] ?? '' ) ) {
			return new \WP_Error( 'ma_co_acp_studio_archive_invalid', __( 'فقط قالب‌های سفارشی را می‌توانید به زباله‌دان منتقل کنید.', 'ma-co-access-control-pro' ) );
		}
		$state['templates'][ $template_id ]['status']             = 'archived';
		$state['templates'][ $template_id ]['archived_at']        = time();
		$state['templates'][ $template_id ]['archive_expires_at'] = time() + ( self::ARCHIVE_DAYS * DAY_IN_SECONDS );
		if ( ( $state['default_template'] ?? '' ) === $template_id ) {
			$state['default_template'] = 'classic';
		}

		$snapshot_backup = $this->push_snapshot( $this->repository->get_state(), 'archive' );
		if ( is_wp_error( $snapshot_backup ) ) { return $snapshot_backup; }
		$saved = $this->repository->save_payload( $state );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $this->result( $saved, __( 'قالب به زباله‌دان منتقل شد. انتخاب مستقیم این قالب در بخش‌های سایت حفظ شد. اگر قالب پیش‌فرض بود، پیش‌فرض سایت به «کلاسیک» برگشت.', 'ma-co-access-control-pro' ) );
	}

	/** @return array|\WP_Error */
	public function restore_archived_template( $template_id, $expected_hash ) {
		return $this->run_locked_mutation(
			function () use ( $template_id, $expected_hash ) {
				return $this->restore_archived_template_locked( $template_id, $expected_hash );
			}
		);
	}

	/** @return array|\WP_Error */
	private function restore_archived_template_locked( $template_id, $expected_hash ) {
		$lock = $this->check_expected_hash( $expected_hash );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$state       = $this->repository->get_state();
		$template_id = sanitize_key( (string) $template_id );
		$template    = $state['templates'][ $template_id ] ?? null;
		if ( ! is_array( $template ) || 'custom' !== ( $template['origin'] ?? '' ) || 'archived' !== ( $template['status'] ?? 'active' ) ) {
			return new \WP_Error( 'ma_co_acp_studio_restore_invalid', __( 'قالب موردنظر در زباله‌دان پیدا نشد.', 'ma-co-access-control-pro' ) );
		}

		$state['templates'][ $template_id ]['status']             = 'active';
		$state['templates'][ $template_id ]['archived_at']        = 0;
		$state['templates'][ $template_id ]['archive_expires_at'] = 0;
		$snapshot_backup = $this->push_snapshot( $this->repository->get_state(), 'restore_archive' );
		if ( is_wp_error( $snapshot_backup ) ) { return $snapshot_backup; }
		$saved = $this->repository->save_payload( $state );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $this->result( $saved, __( 'قالب از زباله‌دان بازیابی شد.', 'ma-co-access-control-pro' ) );
	}

	/** @return array|\WP_Error */
	public function purge_template( $template_id, $expected_hash, $confirm_usage = false ) {
		return $this->run_locked_mutation(
			function () use ( $template_id, $expected_hash, $confirm_usage ) {
				return $this->purge_template_locked( $template_id, $expected_hash, $confirm_usage );
			}
		);
	}

	/** @return array|\WP_Error */
	private function purge_template_locked( $template_id, $expected_hash, $confirm_usage ) {
		$lock = $this->check_expected_hash( $expected_hash );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$state       = $this->repository->get_state();
		$template_id = sanitize_key( (string) $template_id );
		$template    = $state['templates'][ $template_id ] ?? null;
		if ( AccessStatusPublicationService::exists() ) {
			$published = ( new AccessStatusPublicationService( $this->repository ) )->read();
			if ( is_wp_error( $published ) ) { return $published; }
			if ( isset( $published['templates'][ $template_id ] ) ) { return new \WP_Error( 'release_in_use', __( 'این قالب منتشرشده است؛ ابتدا انتشار آن را از بخش انتشار حذف کنید.', 'ma-co-access-control-pro' ) ); }
		}
		if ( ! is_array( $template ) || 'custom' !== ( $template['origin'] ?? '' ) || 'archived' !== ( $template['status'] ?? 'active' ) ) {
			return new \WP_Error( 'ma_co_acp_studio_purge_invalid', __( 'حذف دائمی فقط برای قالب‌های سفارشیِ داخل زباله‌دان امکان‌پذیر است.', 'ma-co-access-control-pro' ) );
		}

		$usages = $this->find_template_usages( $template_id );
		if ( is_wp_error( $usages ) ) {
			return $usages;
		}
		if ( $usages && ! $confirm_usage ) {
			return new \WP_Error(
				'ma_co_acp_studio_template_in_use',
				__( 'این قالب هنوز در سایت استفاده می‌شود. پیش از حذف کامل، محل‌های استفاده را بررسی کنید و حذف را جداگانه تأیید کنید.', 'ma-co-access-control-pro' ),
				[ 'usages' => $usages ]
			);
		}

		$snapshot_backup = $this->push_snapshot( $this->repository->get_state(), 'purge' );
		if ( is_wp_error( $snapshot_backup ) ) { return $snapshot_backup; }
		unset( $state['templates'][ $template_id ], $state['template_visibility'][ $template_id ] );
		$saved = $this->repository->save_payload( $state );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$result           = $this->result( $saved, __( 'قالب از کتابخانه حذف شد. نسخه بازیابی آن تا پایان مدت تعیین‌شده باقی می‌ماند.', 'ma-co-access-control-pro' ) );
		$result['usages'] = $usages;
		return $result;
	}

	/**
	 * Validate and normalize a single-template or library import without saving.
	 *
	 * @return array|\WP_Error
	 */
	public function prepare_import( $payload, $scope = 'library', $mode = 'merge', $expected_hash = '' ) {
		return $this->run_locked_mutation(
			function () use ( $payload, $scope, $mode, $expected_hash ) {
				$lock = $this->check_expected_hash( $expected_hash );
				if ( is_wp_error( $lock ) ) { return $lock; }
				return $this->normalize_import( $payload, $scope, $mode, true );
			}
		);
	}

	/** Analyze an import without acquiring the mutation mutex or writing staged data. */
	public function analyze_import( $payload, $scope = 'library', $mode = 'merge', $expected_hash = '' ) {
		$lock = $this->check_expected_hash( $expected_hash );
		if ( is_wp_error( $lock ) ) { return $lock; }
		return $this->normalize_import( $payload, $scope, $mode, false );
	}

	/** Shared import normalization. Analyze mode is intentionally side-effect free. @return array|\WP_Error */
	private function normalize_import( $payload, $scope, $mode, $include_state ) {

		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error( 'ma_co_acp_studio_import_json', __( 'فایل JSON انتخاب‌شده معتبر نیست.', 'ma-co-access-control-pro' ) );
			}
			$payload = $decoded;
		}
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'ma_co_acp_studio_import_payload', __( 'ساختار فایل ورودی معتبر نیست.', 'ma-co-access-control-pro' ) );
		}

		$format = sanitize_key( (string) ( $payload['format'] ?? '' ) );
		$format_version = absint( $payload['format_version'] ?? 0 );
		$contracts = [
			'ma-co-acp-access-status-template' => [ 'scope' => 'template', 'version' => 2 ],
			'ma-co-acp-access-status-library'  => [ 'scope' => 'library', 'version' => 1 ],
		];
		if ( ! isset( $contracts[ $format ] ) ) {
			return new \WP_Error( 'ma_co_acp_studio_import_format', __( 'این فایل JSON خروجی معتبر قالب‌ساز دسترسی‌بان نیست.', 'ma-co-access-control-pro' ) );
		}
		if ( $format_version !== $contracts[ $format ]['version'] ) {
			return new \WP_Error( 'ma_co_acp_studio_import_version', __( 'نسخه فایل انتخاب‌شده با این نسخه افزونه سازگار نیست.', 'ma-co-access-control-pro' ) );
		}
		$scope = $contracts[ $format ]['scope'];
		$mode  = 'replace' === sanitize_key( (string) $mode ) && 'library' === $scope ? 'replace' : 'merge';
		$current_state = $this->repository->get_state();
		$state = $current_state;
		$added = [];

		if ( 'template' === $scope ) {
			$template = isset( $payload['template'] ) && is_array( $payload['template'] ) ? $payload['template'] : $payload;
			$visibility = isset( $payload['visibility'] ) && is_array( $payload['visibility'] ) ? $payload['visibility'] : [];
			$dependency_warnings = $this->audit_local_dependencies( $template );
			$template = $this->disable_invalid_dependencies( $template, $dependency_warnings );
			$id       = $this->new_custom_id();
			$template['id']                 = $id;
			$template['origin']             = 'custom';
			$template['factory_id']         = '';
			$template['factory_revision']   = 0;
			$template['status']             = 'active';
			$template['archived_at']        = 0;
			$template['archive_expires_at'] = 0;
			$state['templates'][ $id ]      = $template;
			if ( $visibility ) {
				$state['template_visibility'][ $id ] = $visibility;
			}
			$added[] = $id;
		} else {
			$incoming_payload = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : $payload;
			$incoming         = $this->repository->sanitize_payload( $incoming_payload );
			if ( is_wp_error( $incoming ) ) {
				return $incoming;
			}

			if ( 'replace' === $mode ) {
				$dependency_warnings = $this->audit_local_dependencies( $incoming );
				$incoming = $this->disable_invalid_dependencies( $incoming, $dependency_warnings );
				$state = $this->activate_imported_custom_templates( $incoming );
			} else {
				foreach ( $incoming['templates'] as $source_id => $template ) {
					if ( ! is_array( $template ) || 'custom' !== ( $template['origin'] ?? '' ) ) {
						continue;
					}
					$template_warnings = $this->audit_local_dependencies( $template );
					$dependency_warnings = array_merge( $dependency_warnings ?? [], $template_warnings );
					$template = $this->disable_invalid_dependencies( $template, $template_warnings );
					$id = $this->new_custom_id();
					$template['id']                 = $id;
					$template['status']             = 'active';
					$template['archived_at']        = 0;
					$template['archive_expires_at'] = 0;
					$state['templates'][ $id ]      = $template;
					if ( isset( $incoming['template_visibility'][ $source_id ] ) ) {
						$state['template_visibility'][ $id ] = $incoming['template_visibility'][ $source_id ];
					}
					$added[] = $id;
				}
			}
		}

		$clean = $this->repository->sanitize_payload( $state );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$result = [
			'added'          => $added,
			'mode'           => $mode,
			'scope'          => $scope,
			'format'         => $format,
			'format_version' => $format_version,
			'template_count' => count( $clean['templates'] ),
			'current_template_count' => count( (array) ( $current_state['templates'] ?? [] ) ),
			'analyze_only'   => ! $include_state,
			'state_hash'     => $this->get_state_hash(),
			'dependency_warnings' => array_values( $dependency_warnings ?? [] ),
		];
		if ( $include_state ) {
			$result['state'] = $clean;
			$result['history'] = $this->get_history();
			$result['archived_templates'] = $this->get_archived_templates();
		}
		return $result;
	}

	/**
	 * Imported archive timestamps are installation-local and never authoritative.
	 *
	 * @param array $state Sanitized imported library.
	 * @return array
	 */
	private function activate_imported_custom_templates( array $state ) {
		foreach ( (array) ( $state['templates'] ?? [] ) as $id => $template ) {
			if ( ! is_array( $template ) || 'custom' !== ( $template['origin'] ?? '' ) ) {
				continue;
			}
			$state['templates'][ $id ]['status']             = 'active';
			$state['templates'][ $id ]['archived_at']        = 0;
			$state['templates'][ $id ]['archive_expires_at'] = 0;
		}

		return $state;
	}

	/**
	 * Report imported references that cannot be resolved on this installation.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function audit_local_dependencies( $node, $path = 'state' ) {
		if ( ! is_array( $node ) ) {
			return [];
		}
		$warnings = [];
		if ( isset( $node['type'], $node['settings'] ) && is_array( $node['settings'] ) ) {
			$type = sanitize_key( (string) $node['type'] );
			if ( 'elementor_template' === $type ) {
				$id = absint( $node['settings']['template_id'] ?? 0 );
				$post = $id && function_exists( 'get_post' ) ? get_post( $id ) : null;
				if ( ! $post || 'elementor_library' !== ( $post->post_type ?? '' ) || 'publish' !== ( $post->post_status ?? '' ) ) {
					$warnings[] = [ 'path' => $path, 'kind' => 'elementor', 'id' => $id, 'message' => __( 'قالب المنتور در این سایت پیدا نشد یا منتشر نشده است؛ بنابراین بلوک مربوط به آن غیرفعال شد.', 'ma-co-access-control-pro' ) ];
				}
			}
			if ( 'image' === $type && 'media' === sanitize_key( (string) ( $node['settings']['source'] ?? '' ) ) ) {
				$id = absint( $node['settings']['attachment_id'] ?? 0 );
				$valid = $id && ( function_exists( 'wp_attachment_is_image' ) ? wp_attachment_is_image( $id ) : false );
				if ( ! $valid ) {
					$warnings[] = [ 'path' => $path, 'kind' => 'media', 'id' => $id, 'message' => __( 'تصویر رسانه‌ای روی این سایت موجود نیست و بلوک آن غیرفعال شد.', 'ma-co-access-control-pro' ) ];
				}
			}
		}
		foreach ( $node as $key => $child ) {
			if ( is_array( $child ) ) {
				$warnings = array_merge( $warnings, $this->audit_local_dependencies( $child, $path . '.' . sanitize_key( (string) $key ) ) );
			}
		}
		return $warnings;
	}

	/** @return array */
	private function disable_invalid_dependencies( array $node, array $warnings, $path = 'state' ) {
		$invalid_paths = [];
		foreach ( $warnings as $warning ) {
			if ( ! empty( $warning['path'] ) ) {
				$invalid_paths[ (string) $warning['path'] ] = true;
			}
		}
		if ( isset( $invalid_paths[ $path ], $node['type'] ) ) {
			$node['enabled'] = false;
		}
		foreach ( $node as $key => $child ) {
			if ( is_array( $child ) ) {
				$node[ $key ] = $this->disable_invalid_dependencies( $child, $warnings, $path . '.' . sanitize_key( (string) $key ) );
			}
		}
		return $node;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_archived_templates() {
		$state  = $this->repository->get_state();
		$output = [];
		foreach ( $state['templates'] as $id => $template ) {
			if ( 'custom' !== ( $template['origin'] ?? '' ) || 'archived' !== ( $template['status'] ?? 'active' ) ) {
				continue;
			}
			$output[] = [
				'id'                 => sanitize_key( (string) $id ),
				'name'               => sanitize_text_field( (string) ( $template['name'] ?? $id ) ),
				'archived_at'        => absint( $template['archived_at'] ?? 0 ),
				'archive_expires_at' => absint( $template['archive_expires_at'] ?? 0 ),
			];
		}
		return $output;
	}

	/**
	 * Delete expired archives only when no detectable reference remains.
	 *
	 * @return int|\WP_Error
	 */
	public function cleanup_expired_archives() {
		return $this->run_locked_mutation(
			function () {
				$history_cleanup = $this->cleanup_expired_history_locked();
				if ( is_wp_error( $history_cleanup ) ) { return $history_cleanup; }
				if ( AccessStatusPublicationService::exists() ) {
					$publication_cleanup = ( new AccessStatusPublicationService( $this->repository ) )->prune_history();
					if ( is_wp_error( $publication_cleanup ) ) { return $publication_cleanup; }
				}

				$result = $this->cleanup_expired_archives_locked();
				if ( is_wp_error( $result ) ) { return $result; }

				$storage = new AccessStatusStorage();
				if ( $storage->is_active() ) {
					$cleanup = $storage->cleanup_retired_generations();
					if ( is_wp_error( $cleanup ) ) { return $cleanup; }
				}

				return $result;
			}
		);
	}

	/** Remove snapshots that exceeded the configured retention window. @return int|\WP_Error */
	private function cleanup_expired_history_locked() {
		$cutoff  = AccessStatusSnapshotPolicy::cleanup_cutoff();
		$storage = new AccessStatusStorage();
		if ( $storage->is_active() ) {
			return $cutoff > 0 ? $storage->prune_snapshots_before( $cutoff ) : 0;
		}

		$history = $this->read_history();
		if ( is_wp_error( $history ) ) { return $history; }
		$history = is_array( $history ) ? array_values( $history ) : [];
		$kept = array_values( array_filter( $history, static function ( $snapshot ) {
			$created_at = is_array( $snapshot ) ? absint( $snapshot['created_at'] ?? 0 ) : 0;
			return 0 === $created_at || ! AccessStatusSnapshotPolicy::is_expired( $created_at );
		} ) );
		$deleted = count( $history ) - count( $kept );
		if ( 0 === $deleted ) { return 0; }
		$saved = update_option( self::HISTORY_OPTION, $kept, false );
		return $saved || get_option( self::HISTORY_OPTION, [] ) === $kept
			? $deleted
			: new \WP_Error( 'ma_co_acp_studio_history_cleanup_failed', __( 'پاک‌سازی نسخه‌های بازیابی قدیمی انجام نشد.', 'ma-co-access-control-pro' ) );
	}

	/** @return int|\WP_Error */
	private function cleanup_expired_archives_locked() {
		$state   = $this->repository->get_state();
		$published = AccessStatusPublicationService::exists() ? ( new AccessStatusPublicationService( $this->repository ) )->read() : [];
		if ( is_wp_error( $published ) ) { return $published; }
		$changed = false;
		$deleted = 0;
		$now     = time();

		foreach ( $state['templates'] as $id => $template ) {
			if ( isset( $published['templates'][ $id ] ) ) { continue; }
			if ( ! is_array( $template ) ) {
				continue;
			}
			$archived_at = $this->valid_positive_timestamp( $template['archived_at'] ?? null );
			$expires_at  = $this->valid_positive_timestamp( $template['archive_expires_at'] ?? null );
			if (
				'custom' !== ( $template['origin'] ?? '' )
				|| 'archived' !== ( $template['status'] ?? 'active' )
				|| 0 === $archived_at
				|| 0 === $expires_at
				|| $expires_at < $archived_at
				|| $expires_at > $now
			) {
				continue;
			}

			$usages = $this->find_template_usages( $id );
			if ( is_wp_error( $usages ) ) {
				return $usages;
			}
			if ( $usages ) {
				continue;
			}
			unset( $state['templates'][ $id ], $state['template_visibility'][ $id ] );
			$changed = true;
			++$deleted;
		}

		if ( ! $changed ) {
			return 0;
		}
		$snapshot_backup = $this->push_snapshot( $this->repository->get_state(), 'archive_cleanup' );
		if ( is_wp_error( $snapshot_backup ) ) { return $snapshot_backup; }
		$saved = $this->repository->save_payload( $state );
		return is_wp_error( $saved ) ? $saved : $deleted;
	}

	/** @return int Zero means missing, malformed or non-positive. */
	private function valid_positive_timestamp( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( ! is_string( $value ) || ! preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return 0;
		}

		$timestamp = (int) $value;
		return $timestamp > 0 ? $timestamp : 0;
	}

	/**
	 * Locate direct shortcode and Elementor document references.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function find_template_usages( $template_id ) {
		global $wpdb;

		$template_id = sanitize_key( (string) $template_id );
		if ( '' === $template_id ) {
			return [];
		}
		if (
			! is_object( $wpdb )
			|| ! isset( $wpdb->posts, $wpdb->postmeta )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_results' )
			|| ! method_exists( $wpdb, 'esc_like' )
		) {
			return $this->usage_scan_error( 'unavailable', 'database' );
		}

		$like         = '%' . $wpdb->esc_like( $template_id ) . '%';
		$usages       = [];
		$quoted       = preg_quote( $template_id, '/' );
		$shortcode_re = '/\[(?:dastresi_ban_access_status|ma_co_acp_access_status)\b[^\]]*\btemplate\s*=\s*(?:(["\'])' . $quoted . '\1|' . $quoted . '(?=\s|\/?\]))/iu';
		$cursor       = 0;

		do {
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE ID > %d AND (post_content LIKE %s OR post_content LIKE %s) AND post_content LIKE %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d",
					$cursor,
					'%[dastresi_ban_access_status%',
					'%[ma_co_acp_access_status%',
					$like,
					self::USAGE_PAGE_SIZE
				),
				ARRAY_A
			);
			$error = $this->validate_usage_query( $candidates, 'shortcode' );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			if ( ! $candidates ) {
				break;
			}

			$next_cursor = $cursor;
			foreach ( $candidates as $post ) {
				$next_cursor = max( $next_cursor, absint( $post['ID'] ?? 0 ) );
				if ( preg_match( $shortcode_re, (string) ( $post['post_content'] ?? '' ) ) ) {
					$usages[] = $this->usage_record( $post, 'shortcode' );
					if ( count( $usages ) >= self::USAGE_LIMIT ) {
						return array_slice( $usages, 0, self::USAGE_LIMIT );
					}
				}
			}
			if ( $next_cursor <= $cursor ) {
				return $this->usage_scan_error( 'invalid_page', 'shortcode' );
			}
			$cursor = $next_cursor;
		} while ( count( $candidates ) === self::USAGE_PAGE_SIZE );

		$cursor = 0;
		do {
			$elementor = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_type, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.ID > %d AND pm.meta_key='_elementor_data' AND pm.meta_value LIKE %s AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID ASC LIMIT %d",
					$cursor,
					$like,
					self::USAGE_PAGE_SIZE
				),
				ARRAY_A
			);
			$error = $this->validate_usage_query( $elementor, 'elementor' );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			if ( ! $elementor ) {
				break;
			}

			$next_cursor = $cursor;
			foreach ( $elementor as $post ) {
				$next_cursor = max( $next_cursor, absint( $post['ID'] ?? 0 ) );
				$raw_data = (string) ( $post['meta_value'] ?? '' );
				$data     = json_decode( $raw_data, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
					// The database LIKE already found this template ID inside Elementor
					// data. If the document is malformed we cannot prove the reference
					// is absent, so destructive cleanup must fail closed.
					return $this->usage_scan_error( 'malformed_elementor_data', 'elementor' );
				}
				if ( $this->elementor_data_uses_template( $data, $template_id ) ) {
					$usages[] = $this->usage_record( $post, 'elementor' );
					if ( count( $usages ) >= self::USAGE_LIMIT ) {
						return array_slice( $usages, 0, self::USAGE_LIMIT );
					}
				}
			}
			if ( $next_cursor <= $cursor ) {
				return $this->usage_scan_error( 'invalid_page', 'elementor' );
			}
			$cursor = $next_cursor;
		} while ( count( $elementor ) === self::USAGE_PAGE_SIZE );

		return $usages;
	}

	/** @return true|\WP_Error */
	private function validate_usage_query( $rows, $source ) {
		global $wpdb;

		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			return $this->usage_scan_error( 'query_failed', $source );
		}

		return true;
	}

	/** @return \WP_Error */
	private function usage_scan_error( $reason, $source ) {
		global $wpdb;

		$data = [
			'scan_source' => sanitize_key( (string) $source ),
			'scan_reason' => sanitize_key( (string) $reason ),
		];
		if ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) ) {
			$data['database_error'] = sanitize_text_field( (string) $wpdb->last_error );
		}

		return new \WP_Error(
			'ma_co_acp_studio_usage_scan_failed',
			__( 'نتوانستیم همه محل‌های استفاده از این قالب را بررسی کنیم؛ برای جلوگیری از حذف اشتباه، قالب حذف نشد.', 'ma-co-access-control-pro' ),
			$data
		);
	}

	/**
	 * Serialize every command that reads then stages or writes studio state.
	 *
	 * @param callable $callback Locked command.
	 * @return mixed|\WP_Error
	 */
	private function run_locked_mutation( $callback ) {
		$mutex = $this->mutation_mutex->acquire();
		if ( is_wp_error( $mutex ) ) {
			return $mutex;
		}

		try {
			$storage = new AccessStatusStorage();
			$storage->refresh();
			$this->repository->get_state();
			if ( method_exists( $this->repository, 'get_storage_error' ) && $this->repository->get_storage_error() ) {
				return $this->repository->get_storage_error();
			}
			if ( null !== get_option( 'ma_co_acp_db_version', null ) && ! AccessStatusPublicationService::exists() ) { return new \WP_Error( 'release_migration_pending', __( 'ارتقای انتشار کامل نشده است؛ ذخیره تا تکمیل ارتقا متوقف است.', 'ma-co-access-control-pro' ) ); }
			if ( $storage->is_active() ) {
				if ( ! AccessStatusPublicationService::exists() ) { return new \WP_Error( 'release_migration_pending', __( 'ارتقای انتشار کامل نشده است؛ نسخه فعلی سایت حفظ شد.', 'ma-co-access-control-pro' ) ); }
				$published = ( new AccessStatusPublicationService( $this->repository ) )->read();
				if ( is_wp_error( $published ) ) { return $published; }
				$history = $storage->history();
				if ( is_wp_error( $history ) ) { return $history; }
			}
			return call_user_func( $callback );
		} finally {
			$this->mutation_mutex->release( $mutex );
		}
	}


	/** @return array|\WP_Error */
	private function check_expected_hash( $expected_hash ) {
		$expected_hash = preg_replace( '/[^a-f0-9]/i', '', (string) $expected_hash );
		$current_hash  = $this->get_state_hash();
		if ( '' === $expected_hash || ! hash_equals( $current_hash, $expected_hash ) ) {
			return new \WP_Error(
				'ma_co_acp_studio_conflict',
				__( 'کتابخانه در نشست دیگری تغییر کرده است. نسخه تازه را دریافت و تغییرات را دوباره اعمال کنید.', 'ma-co-access-control-pro' ),
				[
					'current_hash' => $current_hash,
					'state'        => $this->repository->get_state(),
				]
			);
		}
		return [];
	}

	/** @return array<string,mixed> */
	private function result( array $state, $message ) {
		return [
			'publication'        => AccessStatusPublicationService::exists() ? ( new AccessStatusPublicationService( $this->repository ) )->status() : [],
			'state'              => $state,
			'state_hash'         => $this->get_state_hash(),
			'message'            => sanitize_text_field( (string) $message ),
			'history'            => $this->get_history(),
			'archived_templates' => $this->get_archived_templates_from_state( $state ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function get_archived_templates_from_state( array $state ) {
		$output = [];
		foreach ( (array) ( $state['templates'] ?? [] ) as $id => $template ) {
			if ( ! is_array( $template ) || 'custom' !== ( $template['origin'] ?? '' ) || 'archived' !== ( $template['status'] ?? 'active' ) ) {
				continue;
			}
			$output[] = [
				'id'                 => sanitize_key( (string) $id ),
				'name'               => sanitize_text_field( (string) ( $template['name'] ?? $id ) ),
				'archived_at'        => absint( $template['archived_at'] ?? 0 ),
				'archive_expires_at' => absint( $template['archive_expires_at'] ?? 0 ),
			];
		}
		return $output;
	}

	/** @return true|\WP_Error */
	private function push_snapshot( array $state, $reason ) {
		global $wpdb;
		// MySQL limits the escaped SQL packet, not the JSON size checked below.
		$packet_limit = self::HISTORY_MAX_BYTES * 2;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$server_limit = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
			if ( $server_limit > 0 ) { $packet_limit = max( 0, $server_limit - 8192 ); }
		}
		$fits_packet = static function ( $value ) use ( $packet_limit ) {
			return strlen( addslashes( serialize( $value ) ) ) <= $packet_limit;
		};
		$storage = new AccessStatusStorage();
		$cutoff  = AccessStatusSnapshotPolicy::cleanup_cutoff();
		if ( $storage->is_active() && $cutoff > 0 ) {
			$pruned = $storage->prune_snapshots_before( $cutoff );
			if ( is_wp_error( $pruned ) ) { return $pruned; }
		}
		$history = $this->read_history();
		if ( is_wp_error( $history ) ) { return $history; }
		$history = is_array( $history ) ? array_values( $history ) : [];
		if ( ! $storage->is_active() ) {
			$history = array_values( array_filter( $history, static function ( $item ) {
				$created_at = is_array( $item ) ? absint( $item['created_at'] ?? 0 ) : 0;
				return 0 === $created_at || ! AccessStatusSnapshotPolicy::is_expired( $created_at );
			} ) );
		}
		$user    = wp_get_current_user();
		$snapshot = [
			'id'         => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'studio-', true ),
			'created_at' => time(),
			'user_id'    => get_current_user_id(),
			'user_name'  => $user && isset( $user->display_name ) ? sanitize_text_field( $user->display_name ) : '',
			'reason'     => sanitize_key( (string) $reason ),
			'hash'       => $this->hash_state( $state ),
			'state'      => $state,
		];
		$snapshot_json = wp_json_encode( $snapshot );
		if ( false === $snapshot_json || strlen( $snapshot_json ) > self::HISTORY_MAX_BYTES ) {
			return new \WP_Error( 'ma_co_acp_studio_history_too_large', __( 'حجم نسخه بازیابی بیش از حد مجاز است. برای جلوگیری از ذخیره بدون امکان بازیابی، تغییرات ذخیره نشد.', 'ma-co-access-control-pro' ) );
		}
		if ( $storage->is_active() ) {
			return $storage->push_snapshot( $snapshot, self::HISTORY_LIMIT, self::HISTORY_MAX_BYTES );
		}

		array_unshift( $history, $snapshot );
		$history = array_slice( $history, 0, self::HISTORY_LIMIT );
		while ( count( $history ) > 1 ) {
			$encoded = wp_json_encode( $history );
			if ( false !== $encoded && strlen( $encoded ) <= self::HISTORY_MAX_BYTES && $fits_packet( $history ) ) { break; }
			array_pop( $history );
		}
		$encoded = wp_json_encode( $history );
		if ( false === $encoded || strlen( $encoded ) > self::HISTORY_MAX_BYTES || ! $fits_packet( $history ) ) {
			return new \WP_Error( 'ma_co_acp_studio_history_too_large', __( 'نسخه بازیابی ذخیره نشد؛ تغییرات اعمال نشد.', 'ma-co-access-control-pro' ) );
		}

		$existing = get_option( self::HISTORY_OPTION, null );
		$saved = null === $existing
			? add_option( self::HISTORY_OPTION, $history, '', 'no' )
			: update_option( self::HISTORY_OPTION, $history, false );
		if ( ! $saved ) {
			return new \WP_Error( 'ma_co_acp_studio_history_write_failed', __( 'ساخت نسخه بازیابی ناموفق بود؛ به همین دلیل تغییرات ذخیره نشد.', 'ma-co-access-control-pro' ) );
		}
		return true;
	}

	/** @return array|null */
	private function find_snapshot( $snapshot_id ) {
		$snapshot_id = sanitize_text_field( (string) $snapshot_id );
		if ( '' === $snapshot_id ) {
			return null;
		}
		$history = $this->read_history();
		if ( is_wp_error( $history ) ) { return null; }
		foreach ( (array) $history as $snapshot ) {
			if (
				is_array( $snapshot )
				&& ! empty( $snapshot['id'] )
				&& isset( $snapshot['state'] )
				&& is_array( $snapshot['state'] )
				&& hash_equals( (string) $snapshot['id'], $snapshot_id )
			) {
				return AccessStatusSnapshotPolicy::is_expired( $snapshot['created_at'] ?? 0 ) ? null : $snapshot;
			}
		}
		return null;
	}

	private function read_history() {
		$storage = new AccessStatusStorage();
		return $storage->is_active() ? $storage->history() : get_option( self::HISTORY_OPTION, [] );
	}

	/** @return string */
	private function hash_state( array $state ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $state ) : json_encode( $state );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/** @return string */
	private function new_custom_id() {
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		return 'custom-' . trim( preg_replace( '/[^a-z0-9-]/', '-', strtolower( $uuid ) ), '-' );
	}

	/** @return array<string,mixed> */
	private function usage_record( array $post, $source ) {
		$post_id = absint( $post['ID'] ?? 0 );
		return [
			'post_id'   => $post_id,
			'title'     => sanitize_text_field( (string) ( $post['post_title'] ?? sprintf( '#%d', $post_id ) ) ),
			'post_type' => sanitize_key( (string) ( $post['post_type'] ?? 'post' ) ),
			'source'    => sanitize_key( (string) $source ),
			'edit_url'  => $post_id ? get_edit_post_link( $post_id, 'raw' ) : '',
		];
	}

	/** @return bool */
	private function elementor_data_uses_template( $node, $template_id ) {
		if ( ! is_array( $node ) ) {
			return false;
		}
		if (
			isset( $node['widgetType'], $node['settings'] )
			&& 'ma_co_acp_access_status' === $node['widgetType']
			&& is_array( $node['settings'] )
			&& $template_id === sanitize_key( (string) ( $node['settings']['template'] ?? '' ) )
		) {
			return true;
		}
		foreach ( $node as $child ) {
			if ( is_array( $child ) && $this->elementor_data_uses_template( $child, $template_id ) ) {
				return true;
			}
		}
		return false;
	}
}
