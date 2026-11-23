<?php
/**
 * Versioned template repository with legacy reads and verified table persistence.
 *
 * @package MA_CO_AccessControlPro\Features\WooCommerce
 */

namespace MA_CO_AccessControlPro\Features\WooCommerce;

use MA_CO_AccessControlPro\Database\AccessStatusStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/AccessStatusFactoryV7Trait.php';
require_once dirname( __DIR__, 2 ) . '/Database/AccessStatusStorage.php';
require_once dirname( __DIR__ ) . '/Settings/Settings/AccessStatusPublicationService.php';

final class AccessStatusTemplateRepository {
	use AccessStatusFactoryV7Trait;

	const OPTION_NAME    = 'ma_co_acp_access_status_templates';
	const SCHEMA_VERSION = 7;
	const FACTORY_REVISION = 7;
	private const CURRENT_FACTORY_REVISION = 7;
	const MAX_PAYLOAD_BYTES = 524288;

	/** @var AccessStatusTemplateSanitizer */
	private $sanitizer;

	/** @var array|null */
	private $factory_templates;

	/** @var array<string,string> Sanitized skin fingerprints for this repository. */
	private $factory_skin_css = [];

	/** @var array|null */
	private $factory_templates_revision_2;

	/** @var array|null */
	private $factory_templates_revision_3;

	/** @var array|null */
	private $factory_templates_revision_4;

	/** @var array|null */
	private $factory_templates_revision_5;

	/** @var array|null */
	private $factory_templates_revision_6;

	/** @var array|null */
	private $legacy_factory_templates;

	/** @var array|null */
	private $state_cache;

	/** @var string|null */
	private $state_cache_fingerprint;

	/**
	 * Normalized libraries shared by every repository instance in this request.
	 * Widgets and shortcodes commonly construct separate service graphs; keying
	 * the cache by the persisted option fingerprint prevents repeated full
	 * sanitization without hiding an option change made later in the request.
	 *
	 * @var array<string,array>
	 */
	private static $request_state_cache = [];
	/** @var \WP_Error|null */
	private $storage_error;

	/** @param AccessStatusTemplateSanitizer|null $sanitizer Sanitizer dependency. */
	public function __construct( AccessStatusTemplateSanitizer $sanitizer = null ) {
		$this->sanitizer = $sanitizer ?: new AccessStatusTemplateSanitizer();
	}

	/**
	 * Return normalized state. Absent legacy data uses factories; corrupt storage is reported.
	 *
	 * @return array
	 */
	public function get_state() {
		$storage = new AccessStatusStorage();
		$raw = $storage->is_active() ? $storage->read() : get_option( self::OPTION_NAME, null );
		$this->storage_error = is_wp_error( $raw ) ? $raw : null;
		if ( $this->storage_error ) { return $this->unavailable_state(); }
		$fingerprint = md5( serialize( $raw ) );
		if ( null !== $this->state_cache && $fingerprint === $this->state_cache_fingerprint ) {
			return $this->state_cache;
		}
		if ( isset( self::$request_state_cache[ $fingerprint ] ) ) {
			$this->state_cache             = self::$request_state_cache[ $fingerprint ];
			$this->state_cache_fingerprint = $fingerprint;
			return $this->state_cache;
		}
		$this->state_cache_fingerprint = $fingerprint;
		if ( ! is_array( $raw ) ) {
			if ( null !== $raw ) {
				$this->storage_error = AccessStatusStorage::error( 'invalid', __( 'داده کتابخانه معتبر نیست؛ ذخیره متوقف شد.', 'ma-co-access-control-pro' ) );
				return $this->unavailable_state();
			}
			$this->state_cache = $this->get_factory_state();
			self::$request_state_cache[ $fingerprint ] = $this->state_cache;
			return $this->state_cache;
		}

		$stored_schema = absint( $raw['schema_version'] ?? 0 );
		if ( ! in_array( $stored_schema, [ 1, 2, 3, 4, 5, 6, self::SCHEMA_VERSION ], true ) || ! is_array( $raw['templates'] ?? null ) ) {
			$this->storage_error = AccessStatusStorage::error( 'schema', __( 'نسخه داده کتابخانه پشتیبانی نمی‌شود؛ داده اصلی حفظ شد.', 'ma-co-access-control-pro' ) );
			return $this->unavailable_state();
		}

		$clean = $this->sanitize_payload( $raw );
		if ( is_wp_error( $clean ) ) {
			$this->storage_error = $clean;
			return $this->unavailable_state();
		}

		// Reads hydrate legacy schemas in memory only; DatabaseManager owns migration.

		$this->state_cache = $clean;
		self::$request_state_cache[ $this->state_cache_fingerprint ] = $clean;
		return $this->state_cache;
	}

	/**
	 * Compatibility entry point: verify state; SQL migration belongs to DatabaseManager.
	 *
	 * @return true|\WP_Error
	 */
	public function migrate_schema_if_needed() {
		$this->get_state();
		if ( $this->storage_error ) { return $this->storage_error; }
		if ( ( new AccessStatusStorage() )->is_active() ) { return true; }
		return new \WP_Error(
			'ma_co_acp_template_migration_pending',
			__( 'انتقال کتابخانه باید از مسیر ارتقای دیتابیس اجرا شود؛ اطلاعات قبلی حفظ شده‌اند.', 'ma-co-access-control-pro' )
		);
	}

	/**
	 * Return immutable canonical factory records used by reset actions.
	 *
	 * @return array
	 */
	public function get_factory_templates() {
		if ( null !== $this->factory_templates ) {
			return $this->factory_templates;
		}

		$this->factory_templates = $this->build_factory_v7_templates();

		return $this->factory_templates;
	}

	/**
	 * Identify a retained factory skin for conversion without changing ownership.
	 * Copies deliberately lose factory_id during sanitization. Their unchanged
	 * source CSS still needs the same structural mapping as the original design.
	 * Additional author rules remain in the CSS delta and retain their priority.
	 *
	 * @return array Factory record, or an empty array for an unrelated custom skin.
	 */
	public function get_template_skin_factory( array $template ) {
		$factories = $this->get_factory_templates();
		$id = $template['factory_id'] ?? '';
		if ( isset( $factories[ $id ] ) ) { return $factories[ $id ]; }
		$source = trim( $template['code']['css'] ?? '' );
		if ( '' === $source ) { return []; }
		foreach ( $factories as $factory ) {
			$skin = trim( $factory['code']['css'] ?? '' );
			if ( '' !== $skin && 0 === strpos( $source, $skin ) ) { return $factory; }
			if ( ! isset( $this->factory_skin_css[ $factory['id'] ] ) ) {
				$clean = $this->sanitizer->sanitize_template( $factory, $factory['id'], 'factory', $factory['id'] );
				$this->factory_skin_css[ $factory['id'] ] = is_wp_error( $clean ) ? '' : trim( $clean['code']['css'] ?? '' );
			}
			$skin = $this->factory_skin_css[ $factory['id'] ];
			if ( '' !== $skin && 0 === strpos( $source, $skin ) ) { return $factory; }
		}
		return [];
	}

