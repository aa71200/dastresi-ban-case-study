<?php
/**
 * Validation and sanitization for access-status template payloads.
 *
 * @package MA_CO_AccessControlPro\Features\WooCommerce
 */

namespace MA_CO_AccessControlPro\Features\WooCommerce;
require_once __DIR__ . '/AccessStatusLocalParts.php';

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessStatusTemplateSanitizer {

	const SCHEMA_VERSION = 7;
	const FACTORY_REVISION = 7;
	private const CURRENT_FACTORY_REVISION = 7;
	const MAX_TEMPLATES  = 50;
	const MAX_BLOCKS     = 100;
	const MAX_PAYLOAD_BYTES = 524288;
	const MAX_HTML_BYTES = 100000;
	const MAX_CSS_BYTES  = 50000;

	/** @var string[] */
	private static $factory_ids = [ 'classic', 'atlas', 'aurora', 'pass', 'horizon', 'velvet' ];

	/**
	 * Safe layout properties used by bundled templates but absent from the
	 * WordPress 5.8 safe_style_css list. They are enabled only while this
	 * sanitizer filters one declaration list.
	 *
	 * @var string[]
	 */
	private static $compat_safe_css_properties = [
		'appearance',
		'border-inline-start-color',
		'border-inline-start-width',
		'box-shadow',
		'flex-wrap',
		'gap',
		'grid-column',
		'grid-row',
		'object-fit',
		'order',
		'outline',
		'outline-offset',
		'overflow-wrap',
		'position',
	];

	/** @var string[] */
	private static $block_types = [
		'status_icon',
		'image',
		'product_title',
		'status_badge',
		'message',
		'purchase_date',
		'expiry_date',
		'remaining_time',
		'access_progress',
		'duration_days',
		'custom_html',
		'button',
		'divider',
		'spacer',
		'elementor_template',
	];

	/** @var string[] */
	private static $state_keys = [
		'active',
		'expiring_soon',
		'expired',
		'unlimited',
		'no_purchase',
		'invalid_duration',
		'guest',
		'empty',
		'inactive',
	];

	/** @var string[] */
	private static $style_keys = [
		'display',
		'flex_direction',
		'columns',
		'align_items',
		'gap',
		'background_color',
		'text_color',
		'muted_color',
		'accent_color',
		'border_color',
		'border_width',
		'border_radius',
		'padding',
		'margin',
		'box_shadow',
		'align',
		'font_size',
		'font_weight',
		'font_family',
		'letter_spacing',
		'image_size',
		'image_position',
		'image_border_radius',
		'image_aspect_ratio',
		'image_object_fit',
		'background_gradient',
		'border_style',
		'line_height',
		'badge_background',
		'badge_color',
		'badge_border_radius',
		'badge_font_size',
		'badge_font_weight',
		'button_background',
		'button_color',
		'button_font_size',
		'button_font_weight',
		'button_border_radius',
		'button_padding',
		'title_font_size',
		'title_font_weight',
		'message_font_size',
		'meta_font_size',
		'status_icon_color',
		'status_icon_background',
		'status_icon_size',
		'status_icon_border_color',
		'status_icon_border_width',
		'status_icon_border_radius',
	];

	/** Canonical style keys accepted by template settings and widget overrides. */
	public static function get_style_keys() { return self::$style_keys; }

	/** @var string[] */
	private static $orientation_style_keys = [
		'display',
		'flex_direction',
		'columns',
		'align_items',
		'gap',
		'align',
		'image_size',
		'image_position',
	];

	/** @var string[] */
	private static $label_keys = [
		'purchase_date',
		'expiry_date',
		'remaining_time',
		'access_progress',
		'duration_days',
		'product_action',
		'account_action',
		'message_title',
	];

	/** @var string[] */
	private static $visibility_keys = [
		'status_icon',
		'image',
		'product_title',
		'status_badge',
		'message',
		'purchase_date',
		'expiry_date',
		'remaining_time',
		'access_progress',
		'duration_days',
		'product_action',
		'account_action',
		'message_title',
	];

	/**
	 * Sanitize a complete versioned library payload.
	 *
	 * @param mixed $payload Array or JSON object.
	 * @param array $factory_templates Canonical factory templates.
	 * @return array|\WP_Error
	 */
	public function sanitize_payload( $payload, array $factory_templates = [], array $legacy_factory_templates = [], array $factory_revision_templates = [] ) {
		if ( is_string( $payload ) ) {
			if ( strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
				return $this->error( 'payload_too_large', __( 'حجم اطلاعات تمپلیت وضعیت بیشتر از حد مجاز است.', 'ma-co-access-control-pro' ) );
			}
			$decoded = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $this->error( 'invalid_json', __( 'ساختار JSON تمپلیت وضعیت معتبر نیست.', 'ma-co-access-control-pro' ) );
			}
			$payload = $decoded;
		}

		if ( ! is_array( $payload ) ) {
			return $this->error( 'invalid_payload', __( 'اطلاعات تمپلیت وضعیت باید یک ساختار داده معتبر باشد.', 'ma-co-access-control-pro' ) );
		}

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			return $this->error( 'invalid_payload', __( 'رمزگذاری اطلاعات تمپلیت وضعیت امکان‌پذیر نیست.', 'ma-co-access-control-pro' ) );
		}
		if ( strlen( $encoded ) > self::MAX_PAYLOAD_BYTES ) {
			return $this->error( 'payload_too_large', __( 'حجم اطلاعات تمپلیت وضعیت بیشتر از حد مجاز است.', 'ma-co-access-control-pro' ) );
		}

		$input_schema = absint( isset( $payload['schema_version'] ) ? $payload['schema_version'] : self::SCHEMA_VERSION );
		if ( $input_schema > self::SCHEMA_VERSION ) { return $this->error( 'unsupported_schema', __( 'نسخه داده قالب پشتیبانی نمی‌شود.', 'ma-co-access-control-pro' ) ); }
		$input_factory_revision = absint( isset( $payload['factory_revision'] ) ? $payload['factory_revision'] : 0 );
		$is_v1        = 1 === $input_schema;
		$raw_templates = isset( $payload['templates'] ) && is_array( $payload['templates'] )
			? $payload['templates']
			: [];

		$custom_count = 0;
		foreach ( $raw_templates as $raw_id => $raw_template ) {
			$id = $this->sanitize_custom_id( $raw_id );
			if ( '' !== $id && ! in_array( $id, self::$factory_ids, true ) && is_array( $raw_template ) ) {
				++$custom_count;
			}
		}
		if ( $custom_count > self::MAX_TEMPLATES ) {
			return $this->error( 'too_many_templates', __( 'حداکثر ۵۰ تمپلیت سفارشی قابل نگهداری است.', 'ma-co-access-control-pro' ) );
		}

		$templates = [];
		foreach ( self::$factory_ids as $factory_id ) {
			$canonical_source = isset( $factory_templates[ $factory_id ] ) && is_array( $factory_templates[ $factory_id ] )
				? $factory_templates[ $factory_id ]
				: [];
			$canonical = $this->sanitize_template( $canonical_source, $factory_id, 'factory', $factory_id );
			if ( is_wp_error( $canonical ) ) {
				return $canonical;
			}

			$raw_record = isset( $raw_templates[ $factory_id ] ) && is_array( $raw_templates[ $factory_id ] )
				? $raw_templates[ $factory_id ]
				: [];
			$overrides  = [];
			$states     = [];
			$orientation_delta = null;
			$orientations = $this->sanitize_orientation_map( isset( $canonical_source['orientations'] ) ? $canonical_source['orientations'] : [] );

			if ( $is_v1 && ! empty( $raw_record ) && $this->has_template_layers( $raw_record ) ) {
				$legacy_source = isset( $legacy_factory_templates[ $factory_id ] ) && is_array( $legacy_factory_templates[ $factory_id ] )
					? $legacy_factory_templates[ $factory_id ]
					: $canonical_source;
				$legacy = $this->sanitize_template( $legacy_source, $factory_id, 'factory', $factory_id );
				$current = $this->sanitize_template( $raw_record, $factory_id, 'factory', $factory_id );
				if ( is_wp_error( $legacy ) ) {
					return $legacy;
				}
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				$overrides = $this->diff_template_overrides( $current, $legacy );
				$states    = $this->diff_state_maps( $current['states'], $legacy['states'] );
			} elseif ( ! $is_v1 && ! empty( $raw_record ) ) {
				$record_revision = absint( isset( $raw_record['factory_revision'] ) ? $raw_record['factory_revision'] : $input_factory_revision );
				$has_historical_snapshot = $record_revision > 0
					&& $record_revision < self::CURRENT_FACTORY_REVISION
					&& isset( $factory_revision_templates[ $record_revision ][ $factory_id ] )
					&& is_array( $factory_revision_templates[ $record_revision ][ $factory_id ] );

				if ( $this->has_template_layers( $raw_record ) && $has_historical_snapshot ) {
					// Early schema-v2 builders posted complete factory records (including an
					// empty overrides key). Diff those records against their immutable factory
					// revision before trusting sparse overrides, otherwise a factory refresh can
					// either erase real edits or misclassify new factory CSS as user CSS.
					$baseline = $this->sanitize_template( $factory_revision_templates[ $record_revision ][ $factory_id ], $factory_id, 'factory', $factory_id );
					$current  = $this->sanitize_template( $raw_record, $factory_id, 'factory', $factory_id );
					if ( is_wp_error( $baseline ) ) {
						return $baseline;
					}
					if ( is_wp_error( $current ) ) {
						return $current;
					}
					$overrides = $this->diff_template_overrides( $current, $baseline );
					$orientation_delta = $this->diff_orientation_maps( $raw_record['orientations'] ?? [], $factory_revision_templates[ $record_revision ][ $factory_id ]['orientations'] ?? [] );
					// Full Studio snapshots contain canonical fields AND sparse edits.
					// Preserve the explicit edits after diffing the historical fields;
					// numeric block canvases replace completely, including an empty list.
					$explicit = $this->sanitize_template_overrides( is_array( $raw_record['overrides'] ?? null ) ? $raw_record['overrides'] : [] );
					if ( is_wp_error( $explicit ) ) { return $explicit; }
					$overrides = array_replace_recursive( $overrides, $explicit );
					foreach ( $explicit['blocks'] ?? [] as $canvas => $blocks ) { $overrides['blocks'][ $canvas ] = $blocks; }
				} elseif ( isset( $raw_record['overrides'] ) && is_array( $raw_record['overrides'] ) ) {
					$overrides = $this->sanitize_template_overrides( $raw_record['overrides'] );
					if ( is_wp_error( $overrides ) ) {
						return $overrides;
					}
				} elseif ( $this->has_template_layers( $raw_record ) ) {
					$baseline = $this->sanitize_template( $canonical_source, $factory_id, 'factory', $factory_id );
					$current  = $this->sanitize_template( $raw_record, $factory_id, 'factory', $factory_id );
					if ( is_wp_error( $baseline ) ) {
						return $baseline;
					}
					if ( is_wp_error( $current ) ) {
						return $current;
					}
					$overrides = $this->diff_template_overrides( $current, $baseline );
				}
				$states = $this->sanitize_state_map( isset( $raw_record['states'] ) ? $raw_record['states'] : [] );
				if ( is_wp_error( $states ) ) {
					return $states;
				}
				if ( null === $orientation_delta ) {
					$orientation_delta = $this->sanitize_orientation_map( isset( $raw_record['orientations'] ) ? $raw_record['orientations'] : [] );
				}
				$orientations      = $this->merge_orientation_maps( $orientations, $orientation_delta );
			}

			$canonical['factory_revision'] = self::CURRENT_FACTORY_REVISION;
			$canonical['overrides']         = $overrides;
			$canonical['states']            = $states;
			$canonical['orientations']      = $orientations;
			if ( isset( $raw_record['foundation_snapshot'] ) ) {
				$snapshot = $this->sanitize_foundation_snapshot( $raw_record['foundation_snapshot'] );
				if ( is_wp_error( $snapshot ) ) { return $snapshot; }
				$canonical['foundation_snapshot'] = $snapshot;
			}
			$templates[ $factory_id ]       = $canonical;
		}

		foreach ( $raw_templates as $raw_id => $raw_template ) {
			$id = $this->sanitize_custom_id( $raw_id );
			if ( '' === $id || in_array( $id, self::$factory_ids, true ) || ! is_array( $raw_template ) ) {
				continue;
			}

			$clean = $this->sanitize_template( $raw_template, $id, 'custom', '' );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$overrides = isset( $raw_template['overrides'] ) && is_array( $raw_template['overrides'] )
				? $this->sanitize_template_overrides( $raw_template['overrides'] )
				: [];
			if ( is_wp_error( $overrides ) ) {
				return $overrides;
			}
			$clean['factory_revision'] = 0;
			$clean['overrides']         = $overrides;
			$clean['orientations']      = $this->sanitize_orientation_map( isset( $raw_template['orientations'] ) ? $raw_template['orientations'] : [] );
			$templates[ $id ] = $clean;
		}

		$default = sanitize_key( isset( $payload['default_template'] ) ? (string) $payload['default_template'] : 'classic' );
		if ( ! isset( $templates[ $default ] ) || 'archived' === ( $templates[ $default ]['status'] ?? 'active' ) ) {
			$default = 'classic';
		}

		$shared_base = $this->sanitize_shared_base( isset( $payload['shared_base'] ) ? $payload['shared_base'] : [] );
		if ( is_wp_error( $shared_base ) ) { return $shared_base; }
		$template_ids = array_keys( $templates );
		if ( $input_schema < 3 ) {
			$legacy_visibility = isset( $shared_base['content']['visibility'] ) && is_array( $shared_base['content']['visibility'] ) ? $shared_base['content']['visibility'] : [];
			$template_visibility = [];
			if ( ! empty( $legacy_visibility ) ) {
				// Schema v2 had one global visibility map and did not remember which
				// library card was being edited. Preserve it on the persisted default
				// template (the builder's deterministic selection on load) so every
				// other template can correctly start from its own factory defaults.
				$template_visibility[ $default ] = $legacy_visibility;
			}
		} else {
			$template_visibility = $this->sanitize_template_visibility( isset( $payload['template_visibility'] ) ? $payload['template_visibility'] : [], $template_ids );
		}
		$shared_base['content']['visibility'] = [];

		return [
			'schema_version'      => self::SCHEMA_VERSION,
			'factory_revision'    => self::CURRENT_FACTORY_REVISION,
			'default_template'    => $default,
			'shared_base'         => $shared_base,
			'template_visibility' => $template_visibility,
			'templates'           => $templates,
		];
	}

	/**
	 * Convert a normalized/hydrated library into the compact persistence shape.
	 * Factory records retain only user deltas so a future factory revision can
	 * safely improve the canonical design without erasing intentional changes.
	 *
	 * @param array $payload Hydrated payload.
	 * @param array $factory_templates Current canonical factories.
	 * @param array $legacy_factory_templates Optional v1 canonical factories.
	 * @return array|\WP_Error
	 */
	public function compact_payload( array $payload, array $factory_templates = [], array $legacy_factory_templates = [], array $factory_revision_templates = [] ) {
		$clean = $this->sanitize_payload( $payload, $factory_templates, $legacy_factory_templates, $factory_revision_templates );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$stored = [
			'schema_version'   => self::SCHEMA_VERSION,
			'factory_revision' => self::CURRENT_FACTORY_REVISION,
			'default_template' => $clean['default_template'],
			'shared_base'         => $clean['shared_base'],
			'template_visibility' => $clean['template_visibility'],
			'templates'           => [],
		];

		foreach ( $clean['templates'] as $id => $template ) {
			if ( 'factory' === ( $template['origin'] ?? '' ) && in_array( $id, self::$factory_ids, true ) ) {
				$canonical_orientation = $this->sanitize_orientation_map(
					isset( $factory_templates[ $id ]['orientations'] ) ? $factory_templates[ $id ]['orientations'] : []
				);
				$stored['templates'][ $id ] = [
					'id'               => $id,
					'name'             => sanitize_text_field( isset( $factory_templates[ $id ]['name'] ) ? $factory_templates[ $id ]['name'] : $id ),
					'origin'           => 'factory',
					'factory_id'       => $id,
					'factory_revision' => self::CURRENT_FACTORY_REVISION,
					'status'           => 'active',
					'archived_at'      => 0,
					'archive_expires_at' => 0,
					'overrides'        => isset( $template['overrides'] ) && is_array( $template['overrides'] ) ? $template['overrides'] : [],
					'states'           => isset( $template['states'] ) && is_array( $template['states'] ) ? $template['states'] : [],
					'orientations'     => $this->diff_orientation_maps( $template['orientations'] ?? [], $canonical_orientation ),
				];
				if ( isset( $template['foundation_snapshot'] ) ) { $stored['templates'][ $id ]['foundation_snapshot'] = $template['foundation_snapshot']; }
				continue;
			}

			$stored['templates'][ $id ] = [
				'id'               => $id,
				'name'             => $template['name'],
				'origin'           => 'custom',
				'factory_id'       => '',
				'factory_revision' => 0,
				'status'           => 'archived' === ( $template['status'] ?? 'active' ) ? 'archived' : 'active',
				'archived_at'      => absint( $template['archived_at'] ?? 0 ),
				'archive_expires_at' => absint( $template['archive_expires_at'] ?? 0 ),
				'mode'             => $template['mode'],
				'blocks'           => $template['blocks'],
				'code'             => $template['code'],
				'styles'           => $template['styles'],
				'overrides'        => isset( $template['overrides'] ) && is_array( $template['overrides'] ) ? $template['overrides'] : [],
				'states'           => isset( $template['states'] ) && is_array( $template['states'] ) ? $template['states'] : [],
				'orientations'     => $this->sanitize_orientation_map( $template['orientations'] ?? [] ),
				'default_orientation' => $this->sanitize_default_orientation( $template['default_orientation'] ?? [] ),
				'info_columns'       => $this->sanitize_info_columns( $template['info_columns'] ?? [] ),
			];
			if ( ! empty( $template['date_display'] ) ) { $stored['templates'][ $id ]['date_display'] = $template['date_display']; }
			if ( array_key_exists( 'state_content', $template ) ) { $stored['templates'][ $id ]['state_content'] = $template['state_content']; }
			if ( isset( $template['foundation_snapshot'] ) ) { $stored['templates'][ $id ]['foundation_snapshot'] = $template['foundation_snapshot']; }
		}

		return $stored;
	}

	/**
	 * Sanitize the only fields allowed in a sparse template delta.
	 *
	 * @param array $raw Raw delta.
	 * @return array|\WP_Error
	 */
	public function sanitize_template_overrides( array $raw ) {
		$clean = [];
		if ( isset( $raw['local_base'] ) ) {
			$local = $this->sanitize_local_base( $raw['local_base'] );
			if ( is_wp_error( $local ) ) { return $local; }
			if ( $local ) { $clean['local_base'] = $local; }
		}
		$date_display = AccessStatusDateDisplay::sanitize_map( $raw['date_display'] ?? [] );
		if ( $date_display ) { $clean['date_display'] = $date_display; }
		if ( array_key_exists( 'mode', $raw ) ) {
			$clean['mode'] = 'code' === sanitize_key( $raw['mode'] ) ? 'code' : 'blocks';
		}
		if ( isset( $raw['blocks'] ) && is_array( $raw['blocks'] ) ) {
			$clean['blocks'] = [];
			foreach ( [ 'product', 'message' ] as $canvas ) {
				if ( ! array_key_exists( $canvas, $raw['blocks'] ) ) {
					continue;
				}
				$blocks = $this->sanitize_blocks( $raw['blocks'][ $canvas ] );
				if ( is_wp_error( $blocks ) ) {
					return $blocks;
				}
				$clean['blocks'][ $canvas ] = $blocks;
			}
			if ( empty( $clean['blocks'] ) ) {
				unset( $clean['blocks'] );
			}
		}
		if ( isset( $raw['code'] ) && is_array( $raw['code'] ) ) {
			$code = $this->sanitize_code( $raw['code'], true );
			if ( is_wp_error( $code ) ) {
				return $code;
			}
			if ( ! empty( $code ) ) {
				$clean['code'] = $code;
			}
		}
		if ( isset( $raw['styles'] ) && is_array( $raw['styles'] ) ) {
			$clean['styles'] = $this->sanitize_responsive_styles( $raw['styles'] );
		}

		return $clean;
	}

	/**
	 * Sanitize per-widget/per-preview overrides. Identity, state and orientation
	 * keys are deliberately not part of this contract.
	 *
	 * @param array $raw Raw instance delta.
	 * @return array|\WP_Error
	 */
	public function sanitize_instance_overrides( array $raw ) {
		unset( $raw['local_base'] ); // Template scopes cannot be injected by a placed widget.
		$clean = $this->sanitize_template_overrides( $raw );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( isset( $raw['content'] ) && is_array( $raw['content'] ) ) {
			$clean['content'] = $this->sanitize_content( $raw['content'] );
		}
		if ( isset( $raw['parts'] ) ) { $clean['parts'] = AccessStatusLocalParts::sanitize( $raw['parts'] ); }
		$actions = $this->sanitize_instance_actions( $raw['smart_actions'] ?? [] );
		if ( $actions ) { $clean['smart_actions'] = $actions; }
		return $clean;
	}

	/** Only destinations may be replaced by an instance; activation and labels belong to the template. */
	public function sanitize_instance_actions( $raw ) {
		$clean = [];
		foreach ( self::get_state_keys() as $state ) {
			$action = is_array( $raw ) && isset( $raw[ $state ] ) && is_array( $raw[ $state ] ) ? $raw[ $state ] : [];
			$source = $action['url_source'] ?? 'inherit';
			if ( ! is_string( $source ) || ! in_array( $source, [ 'none', 'login', 'product', 'account', 'custom' ], true ) ) { continue; }
			$clean[ $state ] = [ 'url_source' => $source, 'url' => 'custom' === $source ? self::sanitize_action_url( $action['url'] ?? '' ) : '' ];
		}
		return $clean;
	}

	/** Accept HTTP(S) addresses or an explicit site-relative path, never protocol-relative URLs. */
	public static function sanitize_action_url( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 2048 ) { return ''; }
		$url = trim( $raw );
		if ( preg_match( '/[\x00-\x20\\\\]/', $url ) ) { return ''; }
		if ( preg_match( '~^/(?!/)~', $url ) ) { return esc_url_raw( $url, [ 'http', 'https' ] ); }
		if ( ! preg_match( '~^https?://[^/\s?#]+~i', $url ) ) { return ''; }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return ''; }
		return esc_url_raw( $url, [ 'http', 'https' ] );
	}

	/**
	 * Sanitize the sparse novice-facing shared foundation.
	 *
	 * @param mixed $raw Raw shared base.
	 * @return array
	 */
	/**
	 * Sanitize per-template semantic visibility overrides.
	 *
	 * @param mixed $raw Raw visibility map.
	 * @param array $template_ids Existing normalized template IDs.
	 * @return array<string,array<string,bool>>
	 */
	public function sanitize_template_visibility( $raw, array $template_ids = [] ) {
		$raw = is_array( $raw ) ? $raw : [];
		$allowed_ids = array_fill_keys( array_map( 'strval', $template_ids ), true );
		$clean = [];
		foreach ( $raw as $raw_id => $raw_visibility ) {
			$id = sanitize_key( (string) $raw_id );
			if ( '' === $id || ( ! in_array( $id, self::$factory_ids, true ) && '' === $this->sanitize_custom_id( $id ) ) || ( ! empty( $allowed_ids ) && ! isset( $allowed_ids[ $id ] ) ) || ! is_array( $raw_visibility ) ) {
				continue;
			}
			$bucket = [];
			foreach ( self::$visibility_keys as $key ) {
				if ( array_key_exists( $key, $raw_visibility ) ) {
					$bucket[ $key ] = $this->to_bool( $raw_visibility[ $key ] );
				}
			}
			if ( ! empty( $bucket ) ) {
				$clean[ $id ] = $bucket;
			}
		}
		return $clean;
	}

	/** A sparse template delta uses exactly the shared controls and validation. */
	public function sanitize_local_base( $raw ) {
		if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), [ 'content', 'behavior', 'state_content' ] ) || ! empty( $raw['content']['visibility'] ) ) {
			return new \WP_Error( 'invalid_local_scope', __( 'تنظیمات اختصاصی قالب معتبر نیست.', 'ma-co-access-control-pro' ) );
		}
		$clean = $this->sanitize_shared_base( $raw );
		if ( is_wp_error( $clean ) ) { return $clean; }
		// Defaults remain inherited. Resetting a value must never freeze unrelated defaults.
		$intersect = static function ( array $values, array $keys ) use ( &$intersect ) {
			$result = [];
			foreach ( array_intersect_key( $values, $keys ) as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = $intersect( $value, is_array( $keys[ $key ] ) ? $keys[ $key ] : [] );
					if ( ! $value ) { continue; }
				}
				$result[ $key ] = $value;
			}
			return $result;
		};
		return $intersect( $clean, $raw );
	}

	public function sanitize_shared_base( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];
		$content = $this->sanitize_content( isset( $raw['content'] ) ? $raw['content'] : [] );
		foreach ( self::get_default_shared_messages() as $state => $message ) {
			if ( ! array_key_exists( $state, $content['messages'] ) ) {
				$content['messages'][ $state ] = $message;
			}
		}
		$clean = [
			'styles'   => $this->sanitize_responsive_styles( isset( $raw['styles'] ) ? $raw['styles'] : [] ),
			'content'  => $content,
			'behavior' => $this->sanitize_behavior( isset( $raw['behavior'] ) ? $raw['behavior'] : [] ),
		];
		if ( isset( $raw['state_content'] ) ) {
			$states = $this->sanitize_state_content( $raw['state_content'] );
			if ( is_wp_error( $states ) ) { return $states; }
			if ( $states ) { $clean['state_content'] = $states; }
		}
		return $clean;
	}

	/** A frozen template basis never changes the shared basis of other templates. */
	public function sanitize_foundation_snapshot( $raw ) {
		if ( ! is_array( $raw ) || ! is_array( $raw['base'] ?? null ) || ! is_array( $raw['shared_base'] ?? null )
			|| array_diff( array_keys( $raw ), [ 'base', 'shared_base' ] )
			|| array_diff( array_keys( $raw['base'] ), [ 'mode', 'blocks', 'code', 'styles', 'default_orientation', 'info_columns', 'date_display' ] ) ) {
			return $this->error( 'invalid_foundation', __( 'پایه مستقل قالب معتبر نیست.', 'ma-co-access-control-pro' ) );
		}
		$base = $this->sanitize_template_overrides( $raw['base'] );
		$shared = $this->sanitize_shared_base( $raw['shared_base'] );
		if ( is_wp_error( $base ) ) { return $base; }
		if ( is_wp_error( $shared ) ) { return $shared; }
		foreach ( [ 'mode', 'blocks', 'code', 'styles' ] as $key ) {
			if ( ! array_key_exists( $key, $base ) ) { return $this->error( 'invalid_foundation', __( 'پایه مستقل قالب کامل نیست.', 'ma-co-access-control-pro' ) ); }
		}
		$base['default_orientation'] = $this->sanitize_default_orientation( $raw['base']['default_orientation'] ?? [] );
		$base['info_columns'] = $this->sanitize_info_columns( $raw['base']['info_columns'] ?? [] );
		return [ 'base' => $base, 'shared_base' => $shared ];
	}

	/** Central display content is trusted Studio data, never an instance text override. */
	public function sanitize_state_content( $raw ) {
		if ( ! is_array( $raw ) ) { return $this->error( 'invalid_state_content', __( 'تنظیمات محتوای وضعیت معتبر نیست.', 'ma-co-access-control-pro' ) ); }
		$clean = [];
		foreach ( $raw as $component => $states ) {
			if ( ! in_array( $component, [ 'message', 'status_icon', 'status_badge', 'message_title', 'custom_html' ], true ) || ! is_array( $states ) ) {
				return $this->error( 'invalid_state_content', __( 'بخش محتوای وضعیت ناشناخته است.', 'ma-co-access-control-pro' ) );
			}
			foreach ( $states as $state => $entry ) {
				if ( ! in_array( $state, self::$state_keys, true ) || ! is_array( $entry ) ) {
					return $this->error( 'invalid_state_content', __( 'وضعیت محتوا معتبر نیست.', 'ma-co-access-control-pro' ) );
				}
				$record = [];
				$allowed = 'message' === $component ? [ 'enabled' ] : [ 'enabled', 'value' ];
				if ( array_diff( array_keys( $entry ), $allowed ) || ( array_key_exists( 'enabled', $entry ) && ! is_bool( $entry['enabled'] ) ) || ( array_key_exists( 'value', $entry ) && ! is_string( $entry['value'] ) ) ) {
					return $this->error( 'invalid_state_content', __( 'فیلد محتوای وضعیت معتبر نیست.', 'ma-co-access-control-pro' ) );
				}
				if ( array_key_exists( 'enabled', $entry ) ) { $record['enabled'] = $this->to_bool( $entry['enabled'] ); }
				// Message text has one canonical owner: shared_base.content.messages.
				if ( 'message' !== $component && array_key_exists( 'value', $entry ) ) {
					$value = $entry['value'];
					if ( 'custom_html' !== $component && strlen( $value ) > 2048 ) { return $this->error( 'invalid_state_content', __( 'متن وضعیت از حد مجاز طولانی‌تر است.', 'ma-co-access-control-pro' ) ); }
					if ( 'custom_html' === $component ) {
						$value = $this->sanitize_html( $value );
						if ( is_wp_error( $value ) ) { return $value; }
					} elseif ( 'status_icon' === $component ) {
						$value = array_key_exists( $value, AccessStatusComponentRegistry::get_icon_classes() ) ? $value : '';
					} else { $value = sanitize_text_field( $value ); }
					if ( '' !== $value ) { $record['value'] = $value; }
				}
				if ( $record ) { $clean[ $component ][ $state ] = $record; }
			}
		}
		return $clean;
	}

	/**
	 * Normalize marketplace behavior controls shared by every template surface.
	 * The access decision itself remains untouched; these values only derive a
	 * display state and the presentation action.
	 *
	 * @param mixed $raw Raw behavior settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_behavior( $raw ) {
		$raw      = is_array( $raw ) ? $raw : [];
		$defaults = self::get_default_behavior();
		$days     = isset( $raw['expiring_days'] ) && is_numeric( $raw['expiring_days'] ) ? absint( $raw['expiring_days'] ) : $defaults['expiring_days'];
		$percent  = isset( $raw['expiring_percent'] ) && is_numeric( $raw['expiring_percent'] ) ? absint( $raw['expiring_percent'] ) : $defaults['expiring_percent'];
		$actions  = isset( $raw['smart_actions'] ) && is_array( $raw['smart_actions'] ) ? $raw['smart_actions'] : [];
		$login_source = isset( $raw['login_source'] ) && is_string( $raw['login_source'] ) && in_array( $raw['login_source'], [ 'wordpress', 'account', 'custom' ], true ) ? $raw['login_source'] : 'wordpress';
		$login_url = isset( $raw['login_url'] ) && is_string( $raw['login_url'] ) ? esc_url_raw( substr( trim( $raw['login_url'] ), 0, 2048 ), [ 'http', 'https' ] ) : '';
		if ( ! preg_match( '~^https?://[^/\s?#]+~i', $login_url ) ) { $login_url = ''; }

		$clean_actions = [];
		foreach ( $defaults['smart_actions'] as $state => $default_action ) {
			$action = isset( $actions[ $state ] ) && is_array( $actions[ $state ] ) ? $actions[ $state ] : [];
			$source = sanitize_key( isset( $action['url_source'] ) ? (string) $action['url_source'] : $default_action['url_source'] );
			if ( ! in_array( $source, [ 'none', 'login', 'product', 'account', 'custom' ], true ) ) {
				$source = $default_action['url_source'];
			}
			$clean_actions[ $state ] = [
				'enabled'    => array_key_exists( 'enabled', $action ) ? $this->to_bool( $action['enabled'] ) : (bool) $default_action['enabled'],
				'label'      => array_key_exists( 'label', $action ) && is_scalar( $action['label'] )
					? sanitize_text_field( (string) $action['label'] )
					: $default_action['label'],
				'url_source' => $source,
				'url'        => 'custom' === $source && isset( $action['url'] ) && is_scalar( $action['url'] )
					? esc_url_raw( (string) $action['url'] )
					: '',
			];
		}

		return [
			'expiring_days'    => min( $days, 36500 ),
			'expiring_percent' => min( $percent, 100 ),
			'login_source'     => $login_source,
			'login_url'        => $login_url,
			'smart_actions'     => $clean_actions,
		];
	}

	/** @return array<string,mixed> */
	public static function get_default_behavior() {
		return [
			'expiring_days'    => 7,
			'expiring_percent' => 20,
			'login_source'     => 'wordpress',
			'login_url'        => '',
			'smart_actions'     => [
				'guest'            => [ 'enabled' => true, 'label' => __( 'ورود به حساب', 'ma-co-access-control-pro' ), 'url_source' => 'login', 'url' => '' ],
				'active'           => [ 'enabled' => true, 'label' => __( 'مشاهده محصول', 'ma-co-access-control-pro' ), 'url_source' => 'product', 'url' => '' ],
				'expiring_soon'    => [ 'enabled' => true, 'label' => __( 'تمدید دسترسی', 'ma-co-access-control-pro' ), 'url_source' => 'product', 'url' => '' ],
				'unlimited'        => [ 'enabled' => true, 'label' => __( 'مشاهده محصول', 'ma-co-access-control-pro' ), 'url_source' => 'product', 'url' => '' ],
				'expired'          => [ 'enabled' => true, 'label' => __( 'تمدید دسترسی', 'ma-co-access-control-pro' ), 'url_source' => 'product', 'url' => '' ],
				'no_purchase'      => [ 'enabled' => true, 'label' => __( 'خرید محصول', 'ma-co-access-control-pro' ), 'url_source' => 'product', 'url' => '' ],
				'invalid_duration' => [ 'enabled' => false, 'label' => '', 'url_source' => 'none', 'url' => '' ],
				'empty'            => [ 'enabled' => false, 'label' => '', 'url_source' => 'none', 'url' => '' ],
				'inactive'         => [ 'enabled' => false, 'label' => '', 'url_source' => 'none', 'url' => '' ],
			],
		];
	}

	/**
	 * Sanitize the internal responsive default card orientation.
	 * Missing devices stay sparse so old custom templates keep natural layout.
	 *
	 * @param mixed $raw Raw default orientation map.
	 * @return array<string,string>
	 */
	public function sanitize_default_orientation( $raw ) {
		$raw   = is_array( $raw ) ? $raw : [];
		$clean = [];
		foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
			$value = sanitize_key( isset( $raw[ $device ] ) ? (string) $raw[ $device ] : '' );
			if ( in_array( $value, [ 'horizontal', 'vertical' ], true ) ) {
				$clean[ $device ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * Sanitize the internal responsive information-grid column count.
	 *
	 * @param mixed $raw Raw responsive column map.
	 * @return array<string,int>
	 */
	public function sanitize_info_columns( $raw ) {
		$raw   = is_array( $raw ) ? $raw : [];
		$clean = [];
		foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
			if ( ! isset( $raw[ $device ] ) || ! is_numeric( $raw[ $device ] ) ) {
				continue;
			}
			$value = absint( $raw[ $device ] );
			if ( $value >= 1 && $value <= 6 ) {
				$clean[ $device ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * Sanitize horizontal/vertical structural deltas.
	 *
	 * @param mixed $raw Raw orientation map.
	 * @return array
	 */
	public function sanitize_orientation_map( $raw ) {
		$raw   = is_array( $raw ) ? $raw : [];
		$clean = [];
		foreach ( [ 'horizontal', 'vertical' ] as $orientation ) {
			$record = isset( $raw[ $orientation ] ) && is_array( $raw[ $orientation ] ) ? $raw[ $orientation ] : [];
			$styles = isset( $record['styles'] ) && is_array( $record['styles'] ) ? $record['styles'] : $record;
			$clean[ $orientation ] = [ 'styles' => $this->sanitize_orientation_styles( $styles ) ];
		}
		return $clean;
	}

	/**
	 * Sanitize a responsive orientation style map using structural keys only.
	 *
	 * @param mixed $raw Raw responsive styles.
	 * @return array
	 */
	public function sanitize_orientation_styles( $raw ) {
		return $this->sanitize_responsive_styles( $raw, self::$orientation_style_keys );
	}

	/**
	 * Sanitize one template record.
	 *
	 * @param array  $template Raw record.
	 * @param string $id Stable template identifier.
	 * @param string $origin factory|custom.
	 * @param string $factory_id Factory ancestor.
	 * @return array|\WP_Error
	 */
	public function sanitize_template( array $template, $id, $origin = 'custom', $factory_id = '' ) {
		$id = 'factory' === $origin ? sanitize_key( $id ) : $this->sanitize_custom_id( $id );
		if ( '' === $id ) {
			return $this->error( 'invalid_template_id', __( 'شناسه تمپلیت وضعیت معتبر نیست.', 'ma-co-access-control-pro' ) );
		}

		$name = sanitize_text_field( isset( $template['name'] ) ? (string) $template['name'] : $id );
		if ( '' === $name ) {
			$name = $id;
		}

		$mode = isset( $template['mode'] ) && 'code' === sanitize_key( $template['mode'] ) ? 'code' : 'blocks';
		$code = $this->sanitize_code( isset( $template['code'] ) && is_array( $template['code'] ) ? $template['code'] : [] );
		if ( is_wp_error( $code ) ) {
			return $code;
		}

		$blocks         = isset( $template['blocks'] ) && is_array( $template['blocks'] ) ? $template['blocks'] : [];
		$product_blocks = $this->sanitize_blocks( isset( $blocks['product'] ) ? $blocks['product'] : [] );
		$message_blocks = $this->sanitize_blocks( isset( $blocks['message'] ) ? $blocks['message'] : [] );
		if ( is_wp_error( $product_blocks ) ) {
			return $product_blocks;
		}
		if ( is_wp_error( $message_blocks ) ) {
			return $message_blocks;
		}
		$lifecycle_status = 'factory' === $origin ? 'active' : sanitize_key( isset( $template['status'] ) ? (string) $template['status'] : 'active' );
		$lifecycle_status = 'archived' === $lifecycle_status ? 'archived' : 'active';
		$archived_at      = 'archived' === $lifecycle_status ? absint( $template['archived_at'] ?? 0 ) : 0;
		$archive_expires  = 'archived' === $lifecycle_status ? absint( $template['archive_expires_at'] ?? 0 ) : 0;

		$clean  = [
			'id'         => $id,
			'name'       => $name,
			'origin'     => 'factory' === $origin ? 'factory' : 'custom',
			'factory_id' => 'factory' === $origin && in_array( $factory_id, self::$factory_ids, true ) ? $factory_id : '',
			'status'     => $lifecycle_status,
			'archived_at' => $archived_at,
			'archive_expires_at' => $archive_expires,
			'mode'       => $mode,
			'blocks'     => [
				'product' => $product_blocks,
				'message' => $message_blocks,
			],
			'code'       => $code,
			'styles'     => $this->sanitize_responsive_styles( isset( $template['styles'] ) ? $template['styles'] : [] ),
			'default_orientation' => $this->sanitize_default_orientation( isset( $template['default_orientation'] ) ? $template['default_orientation'] : [] ),
			'info_columns' => $this->sanitize_info_columns( isset( $template['info_columns'] ) ? $template['info_columns'] : [] ),
			'states'     => [],
		];

		$states = $this->sanitize_state_map( isset( $template['states'] ) ? $template['states'] : [] );
		if ( is_wp_error( $states ) ) {
			return $states;
		}
		$clean['states'] = $states;
		$date_display = AccessStatusDateDisplay::sanitize_map( $template['date_display'] ?? [] );
		if ( $date_display ) { $clean['date_display'] = $date_display; }
		if ( isset( $template['foundation_snapshot'] ) ) {
			$snapshot = $this->sanitize_foundation_snapshot( $template['foundation_snapshot'] );
			if ( is_wp_error( $snapshot ) ) { return $snapshot; }
			$clean['foundation_snapshot'] = $snapshot;
		}
		// A single-template export carries an immutable display snapshot, like its styles/messages.
		if ( 'custom' === $origin && array_key_exists( 'state_content', $template ) ) {
			$snapshot = $this->sanitize_state_content( $template['state_content'] );
			if ( is_wp_error( $snapshot ) ) { return $snapshot; }
			$clean['state_content'] = $snapshot;
		}

		return $clean;
	}

	/**
	 * Sanitize an HTML fragment while retaining the one explicitly supported shortcode.
	 *
	 * @param mixed $html HTML fragment.
	 * @return string|\WP_Error
	 */
	public function sanitize_html( $html ) {
		$html = is_scalar( $html ) ? (string) $html : '';
		if ( strlen( $html ) > self::MAX_HTML_BYTES ) {
			return $this->error( 'html_too_large', __( 'حجم HTML سفارشی بیشتر از حد مجاز است.', 'ma-co-access-control-pro' ) );
		}

		if ( preg_match( '/<\?(?:php|=)|<\s*(?:script|iframe|object|embed|form|input|textarea|select|style)\b|\son[a-z]+\s*=|javascript\s*:/iu', $html ) ) {
			return $this->error( 'unsafe_html', __( 'HTML سفارشی شامل کد اجرایی یا نشانه‌گذاری ناامن است.', 'ma-co-access-control-pro' ) );
		}

		if ( preg_match_all( '/\[\/?([a-z][a-z0-9_-]*)\b[^\]]*\]/iu', $html, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				if ( 'elementor-template' !== strtolower( $tag ) ) {
					return $this->error( 'unsupported_shortcode', __( 'در HTML سفارشی فقط شورت‌کد تمپلیت Elementor مجاز است.', 'ma-co-access-control-pro' ) );
				}
			}
		}

		return wp_kses( $html, $this->get_allowed_html() );
	}

	/**
	 * Validate and normalize a safe subset of CSS.
	 *
	 * @param mixed $css CSS rules.
	 * @return string|\WP_Error
	 */
	public function sanitize_css( $css ) {
		$css = is_scalar( $css ) ? trim( (string) $css ) : '';
		if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
			return $this->error( 'css_too_large', __( 'حجم CSS سفارشی بیشتر از حد مجاز است.', 'ma-co-access-control-pro' ) );
		}

		$css = str_replace( "\0", '', $css );
		if ( preg_match( '/@(?:import|font-face|keyframes|supports|page|namespace|charset)\b|url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding|<\/?style/iu', $css ) ) {
			return $this->error( 'unsafe_css', __( 'CSS سفارشی شامل قانون یا مقدار غیرمجاز است.', 'ma-co-access-control-pro' ) );
		}

		if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			return $this->error( 'invalid_css', __( 'آکولادهای CSS سفارشی به‌درستی بسته نشده‌اند.', 'ma-co-access-control-pro' ) );
		}

		$parsed = $this->parse_css_rules( $css, false );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return trim( $this->serialize_css_rules( $parsed, '' ) );
	}

	/**
	 * Prefix every selector with one template wrapper.
	 *
	 * @param string $css Sanitized CSS.
	 * @param string $scope CSS selector for the template wrapper.
	 * @return string
	 */
	public function scope_css( $css, $scope, array $roots = [] ) {
		$scope = trim( (string) $scope );
		if ( '' === $scope || '' === trim( (string) $css ) ) {
			return '';
		}

		$parsed = $this->parse_css_rules( (string) $css, false );
		if ( is_wp_error( $parsed ) ) {
			return '';
		}

		return trim( $this->serialize_css_rules( $parsed, $scope, $roots ) );
	}

	/**
	 * Return only declarations that differ from a factory skin. Both inputs are
	 * parsed by the same safe CSS grammar used for stored template CSS.
	 *
	 * @return string Safe, unscoped CSS containing changed declarations only.
	 */
	public function css_delta( $modified, $baseline ) {
		$modified = $this->sanitize_css( $modified );
		$baseline = $this->sanitize_css( $baseline );
		if ( is_wp_error( $modified ) || is_wp_error( $baseline ) ) { return ''; }
		$current_rules = $this->parse_css_rules( $modified, false );
		$base_rules = $this->parse_css_rules( $baseline, false );
		if ( is_wp_error( $current_rules ) || is_wp_error( $base_rules ) ) { return ''; }
		$base = [];
		$flatten = static function ( array $rules, $media = '' ) use ( &$flatten ) {
			$flat = [];
			foreach ( $rules as $rule ) {
				if ( isset( $rule['media'] ) ) { $flat = array_merge( $flat, $flatten( $rule['children'], $rule['media'] ) ); continue; }
				$flat[] = [ $media, $rule['selector'], $rule['declarations'] ];
			}
			return $flat;
		};
		$properties = static function ( $declarations ) {
			$values = [];
			foreach ( explode( ';', $declarations ) as $part ) {
				if ( false === strpos( $part, ':' ) ) { continue; }
				list( $property, $value ) = explode( ':', $part, 2 );
				$values[ trim( $property ) ] = trim( $value );
			}
			return $values;
		};
		foreach ( $flatten( $base_rules ) as $rule ) {
			$key = $rule[0] . '|' . $rule[1];
			$base[ $key ] = array_replace( $base[ $key ] ?? [], $properties( $rule[2] ) );
		}
		$current = [];
		foreach ( $flatten( $current_rules ) as $rule ) {
			$key = $rule[0] . '|' . $rule[1];
			$current[ $key ] = [ 'media' => $rule[0], 'selector' => $rule[1], 'declarations' => array_replace( $current[ $key ]['declarations'] ?? [], $properties( $rule[2] ) ) ];
		}
		$out = '';
		foreach ( $current as $key => $rule ) {
			$before = $base[ $key ] ?? [];
			$changed = [];
			foreach ( $rule['declarations'] as $property => $value ) {
				if ( ! isset( $before[ $property ] ) || $before[ $property ] !== $value ) { $changed[] = $property . ':' . $value; }
			}
			if ( ! $changed ) { continue; }
			$css = $rule['selector'] . '{' . implode( ';', $changed ) . '}';
			$out .= $rule['media'] ? $rule['media'] . '{' . $css . '}' : $css;
		}
		return $out;
	}

	/** @return string[] */
	public static function get_block_types() {
		return self::$block_types;
	}

	/** @return string[] */
	public static function get_state_keys() {
		return self::$state_keys;
	}

	/** @return string[] */
	public static function get_factory_ids() {
		return self::$factory_ids;
	}

	/** @return string[] */
	public static function get_label_keys() {
		return self::$label_keys;
	}

	/** @return string[] */
	public static function get_visibility_keys() {
		return self::$visibility_keys;
	}

	/**
	 * @param mixed $raw Raw shared/instance content.
	 * @return array
	 */
	private function sanitize_content( $raw ) {
		$raw   = is_array( $raw ) ? $raw : [];
		$clean = [ 'messages' => [], 'labels' => [], 'visibility' => [] ];

		$messages = isset( $raw['messages'] ) && is_array( $raw['messages'] ) ? $raw['messages'] : [];
		foreach ( self::$state_keys as $state ) {
			if ( array_key_exists( $state, $messages ) && is_scalar( $messages[ $state ] ) ) {
				$clean['messages'][ $state ] = sanitize_textarea_field( (string) $messages[ $state ] );
			}
		}

		$labels = isset( $raw['labels'] ) && is_array( $raw['labels'] ) ? $raw['labels'] : [];
		foreach ( self::$label_keys as $label ) {
			if ( array_key_exists( $label, $labels ) && is_scalar( $labels[ $label ] ) ) {
				$clean['labels'][ $label ] = sanitize_text_field( (string) $labels[ $label ] );
			}
		}

		$visibility = isset( $raw['visibility'] ) && is_array( $raw['visibility'] ) ? $raw['visibility'] : [];
		foreach ( self::$visibility_keys as $key ) {
			if ( array_key_exists( $key, $visibility ) ) {
				$clean['visibility'][ $key ] = $this->to_bool( $visibility[ $key ] );
			}
		}

		return $clean;
	}

	/** @return array<string,string> */
	public static function get_default_shared_messages() {
		return [
			'guest'    => __( 'برای دیدن وضعیت خرید و اعتبارتان وارد حساب کاربری شوید.', 'ma-co-access-control-pro' ),
			'empty'    => __( 'در حال حاضر وضعیت خریدی برای نمایش وجود ندارد.', 'ma-co-access-control-pro' ),
			'inactive' => __( 'امکان بررسی وضعیت خرید در حال حاضر وجود ندارد.', 'ma-co-access-control-pro' ),
		];
	}

	/**
	 * Canonical semantic-label fallbacks used when neither the template nor
	 * Studio provides a more specific label. Action labels are intentionally
	 * excluded from this fixed map because Smart CTA resolves them per state.
	 *
	 * @return array<string,string>
	 */
	public static function get_default_shared_labels() {
		return [
			'purchase_date'   => __( 'تاریخ خرید', 'ma-co-access-control-pro' ),
			'expiry_date'     => __( 'انقضا', 'ma-co-access-control-pro' ),
			'remaining_time'  => __( 'باقی‌مانده', 'ma-co-access-control-pro' ),
			'duration_days'   => __( 'مدت اعتبار', 'ma-co-access-control-pro' ),
			'access_progress' => __( 'اعتبار باقی‌مانده', 'ma-co-access-control-pro' ),
			'message_title'   => __( 'وضعیت حساب کاربری', 'ma-co-access-control-pro' ),
		];
	}

	/**
	 * @param mixed $raw_states Raw state map.
	 * @return array|\WP_Error
	 */
	private function sanitize_state_map( $raw_states ) {
		$raw_states = is_array( $raw_states ) ? $raw_states : [];
		$clean      = [];
		foreach ( array_keys( $raw_states ) as $raw_state_key ) {
			$raw_state_key = (string) $raw_state_key;
			if ( ! in_array( $raw_state_key, self::$state_keys, true ) ) {
				return $this->error( 'unsupported_state', __( 'فایل تمپلیت شامل وضعیت ناشناخته‌ای است که این نسخه نمی‌تواند بدون خطر از دست‌رفتن داده ذخیره کند.', 'ma-co-access-control-pro' ) );
			}
		}
		foreach ( self::$state_keys as $state_key ) {
			if ( empty( $raw_states[ $state_key ] ) || ! is_array( $raw_states[ $state_key ] ) ) {
				continue;
			}
			$state = $this->sanitize_state_override( $raw_states[ $state_key ] );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			if ( ! empty( $state ) ) {
				$clean[ $state_key ] = $state;
			}
		}
		return $clean;
	}

	/** @return bool */
	private function has_template_layers( array $template ) {
		foreach ( [ 'mode', 'blocks', 'code', 'styles', 'date_display' ] as $key ) {
			if ( array_key_exists( $key, $template ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Calculate an allowlisted template delta between two sanitized full records.
	 *
	 * @return array
	 */
	private function diff_template_overrides( array $current, array $baseline ) {
		$delta = [];
		if ( ( $current['date_display'] ?? [] ) !== ( $baseline['date_display'] ?? [] ) ) { $delta['date_display'] = $current['date_display'] ?? []; }
		if ( ( $current['mode'] ?? 'blocks' ) !== ( $baseline['mode'] ?? 'blocks' ) ) {
			$delta['mode'] = $current['mode'];
		}
		foreach ( [ 'product', 'message' ] as $canvas ) {
			$current_blocks = $current['blocks'][ $canvas ] ?? [];
			$base_blocks    = $baseline['blocks'][ $canvas ] ?? [];
			if ( $current_blocks !== $base_blocks ) {
				$delta['blocks'][ $canvas ] = $current_blocks;
			}
		}
		foreach ( [ 'product_html', 'message_html', 'css' ] as $key ) {
			$current_value = $current['code'][ $key ] ?? '';
			$base_value    = $baseline['code'][ $key ] ?? '';
			if ( $current_value !== $base_value ) {
				$delta['code'][ $key ] = $current_value;
			}
		}
		$style_delta = $this->diff_responsive_styles( $current['styles'] ?? [], $baseline['styles'] ?? [] );
		if ( $this->responsive_map_has_values( $style_delta ) ) {
			$delta['styles'] = $style_delta;
		}
		return $delta;
	}

	/** @return array */
	private function diff_state_maps( array $current, array $baseline ) {
		$delta = [];
		foreach ( self::$state_keys as $state ) {
			if ( ! isset( $current[ $state ] ) || ! is_array( $current[ $state ] ) ) {
				continue;
			}
			$state_delta = $this->array_delta( $current[ $state ], isset( $baseline[ $state ] ) && is_array( $baseline[ $state ] ) ? $baseline[ $state ] : [] );
			if ( ! empty( $state_delta ) ) {
				$delta[ $state ] = $state_delta;
			}
		}
		return $delta;
	}

	/** @return array */
	private function diff_responsive_styles( $current, $baseline ) {
		$current  = is_array( $current ) ? $current : [];
		$baseline = is_array( $baseline ) ? $baseline : [];
		$delta    = [ 'desktop' => [], 'tablet' => [], 'mobile' => [] ];
		foreach ( array_keys( $delta ) as $device ) {
			$values = isset( $current[ $device ] ) && is_array( $current[ $device ] ) ? $current[ $device ] : [];
			$base   = isset( $baseline[ $device ] ) && is_array( $baseline[ $device ] ) ? $baseline[ $device ] : [];
			foreach ( $values as $key => $value ) {
				if ( ! array_key_exists( $key, $base ) || $value !== $base[ $key ] ) {
					$delta[ $device ][ $key ] = $value;
				}
			}
		}
		return $delta;
	}

	/** @return bool */
	private function responsive_map_has_values( array $styles ) {
		foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
			if ( ! empty( $styles[ $device ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array */
	private function merge_orientation_maps( array $base, array $delta ) {
		foreach ( [ 'horizontal', 'vertical' ] as $orientation ) {
			foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
				$base_values  = isset( $base[ $orientation ]['styles'][ $device ] ) && is_array( $base[ $orientation ]['styles'][ $device ] ) ? $base[ $orientation ]['styles'][ $device ] : [];
				$delta_values = isset( $delta[ $orientation ]['styles'][ $device ] ) && is_array( $delta[ $orientation ]['styles'][ $device ] ) ? $delta[ $orientation ]['styles'][ $device ] : [];
				$base[ $orientation ]['styles'][ $device ] = array_merge( $base_values, $delta_values );
			}
		}
		return $base;
	}

	/** @return array */
	private function diff_orientation_maps( $current, $baseline ) {
		$current  = $this->sanitize_orientation_map( $current );
		$baseline = $this->sanitize_orientation_map( $baseline );
		$delta    = [];
		foreach ( [ 'horizontal', 'vertical' ] as $orientation ) {
			$delta[ $orientation ] = [
				'styles' => $this->diff_responsive_styles( $current[ $orientation ]['styles'], $baseline[ $orientation ]['styles'] ),
			];
		}
		return $delta;
	}

	/** @return array */
	private function array_delta( array $current, array $baseline ) {
		$delta = [];
		foreach ( $current as $key => $value ) {
			if ( ! array_key_exists( $key, $baseline ) ) {
				$delta[ $key ] = $value;
				continue;
			}
			if ( is_array( $value ) && is_array( $baseline[ $key ] ) && ! $this->is_list_array( $value ) ) {
				$nested = $this->array_delta( $value, $baseline[ $key ] );
				if ( ! empty( $nested ) ) {
					$delta[ $key ] = $nested;
				}
			} elseif ( $value !== $baseline[ $key ] ) {
				$delta[ $key ] = $value;
			}
		}
		return $delta;
	}

	/** @return bool */
	private function is_list_array( array $array ) {
		return empty( $array ) || array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * @param array $state Raw state override.
	 * @return array|\WP_Error
	 */
	private function sanitize_state_override( array $state ) {
		$clean = [];
		if ( array_key_exists( 'enabled', $state ) ) {
			$clean['enabled'] = $this->to_bool( $state['enabled'] );
		}
		if ( isset( $state['mode'] ) ) {
			$clean['mode'] = 'code' === sanitize_key( $state['mode'] ) ? 'code' : 'blocks';
		}
		if ( isset( $state['message'] ) ) {
			$clean['message'] = sanitize_textarea_field( (string) $state['message'] );
		}
		if ( isset( $state['blocks'] ) && is_array( $state['blocks'] ) ) {
			$clean['blocks'] = [];
			foreach ( [ 'product', 'message' ] as $canvas ) {
				if ( array_key_exists( $canvas, $state['blocks'] ) ) {
					$blocks = $this->sanitize_blocks( $state['blocks'][ $canvas ] );
					if ( is_wp_error( $blocks ) ) {
						return $blocks;
					}
					$clean['blocks'][ $canvas ] = $blocks;
				}
			}
		}
		if ( isset( $state['code'] ) && is_array( $state['code'] ) ) {
			$code = $this->sanitize_code( $state['code'], true );
			if ( is_wp_error( $code ) ) {
				return $code;
			}
			$clean['code'] = $code;
		}
		if ( isset( $state['styles'] ) ) {
			$clean['styles'] = $this->sanitize_responsive_styles( $state['styles'] );
		}

		return $clean;
	}

	/**
	 * @param mixed $blocks Raw block list.
	 * @return array|\WP_Error
	 */
	public function sanitize_blocks( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return [];
		}
		if ( count( $blocks ) > self::MAX_BLOCKS ) {
			return $this->error( 'too_many_blocks', __( 'تعداد بلوک‌ها در یکی از بوم‌های وضعیت بیشتر از حد مجاز است.', 'ma-co-access-control-pro' ) );
		}

		$clean = [];
		$seen  = [];
		foreach ( array_values( $blocks ) as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$type = sanitize_key( isset( $block['type'] ) ? $block['type'] : '' );
			if ( ! in_array( $type, self::$block_types, true ) ) {
				return $this->error( 'unsupported_block_type', __( 'فایل تمپلیت شامل نوع بلوک ناشناخته‌ای است که این نسخه نمی‌تواند بدون خطر از دست‌رفتن داده ذخیره کند.', 'ma-co-access-control-pro' ) );
			}

			$id = sanitize_html_class( isset( $block['id'] ) ? (string) $block['id'] : $type . '-' . ( $index + 1 ) );
			if ( '' === $id ) {
				$id = $type . '-' . ( $index + 1 );
			}
			$base_id = $id;
			$suffix  = 2;
			while ( isset( $seen[ $id ] ) ) {
				$id = $base_id . '-' . $suffix;
				++$suffix;
			}
			$seen[ $id ] = true;

			$settings = $this->sanitize_block_settings( $type, isset( $block['settings'] ) ? $block['settings'] : [] );
			if ( is_wp_error( $settings ) ) {
				return $settings;
			}

			$clean[] = [
				'id'       => $id,
				'type'     => $type,
				'enabled'  => ! array_key_exists( 'enabled', $block ) || $this->to_bool( $block['enabled'] ),
				'settings' => $settings,
			];
		}

		return $clean;
	}

	/**
	 * @param string $type Block type.
	 * @param mixed  $settings Raw settings.
	 * @return array|\WP_Error
	 */
	private function sanitize_block_settings( $type, $settings ) {
		$settings = is_array( $settings ) ? $settings : [];
		$clean    = [];
		if ( in_array( $type, [ 'message', 'status_icon', 'status_badge', 'custom_html' ], true ) && isset( $settings['state_content_source'] ) ) {
			$clean['state_content_source'] = in_array( $settings['state_content_source'], [ 'auto', 'shared', 'local' ], true ) ? $settings['state_content_source'] : 'auto';
		}

		foreach ( [ 'label', 'text', 'alt', 'icon', 'class_name', 'prefix', 'aria_label' ] as $key ) {
			if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
				$clean[ $key ] = 'class_name' === $key
					? sanitize_html_class( (string) $settings[ $key ] )
					: sanitize_text_field( (string) $settings[ $key ] );
			}
		}

		if ( 'product_title' === $type && isset( $settings['tag'] ) ) {
			$tag = sanitize_key( $settings['tag'] );
			$clean['tag'] = in_array( $tag, [ 'h2', 'h3', 'h4', 'p', 'div' ], true ) ? $tag : '';
		}

		if ( isset( $settings['block_width'] ) ) {
			$clean['block_width'] = $this->sanitize_block_width( $settings['block_width'] );
		} elseif ( 'divider' !== $type && isset( $settings['width'] ) ) {
			$clean['block_width'] = $this->sanitize_block_width( $settings['width'] );
		}

		if ( isset( $settings['spacing'] ) && is_array( $settings['spacing'] ) ) {
			$spacing = $this->sanitize_block_spacing( $settings['spacing'] );
			if ( ! empty( $spacing ) ) {
				$clean['spacing'] = $spacing;
			}
		}
		if ( in_array( $type, [ 'purchase_date', 'expiry_date', 'remaining_time', 'duration_days', 'access_progress' ], true ) ) {
			foreach ( [ 'value_prefix', 'value_suffix' ] as $key ) {
				if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
					$raw = (string) $settings[ $key ];
					$value = sanitize_text_field( $raw );
					if ( '' !== $value ) {
						$value = ( preg_match( '/^\s/u', $raw ) ? ' ' : '' ) . $value . ( preg_match( '/\s$/u', $raw ) ? ' ' : '' );
					}
					$clean[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 80 ) : substr( $value, 0, 80 );
				}
			}
			if ( isset( $settings['part_styles'] ) && is_array( $settings['part_styles'] ) ) {
				$parts = $this->sanitize_block_part_styles( $settings['part_styles'] );
				if ( ! empty( $parts ) ) { $clean['part_styles'] = $parts; }
			}
		}

		if ( 'status_icon' === $type && isset( $settings['size'] ) ) {
			$clean['size'] = $this->sanitize_dimension( $settings['size'], '' );
		}

		if ( 'access_progress' === $type ) {
			$clean['show_label'] = ! array_key_exists( 'show_label', $settings ) || $this->to_bool( $settings['show_label'] );
			$clean['show_value'] = ! array_key_exists( 'show_value', $settings ) || $this->to_bool( $settings['show_value'] );
		}

		if ( 'divider' === $type ) {
			$clean['color'] = $this->sanitize_color( isset( $settings['color'] ) ? $settings['color'] : '' );
			$clean['width'] = $this->sanitize_dimension( isset( $settings['width'] ) ? $settings['width'] : '1px', '1px' );
			$style = sanitize_key( isset( $settings['style'] ) ? $settings['style'] : 'solid' );
			$clean['style'] = in_array( $style, [ 'solid', 'dashed', 'dotted', 'double' ], true ) ? $style : 'solid';
		}

		if ( 'custom_html' === $type && isset( $settings['html'] ) ) {
			$html = $this->sanitize_html( $settings['html'] );
			if ( is_wp_error( $html ) ) {
				return $html;
			}
			$clean['html'] = $html;
		}

		if ( 'elementor_template' === $type ) {
			$clean['template_id'] = absint( isset( $settings['template_id'] ) ? $settings['template_id'] : 0 );
		}

		if ( 'image' === $type ) {
			$source = sanitize_key( isset( $settings['source'] ) ? $settings['source'] : 'product' );
			$clean['source']        = in_array( $source, [ 'product', 'site_logo', 'media' ], true ) ? $source : 'product';
			$clean['attachment_id'] = absint( isset( $settings['attachment_id'] ) ? $settings['attachment_id'] : 0 );
			$clean['url']           = esc_url_raw( isset( $settings['url'] ) ? (string) $settings['url'] : '' );
		}

		if ( 'button' === $type ) {
			$source = sanitize_key( isset( $settings['url_source'] ) ? $settings['url_source'] : 'product' );
			$clean['url_source'] = in_array( $source, [ 'smart', 'none', 'login', 'product', 'account', 'custom' ], true ) ? $source : 'product';
			$clean['url']        = esc_url_raw( isset( $settings['url'] ) ? (string) $settings['url'] : '' );
			$clean['new_tab']    = ! empty( $settings['new_tab'] );
		}

		if ( 'spacer' === $type ) {
			$clean['size'] = $this->sanitize_dimension( isset( $settings['size'] ) ? $settings['size'] : '16px', '16px' );
		}

		if ( in_array( $type, [ 'purchase_date', 'expiry_date' ], true ) ) {
			$date_display = AccessStatusDateDisplay::sanitize( $settings['date_display'] ?? [] );
			if ( $date_display ) { $clean['date_display'] = $date_display; }
		}
		return $clean;
	}

	/** Allow only the rendered text parts and the shared safe presentation contract. */
	private function sanitize_block_part_styles( array $styles ) {
		$clean = [];
		foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
			if ( empty( $styles[ $device ] ) || ! is_array( $styles[ $device ] ) ) { continue; }
			foreach ( [ 'label', 'value' ] as $part ) {
				$source = $styles[ $device ][ $part ] ?? null;
				if ( ! is_array( $source ) ) { continue; }
				$values = [];
				if ( isset( $source['color'] ) ) {
					$color = $this->sanitize_color( $source['color'] );
					if ( '' !== $color ) { $values['color'] = $color; }
				}
				if ( isset( $source['font_size'] ) ) {
					$size = $this->sanitize_bounded_dimension( $source['font_size'], 8, 96, false );
					if ( '' !== $size && preg_match( '/^\d+(?:\.\d+)?px$/', $size ) ) { $values['font_size'] = $size; }
				}
				if ( isset( $source['font_weight'] ) && preg_match( '/^(?:normal|bold|[1-9]00)$/', (string) $source['font_weight'] ) ) {
					$values['font_weight'] = (string) $source['font_weight'];
				}
				if ( isset( $source['align'] ) && in_array( $source['align'], [ 'start', 'center', 'end' ], true ) ) {
					$values['align'] = $source['align'];
				}
				$properties = [];
				foreach ( AccessStatusComponentRegistry::get_part_style_controls() as $key => $control ) { if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) { $properties[ $control['property'] ] = $source[ $key ]; } }
				$validated = AccessStatusLocalParts::sanitize( [ 'part/product/block/purchase_date/' . $part => [ $device => $properties ] ] );
				foreach ( AccessStatusComponentRegistry::get_part_style_controls() as $key => $control ) { if ( isset( $validated[ 'part/product/block/purchase_date/' . $part ][ $device ][ $control['property'] ] ) ) { $values[ $key ] = $validated[ 'part/product/block/purchase_date/' . $part ][ $device ][ $control['property'] ]; } }
				if ( $values ) { $clean[ $device ][ $part ] = $values; }
			}
		}
		return $clean;
	}

	/**
	 * @param array $code Raw code fields.
	 * @param bool  $partial Keep only supplied fields.
	 * @return array|\WP_Error
	 */
	private function sanitize_code( array $code, $partial = false ) {
		$clean = [];
		foreach ( [ 'product_html', 'message_html' ] as $key ) {
			if ( $partial && ! array_key_exists( $key, $code ) ) {
				continue;
			}
			$html = $this->sanitize_html( isset( $code[ $key ] ) ? $code[ $key ] : '' );
			if ( is_wp_error( $html ) ) {
				return $html;
			}
			$clean[ $key ] = $html;
		}

		if ( ! $partial || array_key_exists( 'css', $code ) ) {
			$css = $this->sanitize_css( isset( $code['css'] ) ? $code['css'] : '' );
			if ( is_wp_error( $css ) ) {
				return $css;
			}
			$clean['css'] = $css;
		}

		return $clean;
	}

	/**
	 * @param mixed $styles Raw responsive styles.
	 * @return array
	 */
	private function sanitize_responsive_styles( $styles, array $allowed_keys = [] ) {
		$styles = is_array( $styles ) ? $styles : [];
		$clean  = [ 'desktop' => [], 'tablet' => [], 'mobile' => [] ];
		$keys   = empty( $allowed_keys ) ? self::$style_keys : $allowed_keys;
		foreach ( array_keys( $clean ) as $device ) {
			$values = isset( $styles[ $device ] ) && is_array( $styles[ $device ] ) ? $styles[ $device ] : [];
			if ( ! array_key_exists( 'columns', $values ) && array_key_exists( 'grid_columns', $values ) ) {
				$values['columns'] = $values['grid_columns'];
			}
			if ( ! array_key_exists( 'align_items', $values ) && array_key_exists( 'alignment', $values ) ) {
				$values['align_items'] = $values['alignment'];
			}
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $values ) || ! is_scalar( $values[ $key ] ) ) {
					continue;
				}
				$value = $this->sanitize_style_value( $key, $values[ $key ] );
				if ( '' !== $value ) {
					$clean[ $device ][ $key ] = $value;
				}
			}
		}

		return $clean;
	}

	/**
	 * @param string $key Style key.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	private function sanitize_style_value( $key, $value ) {
		$value = trim( (string) $value );
		switch ( $key ) {
			case 'display':
				return in_array( $value, [ 'block', 'flex', 'grid' ], true ) ? $value : '';
			case 'flex_direction':
				return in_array( $value, [ 'row', 'column', 'row-reverse', 'column-reverse' ], true ) ? $value : '';
			case 'align_items':
				return in_array( $value, [ 'start', 'center', 'end', 'stretch' ], true ) ? $value : '';
			case 'columns':
				$columns = absint( $value );
				return $columns >= 1 && $columns <= 12 ? (string) $columns : '';
			case 'align':
				return in_array( $value, [ 'right', 'center', 'left', 'start', 'end' ], true ) ? $value : '';
			case 'image_position':
				return in_array( $value, [ 'start', 'end', 'top', 'bottom' ], true ) ? $value : '';
			case 'image_object_fit':
				return in_array( $value, [ 'cover', 'contain', 'fill', 'none', 'scale-down' ], true ) ? $value : '';
			case 'image_aspect_ratio':
				return in_array( $value, [ 'auto', '1/1', '4/3', '3/2', '16/9', '9/16' ], true ) ? $value : '';
			case 'border_style':
				return in_array( $value, [ 'none', 'solid', 'dashed', 'dotted', 'double' ], true ) ? $value : '';
			case 'font_weight':
			case 'badge_font_weight':
			case 'button_font_weight':
			case 'title_font_weight':
				return preg_match( '/^(?:normal|bold|[1-9]00)$/', $value ) ? $value : '';
			case 'font_family':
			case 'letter_spacing':
				$property = 'font_family' === $key ? 'font-family' : 'letter-spacing';
				$clean = AccessStatusLocalParts::sanitize( [ 'style/product/block/product_title/main' => [ 'desktop' => [ $property => $value ] ] ] );
				return $clean['style/product/block/product_title/main']['desktop'][ $property ] ?? '';
			case 'background_color':
			case 'badge_background':
			case 'badge_color':
			case 'text_color':
			case 'muted_color':
			case 'accent_color':
			case 'border_color':
			case 'button_background':
			case 'button_color':
			case 'status_icon_color':
			case 'status_icon_background':
			case 'status_icon_border_color':
				return $this->sanitize_color( $value );
			case 'background_gradient':
				return $this->sanitize_background_gradient( $value );
			case 'box_shadow':
				if ( strlen( $value ) > 256 ) {
					return '';
				}
				$unsafe_shadow_tokens = [ 'url', 'expression', 'behavior', '-moz-binding' ];
				foreach ( $unsafe_shadow_tokens as $unsafe_token ) {
					if ( false !== stripos( $value, $unsafe_token ) ) {
						return '';
					}
				}
				return preg_match( '/^[#(),.%\-\s\da-z]+$/i', $value ) ? $value : '';
			case 'padding':
			case 'button_padding':
				return $this->sanitize_box_dimensions( $value, false );
			case 'margin':
				return $this->sanitize_box_dimensions( $value, true );
			case 'line_height':
				return $this->sanitize_line_height( $value );
			case 'image_size':
				return $this->sanitize_bounded_dimension( $value, 0, 5000, true );
			case 'image_border_radius':
			case 'badge_border_radius':
			case 'button_border_radius':
			case 'status_icon_border_radius':
				return $this->sanitize_bounded_dimension( $value, 0, 1000, true );
			case 'badge_font_size':
			case 'button_font_size':
			case 'title_font_size':
			case 'message_font_size':
			case 'meta_font_size':
			case 'status_icon_size':
				return $this->sanitize_bounded_dimension( $value, 1, 300, false );
			case 'status_icon_border_width':
				return $this->sanitize_bounded_dimension( $value, 0, 50, false );
			default:
				return $this->sanitize_dimension( $value, '' );
		}
	}


	/**
	 * Split a selector list on top-level commas only.
	 * Commas inside :is(), :not(), attribute selectors or quoted values are preserved.
	 *
	 * @param string $value Selector list.
	 * @return string[]
	 */
	private function split_css_selectors( $value ) {
		$value         = (string) $value;
		$parts         = [];
		$buffer        = '';
		$paren_depth   = 0;
		$bracket_depth = 0;
		$quote         = '';
		$escaped       = false;
		$length        = strlen( $value );
		for ( $index = 0; $index < $length; ++$index ) {
			$character = $value[ $index ];
			if ( $escaped ) {
				$buffer .= $character;
				$escaped = false;
				continue;
			}
			if ( '\\' === $character ) {
				$buffer .= $character;
				$escaped = true;
				continue;
			}
			if ( '' !== $quote ) {
				$buffer .= $character;
				if ( $character === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $character || "'" === $character ) {
				$quote   = $character;
				$buffer .= $character;
				continue;
			}
			if ( '(' === $character ) {
				++$paren_depth;
			} elseif ( ')' === $character ) {
				--$paren_depth;
				if ( $paren_depth < 0 ) {
					return [];
				}
			} elseif ( '[' === $character ) {
				++$bracket_depth;
			} elseif ( ']' === $character ) {
				--$bracket_depth;
				if ( $bracket_depth < 0 ) {
					return [];
				}
			}
			if ( ',' === $character && 0 === $paren_depth && 0 === $bracket_depth ) {
				$parts[] = trim( $buffer );
				$buffer  = '';
				continue;
			}
			$buffer .= $character;
		}
		if ( '' !== $quote || 0 !== $paren_depth || 0 !== $bracket_depth ) {
			return [];
		}
		$parts[] = trim( $buffer );
		return $parts;
	}

	/**
	 * Parse flat rules and safe @media blocks.
	 *
	 * @param string $css CSS text.
	 * @param bool   $inside_media Whether recursion is inside media.
	 * @return array|\WP_Error
	 */
	private function parse_css_rules( $css, $inside_media ) {
		$css    = preg_replace( '!/\*.*?\*/!s', '', (string) $css );
		$length = strlen( $css );
		$offset = 0;
		$rules  = [];

		while ( $offset < $length ) {
			while ( $offset < $length && preg_match( '/\s/', $css[ $offset ] ) ) {
				++$offset;
			}
			if ( $offset >= $length ) {
				break;
			}

			$open = strpos( $css, '{', $offset );
			if ( false === $open ) {
				return $this->error( 'invalid_css', __( 'یکی از قوانین CSS سفارشی ناقص است.', 'ma-co-access-control-pro' ) );
			}
			$header = trim( substr( $css, $offset, $open - $offset ) );
			$close  = $this->find_matching_brace( $css, $open );
			if ( false === $close || '' === $header ) {
				return $this->error( 'invalid_css', __( 'یکی از قوانین CSS سفارشی معتبر نیست.', 'ma-co-access-control-pro' ) );
			}

			$body = substr( $css, $open + 1, $close - $open - 1 );
			if ( 0 === stripos( $header, '@media' ) ) {
				if ( $inside_media || ! preg_match( '/^@media\s*\(\s*(?:min|max)-width\s*:\s*\d+(?:\.\d+)?(?:px|em|rem)\s*\)(?:\s+and\s+\(\s*(?:min|max)-width\s*:\s*\d+(?:\.\d+)?(?:px|em|rem)\s*\))?$/i', $header ) ) {
					return $this->error( 'unsafe_css_media', __( 'فقط media query ساده با min-width یا max-width مجاز است.', 'ma-co-access-control-pro' ) );
				}
				$children = $this->parse_css_rules( $body, true );
				if ( is_wp_error( $children ) ) {
					return $children;
				}
				$rules[] = [ 'media' => $header, 'children' => $children ];
			} else {
				if ( false !== strpos( $header, '@' ) || ! preg_match( '/^[a-z0-9_#.\-\s>,:+~*\[\]="\'()]+$/i', $header ) ) {
					return $this->error( 'unsafe_css_selector', __( 'CSS سفارشی شامل selector پشتیبانی‌نشده است.', 'ma-co-access-control-pro' ) );
				}
				$selectors = $this->split_css_selectors( $header );
				foreach ( $selectors as $selector ) {
					// Prefixing a leading sibling combinator would select elements next
					// to the template wrapper instead of descendants inside it.
					if ( '' === $selector || preg_match( '/^[+~]/', $selector ) ) {
						return $this->error( 'unsafe_css_selector', __( 'CSS سفارشی شامل selector خارج از محدوده تمپلیت است.', 'ma-co-access-control-pro' ) );
					}
				}
				$declarations = $this->sanitize_declarations( $body );
				if ( '' !== $declarations ) {
					$rules[] = [ 'selector' => $header, 'declarations' => $declarations ];
				}
			}

			$offset = $close + 1;
		}

		return $rules;
	}

	/**
	 * @param array  $rules Parsed rules.
	 * @param string $scope Optional wrapper selector.
	 * @return string
	 */
	private function serialize_css_rules( array $rules, $scope, array $roots = [] ) {
		$output = '';
		foreach ( $rules as $rule ) {
			if ( isset( $rule['media'] ) ) {
				$output .= $rule['media'] . '{' . $this->serialize_css_rules( $rule['children'], $scope, $roots ) . '}';
				continue;
			}

			$selectors = array_filter( $this->split_css_selectors( $rule['selector'] ) );
			if ( '' !== $scope ) {
				$selectors = array_map(
					function ( $selector ) use ( $scope, $roots ) {
						foreach ( $roots as $root => $target ) {
							$pattern = '/^' . preg_quote( $root, '/' ) . '(?=[\s.#:\[]|$)/';
							if ( preg_match( $pattern, $selector ) && ! preg_match( '/[+~]/', $selector ) ) { return preg_replace( $pattern, $target, $selector, 1 ); }
						}
						if ( preg_match( '/^\.ma-co-acp-access-status(?=[\s.#:\[]|$)/', $selector ) && ! preg_match( '/[+~]/', $selector ) ) { return preg_replace( '/^\.ma-co-acp-access-status/', $scope, $selector, 1 ); }
						return $scope . ' ' . $selector;
					},
					$selectors
				);
			}
			if ( ! empty( $selectors ) ) {
				$output .= implode( ',', $selectors ) . '{' . $rule['declarations'] . '}';
			}
		}

		return $output;
	}

	/**
	 * @param string $body Declaration list.
	 * @return string
	 */
	private function sanitize_declarations( $body ) {
		$body = trim( (string) $body );
		if ( '' === $body || false !== strpos( $body, '{' ) || false !== strpos( $body, '}' ) ) {
			return '';
		}

		$parts = [];
		foreach ( explode( ';', $body ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration || ! preg_match( '/^([-a-z][a-z0-9-]*)\s*:\s*([^{}<>]+)$/i', $declaration, $match ) ) {
				continue;
			}

			$property = strtolower( $match[1] );
			$value    = trim( $match[2] );
			if ( in_array( $property, self::$compat_safe_css_properties, true ) ) {
				$value = $this->sanitize_compat_css_value( $property, $value );
				if ( '' !== $value ) {
					$parts[] = $property . ':' . $value;
				}
				continue;
			}

			if ( function_exists( 'safecss_filter_attr' ) ) {
				$clean = trim( safecss_filter_attr( $property . ':' . $value ) );
				if ( '' !== $clean ) {
					$parts[] = rtrim( $clean, ';' );
				}
			} else {
				$parts[] = $property . ':' . $value;
			}
		}
		$body = implode( ';', $parts );

		return '' === trim( $body ) ? '' : rtrim( trim( $body ), ';' ) . ';';
	}

	/**
	 * Validate the small set of layout declarations missing from WordPress 5.8.
	 *
	 * @param string $property CSS property.
	 * @param string $value CSS value.
	 * @return string
	 */
	private function sanitize_compat_css_value( $property, $value ) {
		$value = trim( (string) $value );
		switch ( $property ) {
			case 'appearance':
				return in_array( $value, [ 'none', 'auto' ], true ) ? $value : '';
			case 'border-inline-start-color':
				return $this->sanitize_color( $value );
			case 'border-inline-start-width':
				return preg_match( '/^\d+(?:\.\d+)?(?:px|rem|em|%)?$/i', $value ) ? $value : '';
			case 'box-shadow':
				foreach ( [ 'url', 'expression', 'behavior', '-moz-binding' ] as $unsafe_token ) {
					if ( false !== stripos( $value, $unsafe_token ) ) {
						return '';
					}
				}
				return preg_match( '/^[#(),.%\-\s\da-z]+$/i', $value ) ? $value : '';
			case 'flex-wrap':
				return in_array( $value, [ 'nowrap', 'wrap', 'wrap-reverse' ], true ) ? $value : '';
			case 'gap':
				return preg_match( '/^\d+(?:\.\d+)?(?:px|rem|em|%)?(?:\s+\d+(?:\.\d+)?(?:px|rem|em|%)?)?$/i', $value ) ? $value : '';
			case 'grid-column':
			case 'grid-row':
				return preg_match( '/^(?:auto|[1-9]\d*(?:\s*\/\s*(?:auto|[1-9]\d*))?|span\s+[1-9]\d*)$/i', $value ) ? preg_replace( '/\s+/', ' ', $value ) : '';
			case 'object-fit':
				return in_array( $value, [ 'fill', 'contain', 'cover', 'none', 'scale-down' ], true ) ? $value : '';
			case 'order':
				return preg_match( '/^-?\d{1,2}$/', $value ) && (int) $value >= -99 && (int) $value <= 99 ? (string) (int) $value : '';
			case 'outline':
				if ( 'none' === strtolower( $value ) ) {
					return 'none';
				}
				if ( ! preg_match( '/^(\d+(?:\.\d+)?(?:px|rem|em))\s+(solid|dashed|dotted)\s+(.+)$/i', $value, $match ) ) {
					return '';
				}
				$color = $this->sanitize_color( $match[3] );
				return '' === $color ? '' : $match[1] . ' ' . strtolower( $match[2] ) . ' ' . $color;
			case 'outline-offset':
				return preg_match( '/^-?\d+(?:\.\d+)?(?:px|rem|em)$/i', $value ) ? $value : '';
			case 'overflow-wrap':
				return in_array( $value, [ 'normal', 'break-word', 'anywhere' ], true ) ? $value : '';
			case 'position':
				return in_array( $value, [ 'static', 'relative', 'absolute', 'fixed', 'sticky' ], true ) ? $value : '';
		}
		return '';
	}

	/** @return array<string,array<string,bool>> */
	private function get_allowed_html() {
		$global = [
			'class'       => true,
			'id'          => true,
			'title'       => true,
			'role'        => true,
			'dir'         => true,
			'aria-label'  => true,
			'aria-hidden' => true,
			'aria-valuemin' => true,
			'aria-valuemax' => true,
			'aria-valuenow' => true,
			'aria-valuetext' => true,
			'data-*'      => true,
		];

		$allowed = [];
		foreach ( [ 'section', 'article', 'div', 'header', 'footer', 'span', 'p', 'strong', 'em', 'b', 'i', 'small', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd' ] as $tag ) {
			$allowed[ $tag ] = $global;
		}
		$allowed['a'] = array_merge( $global, [ 'href' => true, 'target' => true, 'rel' => true ] );
		$allowed['button'] = array_merge( $global, [ 'type' => true, 'disabled' => true ] );
		$allowed['img'] = array_merge( $global, [ 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true ] );
		$allowed['progress'] = array_merge( $global, [ 'max' => true, 'value' => true ] );
		$allowed['hr']  = $global;
		$allowed['br']  = [];

		return $allowed;
	}

	/** @return string */
	private function sanitize_custom_id( $id ) {
		$id = sanitize_key( (string) $id );
		return preg_match( '/^custom-[a-z0-9][a-z0-9-]{7,63}$/', $id ) ? $id : '';
	}

	/** @return string */
	private function sanitize_color( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) || 'transparent' === strtolower( $value ) ) {
			return $value;
		}

		$matches = [];
		if ( preg_match( '/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $value, $matches ) ) {
			return max( (int) $matches[1], (int) $matches[2], (int) $matches[3] ) <= 255 ? $value : '';
		}
		if ( preg_match( '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0(?:\.\d+)?|1(?:\.0+)?)\s*\)$/i', $value, $matches ) ) {
			return max( (int) $matches[1], (int) $matches[2], (int) $matches[3] ) <= 255 ? $value : '';
		}
		return '';
	}

	/** @return string */
	private function sanitize_background_gradient( $value ) {
		$value = trim( (string) $value );
		if ( 'none' === strtolower( $value ) ) {
			return 'none';
		}

		$type   = '';
		$prefix = '';
		$body   = '';
		if ( preg_match( '/^linear-gradient\(\s*(\d{1,3}(?:\.\d+)?)deg\s*,(.*)\)$/i', $value, $matches ) ) {
			$angle = (float) $matches[1];
			if ( $angle < 0 || $angle > 360 ) {
				return '';
			}
			$type   = 'linear-gradient';
			$prefix = (string) ( 0.0 + $angle ) . 'deg';
			$body   = $matches[2];
		} elseif ( preg_match( '/^radial-gradient\(\s*circle\s+at\s+(\d{1,3}(?:\.\d+)?)%\s+(\d{1,3}(?:\.\d+)?)%\s*,(.*)\)$/i', $value, $matches ) ) {
			$x = (float) $matches[1];
			$y = (float) $matches[2];
			if ( $x < 0 || $x > 100 || $y < 0 || $y > 100 ) {
				return '';
			}
			$type   = 'radial-gradient';
			$prefix = 'circle at ' . (string) ( 0.0 + $x ) . '% ' . (string) ( 0.0 + $y ) . '%';
			$body   = $matches[3];
		} else {
			return '';
		}

		$parts = $this->split_css_arguments( $body );
		if ( count( $parts ) < 2 || count( $parts ) > 4 ) {
			return '';
		}

		$color_pattern = '(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)|transparent)';
		$stops         = [];
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^\s*' . $color_pattern . '(?:\s+(\d{1,3}(?:\.\d+)?)%)?\s*$/i', $part, $match ) ) {
				return '';
			}
			$color = $this->sanitize_color( $match[1] );
			if ( '' === $color ) {
				return '';
			}
			$stop = '';
			if ( isset( $match[2] ) && '' !== $match[2] ) {
				$position = (float) $match[2];
				if ( $position < 0 || $position > 100 ) {
					return '';
				}
				$stop = ' ' . (string) ( 0.0 + $position ) . '%';
			}
			$stops[] = $color . $stop;
		}

		return $type . '(' . $prefix . ',' . implode( ',', $stops ) . ')';
	}

	/**
	 * Split top-level CSS function arguments without splitting rgba() channels.
	 *
	 * @param string $value Argument list.
	 * @return string[]
	 */
	private function split_css_arguments( $value ) {
		$parts   = [];
		$buffer  = '';
		$depth   = 0;
		$length  = strlen( (string) $value );
		for ( $index = 0; $index < $length; ++$index ) {
			$character = $value[ $index ];
			if ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
				if ( $depth < 0 ) {
					return [];
				}
			}
			if ( ',' === $character && 0 === $depth ) {
				$parts[] = trim( $buffer );
				$buffer  = '';
				continue;
			}
			$buffer .= $character;
		}
		if ( 0 !== $depth ) {
			return [];
		}
		$parts[] = trim( $buffer );
		return $parts;
	}

	/** @return string */
	private function sanitize_line_height( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
			$number = (float) $value;
			return $number >= 0.5 && $number <= 5 ? $value : '';
		}
		return $this->sanitize_bounded_dimension( $value, 1, 500, true );
	}

	/** @return string */
	private function sanitize_bounded_dimension( $value, $minimum, $maximum, $allow_percent ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em|%|vw|vh)?$/i', $value, $matches ) ) {
			return '';
		}
		$number = (float) $matches[1];
		$unit   = isset( $matches[2] ) ? strtolower( $matches[2] ) : '';
		if ( $number < $minimum || $number > $maximum || ( '%' === $unit && ! $allow_percent ) ) {
			return '';
		}
		if ( '' === $unit ) {
			return 0.0 === $number ? '0' : $matches[1] . 'px';
		}
		return $matches[1] . $unit;
	}

	/** @return string */
	private function sanitize_dimension( $value, $fallback ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^-?\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh)?$/i', $value ) ) {
			return $fallback;
		}
		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $value ) ) {
			return 0.0 === (float) $value ? '0' : $value . 'px';
		}
		return $value;
	}

	/** @return array */
	private function sanitize_block_spacing( array $spacing ) {
		$clean = [];
		foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
			if ( ! isset( $spacing[ $device ] ) || ! is_array( $spacing[ $device ] ) ) {
				continue;
			}
			$device_clean = [];
			foreach ( [ 'margin', 'padding' ] as $type ) {
				if ( ! isset( $spacing[ $device ][ $type ] ) || ! is_array( $spacing[ $device ][ $type ] ) ) {
					continue;
				}
				$type_clean = [];
				foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
					if ( ! array_key_exists( $side, $spacing[ $device ][ $type ] ) || ! is_scalar( $spacing[ $device ][ $type ][ $side ] ) ) {
						continue;
					}
					$value = $this->sanitize_spacing_dimension( $spacing[ $device ][ $type ][ $side ], 'margin' === $type );
					if ( '' !== $value ) {
						$type_clean[ $side ] = $value;
					}
				}
				if ( ! empty( $type_clean ) ) {
					$device_clean[ $type ] = $type_clean;
				}
			}
			if ( ! empty( $device_clean ) ) {
				$clean[ $device ] = $device_clean;
			}
		}
		return $clean;
	}

	/** @return string */
	private function sanitize_spacing_dimension( $value, $allow_negative ) {
		$dimension = $this->sanitize_dimension( $value, '' );
		if ( '' === $dimension || ( ! $allow_negative && 0 === strpos( $dimension, '-' ) ) ) {
			return '';
		}
		if ( ! preg_match( '/^(-?\d+(?:\.\d+)?)(px|rem|em|%|vw|vh)?$/i', $dimension, $matches ) ) {
			return '';
		}
		$amount = (float) $matches[1];
		$unit   = strtolower( isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : 'px' );
		$limit  = '%' === $unit ? 500 : 2000;
		if ( abs( $amount ) > $limit || ( ! $allow_negative && $amount < 0 ) ) {
			return '';
		}
		return 0.0 === $amount ? '0' : $matches[1] . $unit;
	}

	/** @return string */
	private function sanitize_block_width( $value ) {
		$value = trim( (string) $value );
		if ( 'auto' === strtolower( $value ) ) {
			return 'auto';
		}
		if ( preg_match( '/^(\d+(?:\.\d+)?)%$/', $value, $matches ) && (float) $matches[1] <= 100 ) {
			return $value;
		}
		return $this->sanitize_dimension( $value, '' );
	}

	/** @return string */
	private function sanitize_box_dimensions( $value, $allow_negative = true ) {
		$parts = preg_split( '/\s+/', trim( (string) $value ) );
		if ( empty( $parts ) || count( $parts ) > 4 ) {
			return '';
		}
		$clean = [];
		foreach ( $parts as $part ) {
			if ( ! $allow_negative && 0 === strpos( trim( $part ), '-' ) ) {
				return '';
			}
			$dimension = $this->sanitize_dimension( $part, '' );
			if ( '' === $dimension ) {
				return '';
			}
			$clean[] = $dimension;
		}
		return implode( ' ', $clean );
	}

	/** @return int|false */
	private function find_matching_brace( $css, $open ) {
		$depth  = 0;
		$length = strlen( $css );
		for ( $i = $open; $i < $length; ++$i ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return false;
	}

	/** @return bool */
	private function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/** @return \WP_Error */
	private function error( $code, $message ) {
		return new \WP_Error( 'ma_co_acp_' . sanitize_key( $code ), $message );
	}
}