	private function get_factory_templates_revision_4() {
		if ( null !== $this->factory_templates_revision_4 ) {
			return $this->factory_templates_revision_4;
		}

		$classic  = [
			'id'         => 'classic',
			'name'       => __( 'کلاسیک', 'ma-co-access-control-pro' ),
			'origin'     => 'factory',
			'factory_id' => 'classic',
			'factory_revision' => self::CURRENT_FACTORY_REVISION,
			'mode'       => 'blocks',
			'blocks'     => [
				'product' => [
					$this->block( 'product-title', 'product_title' ),
					$this->block( 'status-badge', 'status_badge' ),
					$this->block( 'purchase-date', 'purchase_date', [ 'label' => __( 'تاریخ خرید', 'ma-co-access-control-pro' ) ] ),
					$this->block( 'expiry-date', 'expiry_date', [ 'label' => __( 'انقضا', 'ma-co-access-control-pro' ) ] ),
					$this->block( 'remaining-time', 'remaining_time', [ 'label' => __( 'باقی‌مانده', 'ma-co-access-control-pro' ) ] ),
				],
				'message' => [ $this->block( 'message', 'message' ) ],
			],
			'code'       => $this->classic_code(),
			'styles'     => $this->empty_responsive_styles(),
			'overrides'  => [],
			'states'     => [],
			'orientations' => $this->factory_orientations( 'classic', false ),
			'default_orientation' => [],
			'info_columns' => [],
		];

		$this->factory_templates_revision_4 = [
			'classic' => $classic,
			'atlas'   => $this->designer_factory(
				'atlas',
				__( 'اطلس (Atlas)', 'ma-co-access-control-pro' ),
				false,
				[
					'desktop' => [
						'background_color' => '#0f1d33', 'text_color' => '#f8fafc', 'muted_color' => '#9fb0c8',
						'accent_color' => '#d7b25e', 'border_color' => '#293b57', 'border_width' => '1px',
						'border_radius' => '22px', 'padding' => '24px', 'gap' => '14px',
						'box_shadow' => '0 20px 46px rgba(15,29,51,.24)', 'button_background' => '#d7b25e', 'button_color' => '#162238',
					],
					'tablet' => [ 'padding' => '20px' ],
					'mobile' => [ 'padding' => '16px', 'border_radius' => '16px' ],
				],
				'.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;overflow:hidden;border-inline-start-width:5px;border-inline-start-color:#d7b25e}.ma-co-acp-access-status__head{border-bottom:1px solid #314461;padding-bottom:12px}.ma-co-acp-access-status__block--status_badge{background:#263b58;color:#f0cf86}.ma-co-acp-access-status__block--message{color:#d5dfec}.ma-co-acp-access-status__meta div{display:block;border:1px solid #2d405d;border-radius:12px;background:#142641;padding:9px 11px}.ma-co-acp-access-status__meta dd{text-align:start;color:inherit}.ma-co-acp-access-status__block--button{margin-top:4px;border-radius:10px;font-weight:800}.is-active .ma-co-acp-access-status__block--status_badge,.is-unlimited .ma-co-acp-access-status__block--status_badge{background:#173f35;color:#8ce3bd}.is-expired .ma-co-acp-access-status__block--status_badge{background:#4c2630;color:#ffb2be}'
			),
			'aurora'  => $this->designer_factory(
				'aurora',
				__( 'شفق (Aurora)', 'ma-co-access-control-pro' ),
				true,
				[
					'desktop' => [
						'background_color' => '#ffffff', 'text_color' => '#172033', 'muted_color' => '#667085',
						'background_gradient' => 'linear-gradient(145deg,#ffffff 0%,#faf8ff 52%,#f0f9ff 100%)',
						'accent_color' => '#5b5bd6', 'border_color' => '#e4e7ec', 'border_width' => '1px',
						'border_radius' => '24px', 'padding' => '22px', 'gap' => '14px',
						'box_shadow' => '0 18px 46px rgba(91,91,214,.14)', 'image_size' => '86px',
						'button_background' => '#5b5bd6', 'button_color' => '#ffffff',
					],
					'tablet' => [ 'padding' => '18px' ],
					'mobile' => [ 'padding' => '14px', 'border_radius' => '17px', 'image_size' => '68px' ],
				],
				'.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;overflow:hidden;border-top-width:6px;border-top-color:#8b5cf6}.ma-co-acp-access-status__block--image{border-radius:18px;box-shadow:0 8px 20px #6366f12e}.ma-co-acp-access-status__head{align-content:center;justify-content:flex-start;flex-wrap:wrap}.ma-co-acp-access-status__block--status_badge{background:#ede9fe;color:#6d28d9}.ma-co-acp-access-status__block--message{color:#556176}.ma-co-acp-access-status__meta{padding-top:5px}.ma-co-acp-access-status__meta div{border-radius:12px;background:#ffffffb8;padding:7px 10px}.ma-co-acp-access-status__block--button{margin-top:4px;border-radius:999px;box-shadow:0 8px 18px #5b5bd63d}'
			),
			'pass'    => $this->designer_factory(
				'pass',
				__( 'کارت عبور (Pass)', 'ma-co-access-control-pro' ),
				false,
				[
					'desktop' => [
						'background_color' => '#fffaf0', 'text_color' => '#352b1d', 'muted_color' => '#81725e',
						'background_gradient' => 'linear-gradient(90deg,#fffaf0,#fffdf8)',
						'accent_color' => '#9a5d12', 'border_color' => '#c9ab7d', 'border_width' => '2px',
						'border_radius' => '18px', 'padding' => '22px', 'gap' => '14px',
						'box_shadow' => '0 16px 34px rgba(119,82,32,.13)', 'button_background' => '#9a5d12', 'button_color' => '#ffffff',
					],
					'tablet' => [ 'padding' => '18px' ],
					'mobile' => [ 'padding' => '14px', 'border_radius' => '13px' ],
				],
				'.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{border-style:dashed;position:relative;overflow:hidden}.ma-co-acp-access-status__head{border-bottom:2px dashed #c9ab7d;padding-bottom:12px}.ma-co-acp-access-status__block--status_badge{background:#f3e4c9;color:#7c4a0d}.ma-co-acp-access-status__block--message{color:#6f604d}.ma-co-acp-access-status__meta{border-bottom:2px dashed #c9ab7d;padding-bottom:13px}.ma-co-acp-access-status__meta div{display:block}.ma-co-acp-access-status__meta dd{text-align:start}.ma-co-acp-access-status__block--button{margin-top:2px;border-radius:10px;font-weight:900}'
			),
			'horizon' => $this->designer_factory(
				'horizon',
				__( 'افق (Horizon)', 'ma-co-access-control-pro' ),
				true,
				[
					'desktop' => [
						'background_color' => '#ffffff', 'text_color' => '#132238', 'muted_color' => '#6b778a',
						'accent_color' => '#0f6b78', 'border_color' => '#d8e4e8', 'border_width' => '1px',
						'border_radius' => '24px', 'padding' => '14px', 'gap' => '16px',
						'box_shadow' => '0 18px 44px rgba(15,107,120,.13)',
						'button_background' => '#0f6b78', 'button_color' => '#ffffff',
					],
					'tablet' => [ 'padding' => '12px' ],
					'mobile' => [ 'padding' => '10px', 'border_radius' => '16px' ],
				],
				'.ma-co-acp-access-status__block--image{width:100%;height:auto;border-radius:16px}.ma-co-acp-access-status__head{margin-top:6px}.ma-co-acp-access-status__block--status_badge{background:#e1f3f5;color:#0f6b78}.ma-co-acp-access-status__block--message{color:#536176}.ma-co-acp-access-status__meta{gap:6px}.ma-co-acp-access-status__meta div{border-radius:10px;background:#f3f8f9;padding:7px 9px}.ma-co-acp-access-status__block--button{margin-top:8px;border-radius:10px;font-weight:800}'
			),
			'velvet'  => $this->designer_factory(
				'velvet',
				__( 'مخمل (Velvet)', 'ma-co-access-control-pro' ),
				false,
				[
					'desktop' => [
						'background_color' => '#2e1748', 'text_color' => '#fff8ff', 'muted_color' => '#c6b6d3',
						'background_gradient' => 'radial-gradient(circle at 50% 0%,#53306d 0%,#2e1748 58%)',
						'accent_color' => '#f4a7c6', 'border_color' => '#57366f', 'border_width' => '1px',
						'border_radius' => '28px', 'padding' => '26px', 'gap' => '15px',
						'box_shadow' => '0 24px 60px rgba(62,26,88,.28)', 'button_background' => '#f4a7c6', 'button_color' => '#321844',
					],
					'tablet' => [ 'padding' => '21px' ],
					'mobile' => [ 'padding' => '16px', 'border_radius' => '18px' ],
				],
				'.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{text-align:center}.ma-co-acp-access-status__head{justify-content:center;flex-wrap:wrap}.ma-co-acp-access-status__head h2,.ma-co-acp-access-status__head h3,.ma-co-acp-access-status__head h4{width:100%;font-size:21px}.ma-co-acp-access-status__block--status_badge{border:1px solid #7b538d;background:#ffffff14;color:#ffd4e5}.ma-co-acp-access-status__block--status_icon{width:50px;height:50px;margin:auto;border:1px solid #835d94;border-radius:50%;background:#45265d;color:#f4a7c6}.ma-co-acp-access-status__block--message{color:#d8cce0}.ma-co-acp-access-status__meta{gap:8px}.ma-co-acp-access-status__meta div{display:block;border:1px solid #5d3e70;border-radius:13px;background:#ffffff0e;padding:8px}.ma-co-acp-access-status__meta dd{text-align:center;color:inherit}.ma-co-acp-access-status__block--button{margin-top:6px;border-radius:999px;font-weight:900;width:100%;max-width:170px;margin-left:auto;margin-right:auto}.is-active,.is-unlimited{border-color:#8bdfc2}.is-expired{border-color:#ff9bb5}'
			),
		];

		foreach ( $this->factory_templates_revision_4 as &$template ) {
			$template['factory_revision'] = 4;
		}
		unset( $template );

		return $this->factory_templates_revision_4;
	}

	/**
	 * Options suitable for a shortcode/widget template selector.
	 *
	 * @return array<string,string>
	 */
	public function get_template_options() {
		$options = [ 'global' => __( 'پیش‌فرض سراسری', 'ma-co-access-control-pro' ) ];
		foreach ( $this->get_published_state()['templates'] as $id => $template ) {
			if ( 'archived' === ( $template['status'] ?? 'active' ) ) {
				continue;
			}
			$options[ $id ] = sanitize_text_field( $template['name'] ?? $id );
		}
		return $options;
	}

	/**
	 * Validate a complete UI/preview payload without saving it.
	 *
	 * @param mixed $payload Array or JSON.
	 * @return array|\WP_Error
	 */
	public function sanitize_payload( $payload ) {
		return $this->sanitizer->sanitize_payload( $payload, $this->get_factory_templates(), $this->get_legacy_factory_templates(), $this->get_factory_revision_templates() );
	}

	/**
	 * Validate and persist a complete payload as a non-autoload option.
	 *
	 * @param mixed $payload Array or JSON.
	 * @return array|\WP_Error
	 */
	public function save_payload( $payload ) {
		if ( ( ( new AccessStatusStorage() )->is_active() || null !== get_option( 'ma_co_acp_db_version', null ) ) && ! \MA_CO_AccessControlPro\Features\Settings\Settings\AccessStatusPublicationService::exists() ) {
			return new \WP_Error( 'publication_migration_required', __( 'ابتدا ارتقای دیتابیس را کامل کنید؛ نسخه فعلی سایت حفظ شده است.', 'ma-co-access-control-pro' ) );
		}
		$this->get_state();
		if ( $this->storage_error ) { return $this->storage_error; }
		$clean = $this->sanitize_payload( $payload );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$stored = $this->sanitizer->compact_payload( $clean, $this->get_factory_templates(), $this->get_legacy_factory_templates(), $this->get_factory_revision_templates() );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$storage = new AccessStatusStorage();
		$old = get_option( self::OPTION_NAME, null );
		if ( $storage->is_active() ) {
			$saved = $storage->write( $stored );
			if ( is_wp_error( $saved ) ) { return $saved; }
		} elseif ( null === $old ) {
			$saved = add_option( self::OPTION_NAME, $stored, '', 'no' );
		} else {
			$saved = update_option( self::OPTION_NAME, $stored, false );
			if ( ! $saved && $old === $stored ) {
				$saved = true;
			}
		}

		if ( ! $saved ) {
			return new \WP_Error( 'ma_co_acp_template_save_failed', __( 'ذخیره تمپلیت‌های وضعیت انجام نشد؛ دوباره تلاش کنید.', 'ma-co-access-control-pro' ) );
		}

		$this->state_cache = $clean;
		$this->state_cache_fingerprint = md5( serialize( $stored ) );
		self::$request_state_cache[ $this->state_cache_fingerprint ] = $clean;
		return $clean;
	}

	/** Compact without writing or normalizing the source option during migration. */
	public function compact_for_storage( $payload ) {
		$clean = $this->sanitize_payload( $payload );
		return is_wp_error( $clean ) ? $clean : $this->sanitizer->compact_payload( $clean, $this->get_factory_templates(), $this->get_legacy_factory_templates(), $this->get_factory_revision_templates() );
	}

	/** @return \WP_Error|null */
	public function get_storage_error() { return $this->storage_error; }

	private function unavailable_state() {
		$this->state_cache = null;
		$this->state_cache_fingerprint = null;
		return [ 'schema_version' => self::SCHEMA_VERSION, 'factory_revision' => self::FACTORY_REVISION,
			'default_template' => '', 'shared_base' => [], 'template_visibility' => [], 'templates' => [] ];
	}

	/**
	 * Resolve requested/global/factory fallback against saved or unsaved state.
	 *
	 * @param string $requested Requested ID or global.
	 * @param array  $state Optional sanitized/unsaved state.
	 * @return array
	 */
	public function resolve_template( $requested = 'global', array $state = [] ) {
		if ( empty( $state ) ) {
			$state = $this->get_published_state();
			if ( $this->storage_error ) { return []; }
		} else {
			$clean = $this->sanitize_payload( $state );
			$state = is_wp_error( $clean ) ? $this->get_factory_state() : $clean;
		}

		return $this->resolve_state( $requested, $state );
	}

	/** Saved working state is only for Studio and authorized previews. */
	public function get_working_state() { return $this->get_state(); }

	public function resolve_working_template( $requested = 'global' ) {
		$state = $this->get_working_state();
		return $this->storage_error ? [] : $this->resolve_state( $requested, $state );
	}

	/** Verified full records are deliberately not rehydrated against newer factories. */
	public function get_published_state() {
		$publication = new \MA_CO_AccessControlPro\Features\Settings\Settings\AccessStatusPublicationService( $this );
		if ( ! $publication::exists() ) {
			if ( version_compare( (string) get_option( 'ma_co_acp_db_version', '0' ), '2.2.0', '>=' ) ) {
				$this->storage_error = new \WP_Error( 'release_missing', __( 'مرجع انتشار موجود نیست؛ پیش‌نویس جایگزین خروجی سایت نمی‌شود.', 'ma-co-access-control-pro' ) );
				return $this->unavailable_state();
			}
			return $this->get_state();
		}
		$state = $publication->read();
		$this->storage_error = is_wp_error( $state ) ? $state : null;
		return $this->storage_error ? $this->unavailable_state() : $state;
	}

	private function resolve_state( $requested, array $state ) {
		$requested = sanitize_key( (string) $requested );
		if ( '' === $requested || 'global' === $requested || ! isset( $state['templates'][ $requested ] ) ) {
			$requested = sanitize_key( $state['default_template'] ?? 'classic' );
		}
		if ( ! isset( $state['templates'][ $requested ] ) ) {
			$requested = 'classic';
		}

		$template   = $state['templates'][ $requested ] ?? $this->get_factory_templates()['classic'];
		$shared     = isset( $state['shared_base'] ) && is_array( $state['shared_base'] ) ? $state['shared_base'] : [];
		if ( isset( $template['foundation_snapshot'] ) ) {
			$shared = $template['foundation_snapshot']['shared_base'];
			$template = $this->deep_merge( $template, $template['foundation_snapshot']['base'] );
		}
		$overrides  = isset( $template['overrides'] ) && is_array( $template['overrides'] ) ? $template['overrides'] : [];
		$states     = isset( $template['states'] ) && is_array( $template['states'] ) ? $template['states'] : [];
		$orientation = isset( $template['orientations'] ) && is_array( $template['orientations'] ) ? $template['orientations'] : [];

		$resolved_shared = $shared;
		if ( array_key_exists( 'state_content', $template ) ) { $resolved_shared['state_content'] = $template['state_content']; }
		$resolved_shared['content'] = isset( $shared['content'] ) && is_array( $shared['content'] ) ? $shared['content'] : [ 'messages' => [], 'labels' => [], 'visibility' => [] ];
		$resolved_shared = $this->deep_merge( $resolved_shared, $overrides['local_base'] ?? [] );
		$resolved_shared['content']['visibility'] = isset( $state['template_visibility'][ $requested ] ) && is_array( $state['template_visibility'][ $requested ] ) ? $state['template_visibility'][ $requested ] : [];

		$template = $this->apply_shared_base( $template, $resolved_shared );
		$template = $this->deep_merge( $template, $overrides );
		$template['content']          = $resolved_shared['content'];
		$template['state_content']    = $resolved_shared['state_content'] ?? [];
		$template['overrides']        = $overrides;
		$template['states']           = $states;
		$template['orientations']     = $orientation;
		$template['_layers_resolved'] = true;

		return $template;
	}

	/** @return AccessStatusTemplateSanitizer */
	public function get_sanitizer() {
		return $this->sanitizer;
	}

	/**
	 * Return a deterministic revision token for optimistic concurrency checks.
	 *
	 * @param array $state Optional normalized or unsaved library state.
	 * @return string
	 */
	public function get_state_hash( array $state = [] ) {
		if ( empty( $state ) ) {
			$state = $this->get_state();
		} else {
			$clean = $this->sanitize_payload( $state );
			$state = is_wp_error( $clean ) ? $this->get_factory_state() : $clean;
		}

		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: json_encode( $state );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : serialize( $state ) );
	}

	/** @return array<int,array> */
	private function get_factory_revision_templates() {
		return [
			2 => $this->get_factory_templates_revision_2(),
			3 => $this->get_factory_templates_revision_3(),
			4 => $this->get_factory_templates_revision_4(),
			5 => $this->get_factory_templates_revision_5(),
			6 => $this->get_factory_templates_revision_6(),
		];
	}

	/** @return array */
	private function get_factory_state() {
		return [
			'schema_version'   => self::SCHEMA_VERSION,
			'factory_revision' => self::CURRENT_FACTORY_REVISION,
			'default_template' => 'classic',
			'shared_base'         => $this->sanitizer->sanitize_shared_base( [] ),
			'template_visibility' => [],
			'templates'        => $this->get_factory_templates(),
		];
	}

	/** @return array */
	private function get_default_state_messages() {
		return [
			'guest' => [ 'message' => __( 'برای دیدن وضعیت خرید و اعتبارتان وارد حساب کاربری شوید.', 'ma-co-access-control-pro' ) ],
			'empty' => [ 'message' => __( 'در حال حاضر وضعیت خریدی برای نمایش وجود ندارد.', 'ma-co-access-control-pro' ) ],
			'inactive' => [ 'message' => __( 'امکان بررسی وضعیت خرید در حال حاضر وجود ندارد.', 'ma-co-access-control-pro' ) ],
		];
	}

	/**
	 * Return the immutable revision-3 factory snapshot used to migrate complete
	 * builder payloads created before semantic code markers were introduced.
	 *
	 * @return array
	 */
	private function get_factory_templates_revision_3() {
		if ( null !== $this->factory_templates_revision_3 ) {
			return $this->factory_templates_revision_3;
		}

		$snapshot = $this->get_factory_templates_revision_4();
		foreach ( $snapshot as $id => &$template ) {
			$template['factory_revision'] = 3;
			if ( ! empty( $template['code']['product_html'] ) ) {
				$template['code']['product_html'] = preg_replace(
					'/<div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--(?:purchase_date|expiry_date|remaining_time|duration_days)">/',
					'<div>',
					$template['code']['product_html']
				);
			}
		}
		unset( $template );

		$this->factory_templates_revision_3 = $snapshot;
		return $this->factory_templates_revision_3;
	}

	/**
	 * Immutable revision-5 snapshot used to preserve sparse user deltas while
	 * revision 6 introduces behavior and progress blocks.
	 *
	 * @return array
	 */
	private function get_factory_templates_revision_5() {
		if ( null !== $this->factory_templates_revision_5 ) {
			return $this->factory_templates_revision_5;
		}

		$snapshot = $this->get_factory_templates_revision_4();
		foreach ( $snapshot as $id => &$template ) {
			$template = $this->upgrade_factory_to_revision_5( $id, $template );
		}
		unset( $template );

		$this->factory_templates_revision_5 = $snapshot;
		return $this->factory_templates_revision_5;
	}

	/** Immutable pre-redesign baseline for full and sparse revision-6 payloads. */
	private function get_factory_templates_revision_6() {
		if ( null === $this->factory_templates_revision_6 ) {
			$this->factory_templates_revision_6 = $this->get_factory_templates_revision_5();
			foreach ( $this->factory_templates_revision_6 as $id => &$template ) {
				$template = $this->upgrade_factory_to_revision_6( $id, $template );
			}
			unset( $template );
		}
		return $this->factory_templates_revision_6;
	}

	/**
	 * Return the immutable revision-2 factory snapshot used only to compute
	 * deltas from the first schema-v2 builder payloads. Runtime/reset always
	 * use the latest factory revision.
	 *
	 * @return array
	 */
	private function get_factory_templates_revision_2() {
		if ( null !== $this->factory_templates_revision_2 ) {
			return $this->factory_templates_revision_2;
		}

		$snapshot = $this->get_factory_templates_revision_3();
		$css = [
			'atlas' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;overflow:hidden;border-inline-start-width:5px;border-inline-start-color:#d7b25e}.ma-co-acp-access-status__head{border-bottom:1px solid #314461;padding-bottom:12px}.ma-co-acp-access-status__block--status_badge{background:#263b58;color:#f0cf86}.ma-co-acp-access-status__block--message{color:#d5dfec}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr}.ma-co-acp-access-status__meta div{display:block;border:1px solid #2d405d;border-radius:12px;background:#142641;padding:9px 11px}.ma-co-acp-access-status__meta dd{text-align:start;color:inherit}.ma-co-acp-access-status__block--button{margin-top:4px;border-radius:10px;font-weight:800}.is-active .ma-co-acp-access-status__block--status_badge,.is-unlimited .ma-co-acp-access-status__block--status_badge{background:#173f35;color:#8ce3bd}.is-expired .ma-co-acp-access-status__block--status_badge{background:#4c2630;color:#ffb2be}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}',
			'aurora' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;overflow:hidden;border-top-width:6px;border-top-color:#8b5cf6}.ma-co-acp-access-status__block--image{border-radius:18px;box-shadow:0 8px 20px #6366f12e}.ma-co-acp-access-status__head{align-content:center;justify-content:flex-start;flex-wrap:wrap}.ma-co-acp-access-status__block--status_badge{background:#ede9fe;color:#6d28d9}.ma-co-acp-access-status__block--message{color:#556176}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;padding-top:5px}.ma-co-acp-access-status__meta div{border-radius:12px;background:#ffffffb8;padding:7px 10px}.ma-co-acp-access-status__block--button{margin-top:4px;border-radius:999px;box-shadow:0 8px 18px #5b5bd63d}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}',
			'pass' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{border-style:dashed;position:relative;overflow:hidden}.ma-co-acp-access-status__head{border-bottom:2px dashed #c9ab7d;padding-bottom:12px}.ma-co-acp-access-status__block--status_badge{background:#f3e4c9;color:#7c4a0d}.ma-co-acp-access-status__block--message{color:#6f604d}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;border-bottom:2px dashed #c9ab7d;padding-bottom:13px}.ma-co-acp-access-status__meta div{display:block}.ma-co-acp-access-status__meta dd{text-align:start}.ma-co-acp-access-status__block--button{margin-top:2px;border-radius:10px;font-weight:900}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}',
			'horizon' => '.ma-co-acp-access-status__block--image{width:100%;height:auto;border-radius:16px}.ma-co-acp-access-status__item.is-product-image-missing{padding:24px}.ma-co-acp-access-status__head{margin-top:6px}.ma-co-acp-access-status__block--status_badge{background:#e1f3f5;color:#0f6b78}.ma-co-acp-access-status__block--message{color:#536176}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;gap:6px}.ma-co-acp-access-status__meta div{border-radius:10px;background:#f3f8f9;padding:7px 9px}.ma-co-acp-access-status__block--button{margin-top:8px;border-radius:10px;font-weight:800}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}',
			'velvet' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{text-align:center}.ma-co-acp-access-status__head{justify-content:center;flex-wrap:wrap}.ma-co-acp-access-status__head h2,.ma-co-acp-access-status__head h3,.ma-co-acp-access-status__head h4{width:100%;font-size:21px}.ma-co-acp-access-status__block--status_badge{border:1px solid #7b538d;background:#ffffff14;color:#ffd4e5}.ma-co-acp-access-status__block--status_icon{width:50px;height:50px;margin:auto;border:1px solid #835d94;border-radius:50%;background:#45265d;color:#f4a7c6}.ma-co-acp-access-status__block--message{color:#d8cce0}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;gap:8px}.ma-co-acp-access-status__meta div{display:block;border:1px solid #5d3e70;border-radius:13px;background:#ffffff0e;padding:8px}.ma-co-acp-access-status__meta dd{text-align:center;color:inherit}.ma-co-acp-access-status__block--button{margin-top:6px;border-radius:999px;font-weight:900;width:100%;max-width:170px;margin-left:auto;margin-right:auto}.is-active,.is-unlimited{border-color:#8bdfc2}.is-expired{border-color:#ff9bb5}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}',
		];
		foreach ( $snapshot as $id => &$template ) {
			$template['factory_revision'] = 2;
			unset( $template['default_orientation'], $template['info_columns'] );
			if ( isset( $css[ $id ] ) ) {
				$template['code']['css'] = $css[ $id ];
			}
		}
		unset( $template );

		$this->factory_templates_revision_2 = $snapshot;
		return $this->factory_templates_revision_2;
	}

	/**
	 * Return the revision-1 factory snapshot solely for an accurate one-time
	 * delta migration. It is never exposed as a runtime/reset catalogue.
	 *
	 * @return array
	 */
	private function get_legacy_factory_templates() {
		if ( null !== $this->legacy_factory_templates ) {
			return $this->legacy_factory_templates;
		}

		$legacy = $this->get_factory_templates_revision_2();
		foreach ( $legacy as $id => &$template ) {
			unset( $template['factory_revision'], $template['overrides'], $template['orientations'] );
			unset( $template['styles']['desktop']['background_gradient'] );
			$template['states'] = $this->get_default_state_messages();
		}
		unset( $template );

		$legacy['atlas']['code']['css'] = $this->legacy_atlas_css();
		$legacy['classic']['code']          = $this->legacy_classic_code();
		foreach ( [ 'atlas', 'aurora', 'pass', 'horizon', 'velvet' ] as $designer_id ) {
			$legacy_code = $this->legacy_factory_code( in_array( $designer_id, [ 'aurora', 'horizon' ], true ) );
			$legacy[ $designer_id ]['code']['product_html'] = $legacy_code['product_html'];
			$legacy[ $designer_id ]['code']['message_html'] = $legacy_code['message_html'];
		}
		$legacy['aurora']['code']['css']    = $this->legacy_aurora_css();
		$legacy['pass']['code']['css']      = $this->legacy_pass_css();
		$legacy['horizon']['code']['css']   = $this->legacy_horizon_css();
		$legacy['velvet']['code']['css']    = $this->legacy_velvet_css();
		$legacy['horizon']['styles']['desktop']['image_size'] = '100%';

		$this->legacy_factory_templates = $legacy;
		return $this->legacy_factory_templates;
	}

	/**
	 * Materialize shared style/content defaults before applying the explicit
	 * template delta. This keeps the merge order deterministic for renderers.
	 *
	 * @return array
	 */
	private function apply_shared_base( array $template, array $shared ) {
		if ( isset( $shared['styles'] ) && is_array( $shared['styles'] ) ) {
			$template['styles'] = $this->deep_merge(
				isset( $template['styles'] ) && is_array( $template['styles'] ) ? $template['styles'] : [],
				$shared['styles']
			);
		}
		$template['behavior'] = $this->sanitizer->sanitize_behavior( isset( $shared['behavior'] ) ? $shared['behavior'] : [] );

		$content = isset( $shared['content'] ) && is_array( $shared['content'] ) ? $shared['content'] : [];
		$labels  = isset( $content['labels'] ) && is_array( $content['labels'] ) ? $content['labels'] : [];
		$visible = isset( $content['visibility'] ) && is_array( $content['visibility'] ) ? $content['visibility'] : [];
		if ( empty( $labels ) && empty( $visible ) ) {
			return $template;
		}

		foreach ( [ 'product', 'message' ] as $canvas ) {
			if ( empty( $template['blocks'][ $canvas ] ) || ! is_array( $template['blocks'][ $canvas ] ) ) {
				continue;
			}
			foreach ( $template['blocks'][ $canvas ] as &$block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$semantic = AccessStatusComponentRegistry::get_block_semantic_key( $block, $canvas );
				if ( '' === $semantic ) {
					continue;
				}
				if ( array_key_exists( $semantic, $visible ) ) {
					$block['enabled'] = (bool) $visible[ $semantic ];
				}
				if ( ! array_key_exists( $semantic, $labels ) ) {
					continue;
				}
				if ( ! isset( $block['settings'] ) || ! is_array( $block['settings'] ) ) {
					$block['settings'] = [];
				}
				if ( 'message_title' === $semantic ) {
					$block['settings']['html'] = '<strong class="ma-co-acp-access-status__message-title">' . ( ! empty( $shared['state_content']['message_title'] ) ? '{message_title}' : esc_html( $labels[ $semantic ] ) ) . '</strong>';
				} else {
					$block['settings']['label'] = $labels[ $semantic ];
				}
			}
			unset( $block );
		}

		return $template;
	}


	/** @return array */
	private function deep_merge( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && empty( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! $this->is_list( $base[ $key ] ) ) {
				// Empty responsive/content maps are sparse no-ops. Empty numeric block
				// lists still intentionally replace a canvas.
				continue;
			}
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! $this->is_list( $value ) ) {
				$base[ $key ] = $this->deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/** @return bool */
	private function is_list( array $array ) {
		return empty( $array ) || array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * Upgrade the immutable revision-4 factory snapshot into the revision-5
	 * marketplace design system. Layout archetypes differ structurally while
	 * continuing to use the renderer's shared semantic block contract.
	 *
	 * @param string $id Factory ID.
	 * @param array  $template Revision-4 canonical template.
	 * @return array
	 */
	private function upgrade_factory_to_revision_5( $id, array $template ) {
		// This method materializes the immutable historical snapshot used as the
		// migration baseline. Tying it to CURRENT_FACTORY_REVISION makes a future
		// factory bump silently relabel the snapshot and causes persisted v5
		// customizations to be diffed against the wrong design.
		$template['factory_revision'] = 5;
		$template['blocks']['product'] = $this->factory_v5_product_blocks( $id );
		$template['blocks']['message'] = $this->factory_v5_message_blocks( $id );
		$template['styles'] = $this->factory_v5_styles( $id );
		$template['code']['css'] = $this->factory_v5_css( $id );
		$template['default_orientation'] = in_array( $id, [ 'aurora', 'horizon' ], true )
			? [ 'desktop' => 'horizontal', 'tablet' => 'horizontal', 'mobile' => 'vertical' ]
			: [];
		$template['info_columns'] = [ 'desktop' => 2, 'tablet' => 2, 'mobile' => 1 ];
		return $template;
	}

	/**
	 * Promote the six marketplace archetypes to schema revision 6. The visual
	 * identity from revision 5 remains intact while progress, urgency and smart
	 * actions become part of the canonical semantic contract.
	 *
	 * @param string $id Factory ID.
	 * @param array  $template Revision-5 template.
	 * @return array
	 */
	private function upgrade_factory_to_revision_6( $id, array $template ) {
		$template['factory_revision'] = 6;
		$template['status'] = 'active';
		$template['archived_at'] = 0;
		$template['archive_expires_at'] = 0;
		// Canonical factory records are also a public reset/catalogue surface.
		// Expose the presentation defaults here even though resolved templates
		// ultimately receive the centrally configured shared behavior.
		$template['behavior'] = AccessStatusTemplateSanitizer::get_default_behavior();
		// Login routing was added after this immutable migration baseline.
		unset( $template['behavior']['login_source'], $template['behavior']['login_url'] );
		$template['blocks']['product'] = $this->factory_v6_product_blocks( $id );
		$template['blocks']['message'] = $this->factory_v6_message_blocks( $id );

		$include_image = in_array( $id, [ 'aurora', 'horizon' ], true );
		$code = $this->factory_v6_code( $include_image );
		$code['css'] = $this->factory_v5_css( $id ) . $this->factory_v6_css( $id );
		$template['code'] = $code;
		return $template;
	}

	/** @return array */
	private function factory_v6_product_blocks( $id ) {
		$blocks   = $this->factory_v5_product_blocks( $id );
		$progress = $this->block(
			'access-progress',
			'access_progress',
			[
				'label'      => __( 'اعتبار باقی‌مانده', 'ma-co-access-control-pro' ),
				'show_label' => true,
				'show_value' => true,
				'aria_label' => __( 'درصد اعتبار باقی‌مانده', 'ma-co-access-control-pro' ),
				'class_name' => 'ma-co-acp-template-progress',
			]
		);
		$inserted = false;
		foreach ( $blocks as $index => $block ) {
			if ( 'button' !== ( $block['type'] ?? '' ) ) {
				continue;
			}
			$blocks[ $index ]['settings']['url_source'] = 'smart';
			$blocks[ $index ]['settings']['label'] = '{action_label}';
			array_splice( $blocks, $index, 0, [ $progress ] );
			$inserted = true;
			break;
		}
		if ( ! $inserted ) {
			$blocks[] = $progress;
		}
		return $blocks;
	}

	/** @return array */
	private function factory_v6_message_blocks( $id ) {
		$blocks = $this->factory_v5_message_blocks( $id );
		foreach ( $blocks as &$block ) {
			if ( 'button' === ( $block['type'] ?? '' ) ) {
				$block['settings']['url_source'] = 'smart';
				$block['settings']['label'] = '{action_label}';
			}
		}
		unset( $block );
		return $blocks;
	}

	/** @return array */
	private function factory_v6_code( $include_image ) {
		$image = $include_image
			? '<img class="ma-co-acp-access-status__block ma-co-acp-access-status__block--image" src="{product_image_url}" alt="{product_title}" loading="lazy">'
			: '';
		$progress = '<div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--access_progress"><span class="ma-co-acp-access-status__progress-label">' . esc_html__( 'اعتبار باقی‌مانده', 'ma-co-access-control-pro' ) . '<strong>{remaining_percent}%</strong></span><progress max="100" value="{remaining_percent}" aria-label="' . __( 'درصد اعتبار باقی‌مانده', 'ma-co-access-control-pro' ) . '">{remaining_percent}%</progress></div>';
		return [
			'product_html' => $image . '<div class="ma-co-acp-access-status__head"><h3 class="ma-co-acp-access-status__block ma-co-acp-access-status__block--product_title">{product_title}</h3><span class="ma-co-acp-access-status__block ma-co-acp-access-status__block--status_badge">{status_label}</span></div><p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message">{message}</p>' . $progress . '<dl class="ma-co-acp-access-status__meta"><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--purchase_date"><dt>{purchase_date_label}</dt><dd>{purchase_date}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--expiry_date"><dt>{expiry_date_label}</dt><dd>{expiry_date}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--remaining_time"><dt>{remaining_time_label}</dt><dd>{remaining_time}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--duration_days"><dt>{duration_days_label}</dt><dd>{duration_days}</dd></div></dl><a class="ma-co-acp-access-status__block ma-co-acp-access-status__block--button" href="{action_url}">{action_label}</a>',
			'message_html' => '<div class="ma-co-acp-access-status__message"><strong class="ma-co-acp-access-status__message-title">{message_title}</strong><p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message">{message}</p><a class="ma-co-acp-access-status__block ma-co-acp-access-status__block--button" href="{action_url}">{action_label}</a></div>',
			'css' => '',
		];
	}

	/** @return string */
	private function factory_v6_css( $id ) {
		$tokens = $this->factory_v5_design_tokens( $id );
		return '.ma-co-acp-access-status__item{--ma-co-acp-progress-color:' . $tokens['accent'] . '}'
			. '.ma-co-acp-access-status__block--access_progress{display:grid;gap:7px}'
			. '.ma-co-acp-access-status__progress-label{display:flex;align-items:center;justify-content:space-between;gap:12px;color:' . $tokens['muted'] . ';font-size:12px}'
			. '.ma-co-acp-access-status__progress-label strong{color:' . $tokens['text'] . ';font-size:13px}'
			. '.ma-co-acp-access-status__block--access_progress progress{accent-color:' . $tokens['accent'] . '}'
			. '.ma-co-acp-access-status__item.is-expiring-soon{border-color:' . $tokens['accent'] . '}'
			. '.ma-co-acp-access-status__item.is-expiring-soon .ma-co-acp-access-status__block--status_badge{background:' . $tokens['highlight_bg'] . ';color:' . $tokens['highlight_text'] . ';border-color:' . $tokens['highlight_border'] . '}';
	}

	/** @return array */
	private function factory_v5_product_blocks( $id ) {
		$title = $this->block( 'product-title', 'product_title', [ 'tag' => 'h3' ] );
		$icon = $this->block( 'status-icon', 'status_icon', [ 'aria_label' => __( 'وضعیت {status_label}', 'ma-co-access-control-pro' ), 'size' => '28px' ] );
		$badge = $this->block( 'status-badge', 'status_badge' );
		$message = $this->block( 'message', 'message', [ 'text' => '{message}' ] );
		$purchase = $this->block( 'purchase-date', 'purchase_date', [ 'label' => __( 'تاریخ خرید', 'ma-co-access-control-pro' ) ] );
		$expiry = $this->block( 'expiry-date', 'expiry_date', [ 'label' => __( 'انقضا', 'ma-co-access-control-pro' ) ] );
		$remaining = $this->block( 'remaining-time', 'remaining_time', [ 'label' => __( 'باقی‌مانده', 'ma-co-access-control-pro' ) ] );
		$duration = $this->block( 'duration-days', 'duration_days', [ 'label' => __( 'مدت اعتبار', 'ma-co-access-control-pro' ) ] );
		$button = $this->block( 'action', 'button', [ 'label' => __( 'مشاهده محصول', 'ma-co-access-control-pro' ), 'url_source' => 'product', 'class_name' => 'ma-co-acp-template-action' ] );
		$divider = static function ( $suffix ) {
			return [ 'id' => 'divider-' . $suffix, 'type' => 'divider', 'enabled' => true, 'settings' => [ 'class_name' => 'ma-co-acp-template-divider' ] ];
		};
		$image = $this->block( 'product-image', 'image', [ 'source' => 'product', 'alt' => '{product_title}', 'class_name' => 'ma-co-acp-template-image' ] );

		switch ( $id ) {
			case 'atlas':
				$remaining['settings']['class_name'] = 'ma-co-acp-atlas-primary';
				return [ $icon, $title, $badge, $message, $remaining, $expiry, $purchase, $duration, $button ];
			case 'aurora':
				return [ $image, $icon, $title, $badge, $message, $remaining, $expiry, $purchase, $duration, $button ];
			case 'pass':
				return [ $icon, $title, $badge, $divider( 'pass-head' ), $purchase, $expiry, $remaining, $duration, $divider( 'pass-meta' ), $message, $button ];
			case 'horizon':
				$remaining['settings']['class_name'] = 'ma-co-acp-horizon-primary';
				return [ $image, $icon, $title, $badge, $remaining, $expiry, $purchase, $duration, $message, $button ];
			case 'velvet':
				$remaining['settings']['class_name'] = 'ma-co-acp-velvet-primary';
				return [ $icon, $title, $badge, $divider( 'velvet-head' ), $remaining, $expiry, $purchase, $duration, $message, $button ];
			default:
				return [ $icon, $title, $badge, $message, $purchase, $expiry, $remaining, $duration, $button ];
		}
	}

	/** @return array */
	private function factory_v5_message_blocks( $id ) {
		$icon = $this->block( 'status-icon-message', 'status_icon', [ 'aria_label' => __( 'پیام وضعیت', 'ma-co-access-control-pro' ), 'size' => '30px' ] );
		$title = $this->block( 'message-title', 'custom_html', [ 'html' => '<strong class="ma-co-acp-access-status__message-title">' . esc_html__( 'وضعیت حساب کاربری', 'ma-co-access-control-pro' ) . '</strong>' ] );
		$message = $this->block( 'message-text', 'message', [ 'text' => '{message}' ] );
		$button = $this->block( 'message-action', 'button', [ 'label' => __( 'ورود یا مشاهده حساب', 'ma-co-access-control-pro' ), 'url_source' => 'account', 'class_name' => 'ma-co-acp-template-action' ] );
		$divider = [ 'id' => 'message-divider', 'type' => 'divider', 'enabled' => true, 'settings' => [ 'class_name' => 'ma-co-acp-template-divider' ] ];
		if ( in_array( $id, [ 'atlas', 'pass', 'velvet' ], true ) ) {
			return [ $icon, $title, 'pass' === $id ? $divider : $message, 'pass' === $id ? $message : $divider, $button ];
		}
		if ( 'horizon' === $id ) {
			return [ $title, $icon, $message, $button ];
		}
		return [ $icon, $title, $message, $button ];
	}

	/**
	 * Marketplace design tokens for the six revision-5 archetypes. Keeping
	 * palette, spacing and component colors in one source prevents factory CSS
	 * from drifting when a template is reused by another renderer surface.
	 *
	 * @return array<string,string>
	 */
	private function factory_v5_design_tokens( $id ) {
		$tokens = [
			'classic' => [
				'surface' => '#ffffff', 'text' => '#172033', 'muted' => '#667085', 'accent' => '#2563eb', 'border' => '#e4e7ec',
				'radius' => '18px', 'padding' => '20px', 'gap' => '12px', 'shadow' => '0 10px 30px #0f172a14',
				'button' => '#2563eb', 'button_text' => '#ffffff', 'icon_bg' => '#eff6ff', 'badge_bg' => '#eef2ff', 'badge_text' => '#3730a3',
				'meta_bg' => '#f8fafc', 'meta_border' => '#eef2f6', 'divider' => '#eef1f5', 'highlight_bg' => '#eef6ff', 'highlight_border' => '#cfe1ff', 'highlight_text' => '#1d4ed8',
				'gradient' => 'none', 'image_shadow' => 'none',
			],
			'atlas' => [
				'surface' => '#101827', 'text' => '#f8fafc', 'muted' => '#a7b4c6', 'accent' => '#f3c969', 'border' => '#2a3a52',
				'radius' => '20px', 'padding' => '22px', 'gap' => '14px', 'shadow' => '0 22px 48px #02061747',
				'button' => '#f3c969', 'button_text' => '#172033', 'icon_bg' => '#1a2a40', 'badge_bg' => '#24364e', 'badge_text' => '#f6d98e',
				'meta_bg' => '#162238', 'meta_border' => '#2b3c55', 'divider' => '#314461', 'highlight_bg' => '#201f1b', 'highlight_border' => '#6e5a2c', 'highlight_text' => '#f8d66f',
				'gradient' => 'none', 'image_shadow' => 'none',
			],
			'aurora' => [
				'surface' => '#ffffff', 'text' => '#172033', 'muted' => '#667085', 'accent' => '#6656d9', 'border' => '#e7e3f7',
				'radius' => '24px', 'padding' => '20px', 'gap' => '14px', 'shadow' => '0 20px 48px #5b5bd624',
				'button' => '#6656d9', 'button_text' => '#ffffff', 'icon_bg' => '#f0edff', 'badge_bg' => '#eeeaff', 'badge_text' => '#5b4bc6',
				'meta_bg' => '#ffffffd1', 'meta_border' => '#ece9f8', 'divider' => '#e7e3f7', 'highlight_bg' => '#f0edff', 'highlight_border' => '#dcd5ff', 'highlight_text' => '#5545c4',
				'gradient' => 'linear-gradient(145deg,#ffffff 0%,#fbfaff 55%,#f1f8ff 100%)', 'image_shadow' => '0 10px 24px #5b5bd62e',
			],
			'pass' => [
				'surface' => '#fffaf0', 'text' => '#342819', 'muted' => '#7d6b52', 'accent' => '#95580f', 'border' => '#c9aa78',
				'radius' => '18px', 'padding' => '20px', 'gap' => '13px', 'shadow' => '0 16px 36px #77522021',
				'button' => '#95580f', 'button_text' => '#ffffff', 'icon_bg' => '#f4e6cf', 'badge_bg' => '#f2e2c5', 'badge_text' => '#78470d',
				'meta_bg' => 'transparent', 'meta_border' => '#dec69f', 'divider' => '#c9aa78', 'highlight_bg' => '#f8ead1', 'highlight_border' => '#d6b27a', 'highlight_text' => '#78470d',
				'gradient' => 'none', 'image_shadow' => 'none',
			],
			'horizon' => [
				'surface' => '#ffffff', 'text' => '#122238', 'muted' => '#5f6f82', 'accent' => '#0d7482', 'border' => '#d7e6e9',
				'radius' => '22px', 'padding' => '16px', 'gap' => '14px', 'shadow' => '0 20px 46px #0f6b7821',
				'button' => '#0d7482', 'button_text' => '#ffffff', 'icon_bg' => '#e6f4f5', 'badge_bg' => '#def1f3', 'badge_text' => '#0a6470',
				'meta_bg' => '#f2f8f9', 'meta_border' => '#e0ecee', 'divider' => '#d7e6e9', 'highlight_bg' => '#e7f5f6', 'highlight_border' => '#c9e7ea', 'highlight_text' => '#0b6975',
				'gradient' => 'none', 'image_shadow' => 'none',
			],
			'velvet' => [
				'surface' => '#2b173f', 'text' => '#fff8ff', 'muted' => '#d2c3dc', 'accent' => '#f1a9c4', 'border' => '#57366f',
				'radius' => '28px', 'padding' => '24px', 'gap' => '15px', 'shadow' => '0 24px 58px #2d13414d',
				'button' => '#f1a9c4', 'button_text' => '#321844', 'icon_bg' => '#43245b', 'badge_bg' => '#ffffff14', 'badge_text' => '#ffd7e6',
				'meta_bg' => '#ffffff0f', 'meta_border' => '#5c3d70', 'divider' => '#745184', 'highlight_bg' => '#f1a9c41f', 'highlight_border' => '#81506f', 'highlight_text' => '#ffd0e1',
				'gradient' => 'radial-gradient(circle at 50% 0%,#503166 0%,#2b173f 62%)', 'image_shadow' => 'none',
			],
		];
		return $tokens[ $id ] ?? $tokens['classic'];
	}

	/** @return array */
	private function factory_v5_styles( $id ) {
		$v = $this->factory_v5_design_tokens( $id );
		$desktop = [
			'background_color' => $v['surface'], 'text_color' => $v['text'], 'muted_color' => $v['muted'], 'accent_color' => $v['accent'],
			'border_color' => $v['border'], 'border_width' => '1px', 'border_radius' => $v['radius'], 'padding' => $v['padding'], 'gap' => $v['gap'],
			'box_shadow' => $v['shadow'], 'button_background' => $v['button'], 'button_color' => $v['button_text'], 'button_border_radius' => '10px',
			'button_padding' => '10px 16px', 'button_font_weight' => '800', 'title_font_size' => '20px', 'title_font_weight' => '800',
			'message_font_size' => '14px', 'meta_font_size' => '13px', 'line_height' => '1.65',
			'status_icon_size' => '44px', 'status_icon_border_radius' => '14px', 'status_icon_background' => $v['icon_bg'], 'status_icon_color' => $v['accent'],
			'badge_background' => $v['badge_bg'], 'badge_color' => $v['badge_text'], 'badge_font_weight' => '700', 'badge_border_radius' => '999px',
		];
		if ( 'none' !== $v['gradient'] ) { $desktop['background_gradient'] = $v['gradient']; }
		if ( 'aurora' === $id ) { $desktop['image_size'] = '112px'; $desktop['image_border_radius'] = '18px'; }
		if ( 'horizon' === $id ) { $desktop['image_border_radius'] = '16px'; $desktop['image_object_fit'] = 'cover'; }
		if ( 'velvet' === $id ) { $desktop['status_icon_size'] = '56px'; $desktop['status_icon_border_radius'] = '50%'; $desktop['status_icon_border_color'] = '#76508b'; $desktop['status_icon_border_width'] = '1px'; }
		if ( 'pass' === $id ) { $desktop['border_width'] = '2px'; $desktop['border_style'] = 'dashed'; }
		return [
			'desktop' => $desktop,
			'tablet' => [ 'padding' => '18px', 'gap' => '12px' ],
			'mobile' => [ 'padding' => '14px', 'gap' => '10px', 'border_radius' => '15px', 'title_font_size' => '18px', 'button_padding' => '11px 14px' ],
		];
	}

	/** @return string */
	private function factory_v5_css( $id ) {
		$v = $this->factory_v5_design_tokens( $id );
		$common = '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;overflow:hidden}'
			. '.ma-co-acp-access-status__head{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px;min-width:0}'
			. '.ma-co-acp-access-status__head>*{min-width:0}'
			. '.ma-co-acp-access-status__head>.ma-co-acp-access-status__block--status_icon{grid-column:1;grid-row:1}'
			. '.ma-co-acp-access-status__head>.ma-co-acp-access-status__block--status_badge{grid-column:3;grid-row:1}'
			. '.ma-co-acp-access-status__head>h3{grid-column:1/4;grid-row:2;text-align:start}'
			. '.ma-co-acp-access-status__block--message{line-height:1.75}'
			. '.ma-co-acp-template-action{display:inline-flex;align-items:center;justify-content:center;min-height:44px}'
			. '.ma-co-acp-template-action:focus-visible{outline:3px solid ' . $v['accent'] . ';outline-offset:2px}'
			. '.ma-co-acp-template-divider{opacity:.72;margin:0}'
			. '.ma-co-acp-access-status__meta div{min-width:0}'
			. '.ma-co-acp-access-status__meta dd{overflow-wrap:anywhere}';
		$css = [
			'classic' => '.ma-co-acp-access-status__head{padding-bottom:11px;border-bottom:1px solid ' . $v['divider'] . '}.ma-co-acp-access-status__meta div{background:' . $v['meta_bg'] . ';border:1px solid ' . $v['meta_border'] . ';border-radius:10px;padding:8px 10px}.ma-co-acp-access-status__block--button{margin-top:2px}',
			'atlas' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{border-inline-start-width:4px;border-inline-start-color:' . $v['accent'] . '}.ma-co-acp-access-status__head{padding-bottom:12px;border-bottom:1px solid ' . $v['divider'] . '}.ma-co-acp-access-status__head h3{font-size:22px}.ma-co-acp-access-status__meta div{background:' . $v['meta_bg'] . ';border:1px solid ' . $v['meta_border'] . ';border-radius:11px;padding:9px 10px}.ma-co-acp-atlas-primary{border-color:' . $v['highlight_border'] . '!important;background:' . $v['highlight_bg'] . '!important}.ma-co-acp-atlas-primary dd{font-size:16px;font-weight:800;color:' . $v['highlight_text'] . '!important}.ma-co-acp-access-status__block--button{margin-top:4px}',
			'aurora' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{border-top-width:5px;border-top-color:' . $v['accent'] . '}.ma-co-acp-access-status__block--image{box-shadow:' . $v['image_shadow'] . '}.ma-co-acp-access-status__meta div{background:' . $v['meta_bg'] . ';border:1px solid ' . $v['meta_border'] . ';border-radius:12px;padding:8px 10px}.ma-co-acp-access-status__block--button{border-radius:999px;box-shadow:0 8px 18px #5b5bd638}',
			'pass' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{border-style:dashed}.ma-co-acp-access-status__head{gap:8px}.ma-co-acp-template-divider{border-top-style:dashed!important;border-top-color:' . $v['divider'] . '!important}.ma-co-acp-access-status__meta div{display:block;padding:6px 0;border-bottom:1px dashed ' . $v['meta_border'] . '}.ma-co-acp-access-status__meta div:nth-last-child(-n+2){border-bottom:0}.ma-co-acp-access-status__meta dt{margin-bottom:2px}.ma-co-acp-access-status__meta dd{text-align:start;font-weight:800}.ma-co-acp-access-status__block--button{font-weight:900}',
			'horizon' => '.ma-co-acp-access-status__head{gap:9px}.ma-co-acp-access-status__block--image{min-height:150px;object-fit:cover}.ma-co-acp-access-status__meta div{background:' . $v['meta_bg'] . ';border:1px solid ' . $v['meta_border'] . ';border-radius:10px;padding:8px 9px}.ma-co-acp-horizon-primary{background:' . $v['highlight_bg'] . '!important;border-color:' . $v['highlight_border'] . '!important}.ma-co-acp-horizon-primary dd{font-weight:800;color:' . $v['highlight_text'] . '!important}.ma-co-acp-access-status__block--button{margin-top:4px}',
			'velvet' => '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{text-align:center}.ma-co-acp-access-status__head{display:flex;justify-content:center;flex-wrap:wrap}.ma-co-acp-access-status__head h3{order:1;width:100%;font-size:22px;text-align:center}.ma-co-acp-access-status__head .ma-co-acp-access-status__block--status_icon{order:0;margin-left:auto;margin-right:auto}.ma-co-acp-access-status__head .ma-co-acp-access-status__block--status_badge{order:2}.ma-co-acp-access-status__block--status_badge{border:1px solid ' . $v['divider'] . '}.ma-co-acp-template-divider{border-top-color:' . $v['divider'] . '!important}.ma-co-acp-access-status__meta div{display:block;background:' . $v['meta_bg'] . ';border:1px solid ' . $v['meta_border'] . ';border-radius:12px;padding:8px}.ma-co-acp-access-status__meta dd{text-align:center;color:inherit}.ma-co-acp-velvet-primary{background:' . $v['highlight_bg'] . '!important;border-color:' . $v['highlight_border'] . '!important}.ma-co-acp-velvet-primary dd{font-size:16px;font-weight:900;color:' . $v['highlight_text'] . '!important}.ma-co-acp-access-status__block--button{width:100%;max-width:210px;margin-left:auto;margin-right:auto;border-radius:999px;font-weight:900}',
		];
		return $common . ( $css[ $id ] ?? $css['classic'] );
	}

	/**
	 * Build one immutable designer factory from the same block contract used by the renderer.
	 *
	 * @param string $id Stable factory ID.
	 * @param string $name Display name.
	 * @param bool   $include_image Add product-image block.
	 * @param array  $styles Responsive style map.
	 * @param string $css Scope-ready custom CSS.
	 * @return array
	 */
	private function designer_factory( $id, $name, $include_image, array $styles, $css ) {
		return [
			'id'         => $id,
			'name'       => $name,
			'origin'     => 'factory',
			'factory_id' => $id,
			'factory_revision' => self::CURRENT_FACTORY_REVISION,
			'mode'       => 'blocks',
			'blocks'     => [
				'product' => $this->designer_product_blocks( $include_image ),
				'message' => $this->designer_message_blocks(),
			],
			'code'       => array_merge( $this->factory_code( $include_image ), [ 'css' => $css ] ),
			'styles'     => $styles,
			'overrides'  => [],
			'states'     => [],
			'orientations' => $this->factory_orientations( $id, $include_image ),
			'default_orientation' => $this->factory_default_orientation( $id ),
			'info_columns' => [ 'desktop' => 2, 'tablet' => 2, 'mobile' => 1 ],
		];
	}

	/** @return array<string,string> */
	private function factory_default_orientation( $id ) {
		return in_array( $id, [ 'aurora', 'horizon' ], true )
			? [ 'desktop' => 'horizontal', 'tablet' => 'horizontal', 'mobile' => 'vertical' ]
			: [];
	}

	/** @return array */
	private function designer_product_blocks( $include_image ) {
		$blocks = [];
		if ( $include_image ) {
			$blocks[] = $this->block( 'product-image', 'image', [ 'source' => 'product', 'alt' => '{product_title}', 'class_name' => 'ma-co-acp-template-image' ] );
		}
		return array_merge(
			$blocks,
			[
				$this->block( 'status-icon', 'status_icon', [ 'aria_label' => __( 'وضعیت {status_label}', 'ma-co-access-control-pro' ), 'size' => '26px' ] ),
				$this->block( 'product-title', 'product_title', [ 'tag' => 'h3' ] ),
				$this->block( 'status-badge', 'status_badge' ),
				$this->block( 'message', 'message', [ 'text' => '{message}' ] ),
				$this->block( 'purchase-date', 'purchase_date', [ 'label' => __( 'تاریخ خرید', 'ma-co-access-control-pro' ) ] ),
				$this->block( 'expiry-date', 'expiry_date', [ 'label' => __( 'انقضا', 'ma-co-access-control-pro' ) ] ),
				$this->block( 'remaining-time', 'remaining_time', [ 'label' => __( 'باقی‌مانده', 'ma-co-access-control-pro' ) ] ),
				$this->block( 'duration-days', 'duration_days', [ 'label' => __( 'مدت اعتبار', 'ma-co-access-control-pro' ) ] ),
				$this->block( 'action', 'button', [ 'label' => __( 'مشاهده محصول', 'ma-co-access-control-pro' ), 'url_source' => 'product', 'class_name' => 'ma-co-acp-template-action' ] ),
			]
		);
	}

	/** @return array */
	private function designer_message_blocks() {
		return [
			$this->block( 'status-icon-message', 'status_icon', [ 'aria_label' => __( 'پیام وضعیت', 'ma-co-access-control-pro' ), 'size' => '28px' ] ),
			$this->block( 'message-title', 'custom_html', [ 'html' => '<strong class="ma-co-acp-access-status__message-title">' . esc_html__( 'وضعیت حساب کاربری', 'ma-co-access-control-pro' ) . '</strong>' ] ),
			$this->block( 'message-text', 'message', [ 'text' => '{message}' ] ),
			$this->block( 'message-action', 'button', [ 'label' => __( 'ورود یا مشاهده حساب', 'ma-co-access-control-pro' ), 'url_source' => 'account', 'class_name' => 'ma-co-acp-template-action' ] ),
		];
	}

	/**
	 * Preserve the original classic code-mode fallback byte-for-byte in behavior.
	 *
	 * @return array
	 */
	private function classic_code() {
		return [
			'product_html' => '<div class="ma-co-acp-access-status__head"><strong class="ma-co-acp-access-status__block ma-co-acp-access-status__block--product_title">{product_title}</strong><span class="ma-co-acp-access-status__block ma-co-acp-access-status__block--status_badge">{status_label}</span></div><p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message">{message}</p><dl class="ma-co-acp-access-status__meta"><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--purchase_date"><dt>{purchase_date_label}</dt><dd>{purchase_date}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--expiry_date"><dt>{expiry_date_label}</dt><dd>{expiry_date}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--remaining_time"><dt>{remaining_time_label}</dt><dd>{remaining_time}</dd></div></dl>',
			'message_html' => '<p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message ma-co-acp-access-status__message">{message}</p>',
			'css'          => '',
		];
	}

	/** @return array */
	private function legacy_classic_code() {
		return [
			'product_html' => '<div class="ma-co-acp-access-status__custom-head"><strong>{product_title}</strong><span>{status_label}</span></div><p>{message}</p><dl><div><dt>' . esc_html__( 'تاریخ خرید', 'ma-co-access-control-pro' ) . '</dt><dd>{purchase_date}</dd></div><div><dt>' . esc_html__( 'انقضا', 'ma-co-access-control-pro' ) . '</dt><dd>{expiry_date}</dd></div><div><dt>' . esc_html__( 'باقی‌مانده', 'ma-co-access-control-pro' ) . '</dt><dd>{remaining_time}</dd></div></dl>',
			'message_html' => '<p class="ma-co-acp-access-status__message">{message}</p>',
			'css'          => '',
		];
	}

	/** @return array */
	private function legacy_factory_code( $include_image ) {
		$image = $include_image
			? '<img class="ma-co-acp-access-status__block ma-co-acp-access-status__block--image" src="{product_image_url}" alt="{product_title}" loading="lazy">'
			: '';
		return [
			'product_html' => $image . '<div class="ma-co-acp-access-status__head"><h3 class="ma-co-acp-access-status__block ma-co-acp-access-status__block--product_title">{product_title}</h3><span class="ma-co-acp-access-status__block ma-co-acp-access-status__block--status_badge">{status_label}</span></div><p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message">{message}</p><dl class="ma-co-acp-access-status__meta"><div><dt>' . esc_html__( 'تاریخ خرید', 'ma-co-access-control-pro' ) . '</dt><dd>{purchase_date}</dd></div><div><dt>' . esc_html__( 'انقضا', 'ma-co-access-control-pro' ) . '</dt><dd>{expiry_date}</dd></div><div><dt>' . esc_html__( 'باقی‌مانده', 'ma-co-access-control-pro' ) . '</dt><dd>{remaining_time}</dd></div><div><dt>' . esc_html__( 'مدت اعتبار', 'ma-co-access-control-pro' ) . '</dt><dd>{duration_days}</dd></div></dl><a class="ma-co-acp-access-status__block ma-co-acp-access-status__block--button" href="{product_url}">' . esc_html__( 'مشاهده محصول', 'ma-co-access-control-pro' ) . '</a>',
			'message_html' => '<div class="ma-co-acp-access-status__message"><strong class="ma-co-acp-access-status__message-title">' . esc_html__( 'وضعیت حساب کاربری', 'ma-co-access-control-pro' ) . '</strong><p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message">{message}</p><a class="ma-co-acp-access-status__block ma-co-acp-access-status__block--button" href="{account_url}">' . esc_html__( 'ورود یا مشاهده حساب', 'ma-co-access-control-pro' ) . '</a></div>',
		];
	}

	/** @return string */
	private function legacy_atlas_css() {
		return '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;overflow:hidden;border-inline-start-width:5px;border-inline-start-color:#d7b25e}.ma-co-acp-access-status__head{border-bottom:1px solid #314461;padding-bottom:12px}.ma-co-acp-access-status__block--status_badge{background:#263b58;color:#f0cf86}.ma-co-acp-access-status__block--message{color:#d5dfec}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr}.ma-co-acp-access-status__meta div{display:block;border:1px solid #2d405d;border-radius:12px;background:#142641;padding:9px 11px}.ma-co-acp-access-status__meta dd{text-align:start}.ma-co-acp-access-status__block--button{margin-top:4px;border-radius:10px;font-weight:800}.is-active .ma-co-acp-access-status__block--status_badge,.is-unlimited .ma-co-acp-access-status__block--status_badge{background:#173f35;color:#8ce3bd}.is-expired .ma-co-acp-access-status__block--status_badge{background:#4c2630;color:#ffb2be}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}';
	}

	/** @return string */
	private function legacy_aurora_css() {
		return '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;background:linear-gradient(145deg,#ffffff 0,#faf8ff 52%,#f0f9ff 100%);overflow:hidden;border-top-width:6px;border-top-color:#8b5cf6}.ma-co-acp-access-status__item.has-product-image{display:grid;grid-template-columns:auto 1fr;column-gap:14px}.ma-co-acp-access-status__block--image{grid-column:1;grid-row:1/5;border-radius:18px;box-shadow:0 8px 20px #6366f12e}.ma-co-acp-access-status__item>.ma-co-acp-access-status__head,.ma-co-acp-access-status__item>.ma-co-acp-access-status__block--message,.ma-co-acp-access-status__item>.ma-co-acp-access-status__meta,.ma-co-acp-access-status__item>.ma-co-acp-access-status__block--button{grid-column:2}.ma-co-acp-access-status__head{align-content:center;justify-content:flex-start;flex-wrap:wrap}.ma-co-acp-access-status__block--status_badge{background:#ede9fe;color:#6d28d9}.ma-co-acp-access-status__block--message{color:#556176}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;padding-top:5px}.ma-co-acp-access-status__meta div{border-radius:12px;background:#ffffffb8;padding:7px 10px}.ma-co-acp-access-status__block--button{margin-top:4px;border-radius:999px;box-shadow:0 8px 18px #5b5bd63d}@media(max-width:767px){.ma-co-acp-access-status__item.has-product-image{grid-template-columns:1fr}.ma-co-acp-access-status__block--image,.ma-co-acp-access-status__item>.ma-co-acp-access-status__head,.ma-co-acp-access-status__item>.ma-co-acp-access-status__block--message,.ma-co-acp-access-status__item>.ma-co-acp-access-status__meta,.ma-co-acp-access-status__item>.ma-co-acp-access-status__block--button{grid-column:1;grid-row:auto}.ma-co-acp-access-status__meta{grid-template-columns:1fr}}';
	}

	/** @return string */
	private function legacy_pass_css() {
		return '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{border-style:dashed;position:relative;background:linear-gradient(90deg,#fffaf0,#fffdf8);overflow:hidden}.ma-co-acp-access-status__head{border-bottom:2px dashed #c9ab7d;padding-bottom:12px}.ma-co-acp-access-status__block--status_badge{background:#f3e4c9;color:#7c4a0d}.ma-co-acp-access-status__block--message{color:#6f604d}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;border-bottom:2px dashed #c9ab7d;padding-bottom:13px}.ma-co-acp-access-status__meta div{display:block}.ma-co-acp-access-status__meta dd{text-align:start}.ma-co-acp-access-status__block--button{margin-top:2px;border-radius:10px;font-weight:900}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}';
	}

	/** @return string */
	private function legacy_horizon_css() {
		return '.ma-co-acp-access-status__item.has-product-image{display:grid;grid-template-columns:42% 58%;align-items:start}.ma-co-acp-access-status__block--image{grid-column:1;grid-row:1/7;width:100%;height:100%;min-height:300px;border-radius:16px}.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__head,.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__block--message,.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__meta,.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__block--button{grid-column:2}.ma-co-acp-access-status__item.is-product-image-missing{display:block;padding:24px}.ma-co-acp-access-status__head{margin-top:6px}.ma-co-acp-access-status__block--status_badge{background:#e1f3f5;color:#0f6b78}.ma-co-acp-access-status__block--message{color:#536176}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;gap:6px}.ma-co-acp-access-status__meta div{border-radius:10px;background:#f3f8f9;padding:7px 9px}.ma-co-acp-access-status__block--button{margin-top:8px;border-radius:10px;font-weight:800}@media(max-width:767px){.ma-co-acp-access-status__item.has-product-image{grid-template-columns:1fr}.ma-co-acp-access-status__block--image,.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__head,.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__block--message,.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__meta,.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__block--button{grid-column:1;grid-row:auto}.ma-co-acp-access-status__block--image{min-height:170px;max-height:210px}.ma-co-acp-access-status__meta{grid-template-columns:1fr}}';
	}

	/** @return string */
	private function legacy_velvet_css() {
		return '.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{text-align:center;background:radial-gradient(circle at 50% 0,#53306d 0,#2e1748 58%)}.ma-co-acp-access-status__head{justify-content:center;flex-wrap:wrap}.ma-co-acp-access-status__head h2,.ma-co-acp-access-status__head h3,.ma-co-acp-access-status__head h4{width:100%;font-size:21px}.ma-co-acp-access-status__block--status_badge{border:1px solid #7b538d;background:#ffffff14;color:#ffd4e5}.ma-co-acp-access-status__block--status_icon{width:50px;height:50px;margin:auto;border:1px solid #835d94;border-radius:50%;background:#45265d;color:#f4a7c6}.ma-co-acp-access-status__block--message{color:#d8cce0}.ma-co-acp-access-status__meta{grid-template-columns:1fr 1fr;gap:8px}.ma-co-acp-access-status__meta div{display:block;border:1px solid #5d3e70;border-radius:13px;background:#ffffff0e;padding:8px}.ma-co-acp-access-status__meta dd{text-align:center}.ma-co-acp-access-status__block--button{margin-top:6px;border-radius:999px;font-weight:900;min-width:170px}.is-active,.is-unlimited{border-color:#8bdfc2}.is-expired{border-color:#ff9bb5}@media(max-width:767px){.ma-co-acp-access-status__meta{grid-template-columns:1fr}}';
	}

	/** @return array */
	private function factory_code( $include_image ) {
		$image = $include_image
			? '<img class="ma-co-acp-access-status__block ma-co-acp-access-status__block--image" src="{product_image_url}" alt="{product_title}" loading="lazy">'
			: '';
		return [
			'product_html' => $image . '<div class="ma-co-acp-access-status__head"><h3 class="ma-co-acp-access-status__block ma-co-acp-access-status__block--product_title">{product_title}</h3><span class="ma-co-acp-access-status__block ma-co-acp-access-status__block--status_badge">{status_label}</span></div><p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message">{message}</p><dl class="ma-co-acp-access-status__meta"><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--purchase_date"><dt>{purchase_date_label}</dt><dd>{purchase_date}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--expiry_date"><dt>{expiry_date_label}</dt><dd>{expiry_date}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--remaining_time"><dt>{remaining_time_label}</dt><dd>{remaining_time}</dd></div><div class="ma-co-acp-access-status__block ma-co-acp-access-status__block--duration_days"><dt>{duration_days_label}</dt><dd>{duration_days}</dd></div></dl><a class="ma-co-acp-access-status__block ma-co-acp-access-status__block--button" href="{product_url}">{product_action_label}</a>',
			'message_html' => '<div class="ma-co-acp-access-status__message"><strong class="ma-co-acp-access-status__message-title">{message_title}</strong><p class="ma-co-acp-access-status__block ma-co-acp-access-status__block--message">{message}</p><a class="ma-co-acp-access-status__block ma-co-acp-access-status__block--button" href="{account_url}">{account_action_label}</a></div>',
			'css'          => '',
		];
	}

	/**
	 * Canonical internal-card orientation deltas. They deliberately contain
	 * structural values only; colour/content remains inherited from the shared
	 * base, template and state layers.
	 *
	 * @param string $id Factory ID.
	 * @param bool   $include_image Whether the card has a product image.
	 * @return array
	 */
	private function factory_orientations( $id, $include_image ) {
		$gaps = [
			'classic' => '12px',
			'atlas'   => '14px',
			'aurora'  => '14px',
			'pass'    => '14px',
			'horizon' => '16px',
			'velvet'  => '15px',
		];
		$gap = isset( $gaps[ $id ] ) ? $gaps[ $id ] : '12px';

		// Structural display/direction belongs to the shared renderer contract.
		// Factories only tune spacing and image geometry so narrow cards can
		// collapse safely without a skin re-locking them into equal columns.
		$horizontal = [
			'desktop' => [ 'gap' => $gap ],
			'tablet'  => [ 'gap' => $gap ],
			'mobile'  => [ 'gap' => '10px' ],
		];
		$vertical = [
			'desktop' => [ 'gap' => $gap ],
			'tablet'  => [ 'gap' => $gap ],
			'mobile'  => [ 'gap' => '10px' ],
		];

		if ( $include_image ) {
			$image_sizes = 'horizon' === $id
				? [ 'desktop' => '220px', 'tablet' => '180px', 'mobile' => '120px' ]
				: [ 'desktop' => '86px', 'tablet' => '76px', 'mobile' => '68px' ];
			foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
				$horizontal[ $device ]['image_position'] = 'start';
				$horizontal[ $device ]['image_size']     = $image_sizes[ $device ];
				$vertical[ $device ]['image_position']   = 'top';
				$vertical[ $device ]['image_size']       = 'horizon' === $id ? '180px' : $image_sizes[ $device ];
			}
		}

		return [
			'horizontal' => [ 'styles' => $horizontal ],
			'vertical'   => [ 'styles' => $vertical ],
		];
	}

	/** @return array */
	private function empty_responsive_styles() {
		return [ 'desktop' => [], 'tablet' => [], 'mobile' => [] ];
	}

	/** @return array */
	private function block( $id, $type, array $settings = [] ) {
		return [
			'id'       => $id,
			'type'     => $type,
			'enabled'  => true,
			'settings' => $settings,
		];
	}
}
