<?php
/**
 * WooCommerce purchase validity service.
 *
 * @package MA_CO_AccessControlPro\Features\WooCommerce
 */

namespace MA_CO_AccessControlPro\Features\WooCommerce;

use MA_CO_AccessControlPro\Utilities\TimeHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooCommerceAccessValidityService {

	const PRODUCT_DURATION_META_KEY = '_ma_co_acp_access_duration_days';
	const PRESETS_OPTION           = 'ma_co_acp_wc_duration_presets';
	const RENEWAL_MODE_OPTION      = 'ma_co_acp_wc_duration_renewal_mode';

	const MODE_LEGACY    = 'legacy';
	const MODE_UNLIMITED = 'unlimited';
	const MODE_PRODUCT   = 'product';
	const MODE_PRESET    = 'preset';
	const MODE_CUSTOM    = 'custom';

	const RENEWAL_LATEST = 'latest';
	const RENEWAL_STACK  = 'stack';

	const DEFAULT_EXPIRING_DAYS    = 7;
	const DEFAULT_EXPIRING_PERCENT = 20;

	public static function get_default_presets() {
		return [
			[
				'key'   => '90-days',
				'label' => __( 'اعتبار ۹۰روزه', 'ma-co-access-control-pro' ),
				'days'  => 90,
			],
			[
				'key'   => '6-months',
				'label' => __( 'اعتبار ۱۸۰روزه', 'ma-co-access-control-pro' ),
				'days'  => 180,
			],
			[
				'key'   => '12-months',
				'label' => __( 'اعتبار ۳۶۵روزه', 'ma-co-access-control-pro' ),
				'days'  => 365,
			],
		];
	}

	public static function get_presets() {
		$raw = get_option( self::PRESETS_OPTION, null );

		if ( null === $raw ) {
			return self::get_default_presets();
		}

		return self::sanitize_presets( $raw );
	}

	public static function sanitize_presets( $raw ) {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$presets = [];
		$seen    = [];

		foreach ( $raw as $index => $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}

			$label = sanitize_text_field( $preset['label'] ?? '' );
			$days  = self::sanitize_duration_days( $preset['days'] ?? 0 );
			$key   = sanitize_key( $preset['key'] ?? '' );

			if ( '' === $label || $days < 1 ) {
				continue;
			}

			if ( '' === $key ) {
				$key = sanitize_key( $label );
			}

			if ( '' === $key ) {
				$key = 'preset-' . absint( $index );
			}

			if ( isset( $seen[ $key ] ) ) {
				$key .= '-' . absint( $index );
			}

			$seen[ $key ] = true;
			$presets[]    = [
				'key'   => $key,
				'label' => $label,
				'days'  => $days,
			];
		}

		return $presets;
	}

	public static function save_presets( $raw ) {
		return update_option( self::PRESETS_OPTION, self::sanitize_presets( $raw ) );
	}

	public static function sanitize_renewal_mode( $mode ) {
		$mode    = sanitize_key( (string) $mode );
		$allowed = [ self::RENEWAL_LATEST, self::RENEWAL_STACK ];

		return in_array( $mode, $allowed, true ) ? $mode : self::RENEWAL_LATEST;
	}

	public static function get_renewal_mode() {
		return self::sanitize_renewal_mode( get_option( self::RENEWAL_MODE_OPTION, self::RENEWAL_LATEST ) );
	}

	public static function save_renewal_mode( $mode ) {
		return update_option( self::RENEWAL_MODE_OPTION, self::sanitize_renewal_mode( $mode ) );
	}

	/**
	 * Canonical product-status messages used when Studio has no explicit override.
	 * `expiring_soon` is a presentation state layered on top of canonical active,
	 * so its untouched fallback intentionally matches the active runtime message.
	 *
	 * @return array<string,string>
	 */
	public static function get_default_status_messages() {
		return [
			'active'           => __( 'اعتبار خرید شما فعال است.', 'ma-co-access-control-pro' ),
			'expiring_soon'    => __( 'اعتبار خرید شما فعال است.', 'ma-co-access-control-pro' ),
			'expired'          => __( 'مدت اعتبار خرید شما به پایان رسیده است.', 'ma-co-access-control-pro' ),
			'unlimited'        => __( 'اعتبار خرید شما بدون انقضاست.', 'ma-co-access-control-pro' ),
			'no_purchase'      => __( 'خرید معتبری برای این محصول در حساب شما پیدا نشد.', 'ma-co-access-control-pro' ),
			'invalid_duration' => __( 'مدت اعتبار این دسترسی به‌درستی تنظیم نشده است. لطفاً با مدیر سایت تماس بگیرید.', 'ma-co-access-control-pro' ),
		];
	}

	/** @return int */
	public static function sanitize_expiring_days( $days ) {
		return min( absint( $days ), 36500 );
	}

	/** @return int */
	public static function sanitize_expiring_percent( $percent ) {
		return min( absint( $percent ), 100 );
	}

	/**
	 * Add presentation-only period metadata without changing the canonical
	 * access status. `expiring_soon` is therefore exposed only as display_state.
	 *
	 * @param array $status Canonical status record.
	 * @param array $context Optional urgency thresholds.
	 * @return array
	 */
	public static function apply_display_metadata( array $status, array $context = [] ) {
		$canonical = sanitize_key( isset( $status['status'] ) ? (string) $status['status'] : 'unknown' );
		$remaining = max( 0, absint( $status['remaining_seconds'] ?? 0 ) );
		$period    = max( 0, absint( $status['period_seconds'] ?? 0 ) );
		if ( 0 === $period && in_array( $canonical, [ 'active', 'expired' ], true ) && ! empty( $status['duration_days'] ) ) {
			$period = absint( $status['duration_days'] ) * DAY_IN_SECONDS;
		}

		if ( 'unlimited' === $canonical ) {
			$percent = 100;
		} elseif ( $period > 0 ) {
			$percent = (int) round( min( 1, $remaining / $period ) * 100 );
		} else {
			$percent = min( 100, absint( $status['remaining_percent'] ?? 0 ) );
		}

		$display = $canonical;
		if ( 'active' === $canonical && $remaining > 0 ) {
			$days_threshold = self::sanitize_expiring_days( $context['expiring_days'] ?? self::DEFAULT_EXPIRING_DAYS );
			$percent_threshold = self::sanitize_expiring_percent( $context['expiring_percent'] ?? self::DEFAULT_EXPIRING_PERCENT );
			$within_days = $days_threshold > 0 && $remaining <= ( $days_threshold * DAY_IN_SECONDS );
			$within_percent = $percent_threshold > 0 && $percent <= $percent_threshold;
			if ( $within_days || $within_percent ) {
				$display = 'expiring_soon';
			}
		}

		$status['period_seconds'] = $period;
		$status['remaining_percent'] = max( 0, min( 100, $percent ) );
		$status['display_state'] = $display;
		return $status;
	}

	public static function get_preset_options() {
		$options = [];

		foreach ( self::get_presets() as $preset ) {
			$options[ $preset['key'] ] = sprintf(
				'%s (%s)',
				$preset['label'],
				sprintf(
					/* translators: %d: number of days */
					_n( '%d روز', '%d روز', $preset['days'], 'ma-co-access-control-pro' ),
					$preset['days']
				)
			);
		}

		return $options;
	}

	public static function get_preset_days( $key ) {
		$key = sanitize_key( $key );

		foreach ( self::get_presets() as $preset ) {
			if ( $preset['key'] === $key ) {
				return absint( $preset['days'] );
			}
		}

		return null;
	}

	public static function sanitize_duration_mode( $mode, $default = self::MODE_LEGACY ) {
		$mode    = sanitize_key( (string) $mode );
		$allowed = [
			self::MODE_LEGACY,
			self::MODE_UNLIMITED,
			self::MODE_PRODUCT,
			self::MODE_PRESET,
			self::MODE_CUSTOM,
		];

		return in_array( $mode, $allowed, true ) ? $mode : $default;
	}

	public static function sanitize_duration_days( $days ) {
		return min( absint( $days ), 36500 );
	}

	public static function get_product_duration_days( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return null;
		}

		if ( metadata_exists( 'post', $product_id, self::PRODUCT_DURATION_META_KEY ) ) {
			return self::sanitize_duration_days( get_post_meta( $product_id, self::PRODUCT_DURATION_META_KEY, true ) );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( $product && $product->is_type( 'variation' ) ) {
			$parent_id = absint( $product->get_parent_id() );

			if ( $parent_id && metadata_exists( 'post', $parent_id, self::PRODUCT_DURATION_META_KEY ) ) {
				return self::sanitize_duration_days( get_post_meta( $parent_id, self::PRODUCT_DURATION_META_KEY, true ) );
			}
		}

		return null;
	}

	public static function resolve_duration_days( $product_id, array $context = [] ) {
		$mode = self::sanitize_duration_mode( $context['mode'] ?? self::MODE_LEGACY );

		switch ( $mode ) {
			case self::MODE_CUSTOM:
				$days = self::sanitize_duration_days( $context['custom_days'] ?? 0 );
				return $days < 1 ? null : $days;

			case self::MODE_PRESET:
				$days = self::get_preset_days( $context['preset'] ?? '' );
				return null === $days ? null : self::sanitize_duration_days( $days );

			case self::MODE_PRODUCT:
				$days = self::get_product_duration_days( $product_id );
				return null === $days ? 0 : self::sanitize_duration_days( $days );

			case self::MODE_UNLIMITED:
			case self::MODE_LEGACY:
			default:
				return 0;
		}
	}

	public static function is_woocommerce_ready() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_product' );
	}

	public static function get_paid_order_statuses() {
		if ( function_exists( 'wc_get_is_paid_statuses' ) ) {
			$statuses = wc_get_is_paid_statuses();
		} else {
			$statuses = [ 'processing', 'completed' ];
		}

		$statuses = array_map(
			function ( $status ) {
				$status = sanitize_key( $status );
				return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
			},
			(array) $statuses
		);

		return apply_filters( 'ma_co_acp_wc_validity_paid_statuses', array_values( array_unique( $statuses ) ) );
	}

	public static function inspect_user_product_access( $user_id, array $product_ids, array $context = [] ) {
		$user_id     = absint( $user_id );
		$product_ids = array_values( array_filter( array_unique( array_map( 'absint', $product_ids ) ) ) );

		$result = [
			'allowed'           => false,
			'status'            => 'no_purchase',
			'message'           => __( 'خرید معتبری برای محصولات انتخاب‌شده پیدا نشد.', 'ma-co-access-control-pro' ),
			'user_id'           => $user_id,
			'product_id'        => 0,
			'product_title'     => '',
			'order_id'          => 0,
			'purchased_at'      => 0,
			'purchased_at_text' => '',
			'expires_at'        => 0,
			'expires_at_text'   => '',
			'remaining_seconds' => 0,
			'remaining_text'    => '',
			'period_started_at'  => 0,
			'period_seconds'     => 0,
			'remaining_percent' => 0,
			'display_state'     => 'no_purchase',
			'duration_days'     => 0,
			'duration_mode'     => self::sanitize_duration_mode( $context['mode'] ?? self::MODE_LEGACY ),
			'renewal_mode'      => self::sanitize_renewal_mode( $context['renewal_mode'] ?? self::get_renewal_mode() ),
		];

		if ( ! $user_id ) {
			$result['status']  = 'guest';
			$result['message'] = __( 'کاربر وارد نشده است.', 'ma-co-access-control-pro' );
			return self::apply_display_metadata( $result, $context );
		}

		if ( empty( $product_ids ) ) {
			$result['allowed'] = true;
			$result['status']  = 'no_products';
			$result['message'] = __( 'محصولی برای بررسی انتخاب نشده است؛ طبق رفتار قبلی قانون، دسترسی مجاز است.', 'ma-co-access-control-pro' );
			return self::apply_display_metadata( $result, $context );
		}

		if ( ! self::is_woocommerce_ready() ) {
			$result['status']  = 'woocommerce_inactive';
			$result['message'] = __( 'ووکامرس فعال یا در دسترس نیست.', 'ma-co-access-control-pro' );
			return self::apply_display_metadata( $result, $context );
		}

		$statuses       = self::get_product_statuses( $user_id, $product_ids, $context );
		$best_expired   = null;
		$best_no_access = null;

		foreach ( $statuses as $status ) {
			if ( ! empty( $status['allowed'] ) ) {
				return $status;
			}

			if ( 'expired' === ( $status['status'] ?? '' ) ) {
				if ( null === $best_expired || absint( $status['expires_at'] ?? 0 ) > absint( $best_expired['expires_at'] ?? 0 ) ) {
					$best_expired = $status;
				}
				continue;
			}

			if ( null === $best_no_access && 'no_purchase' !== ( $status['status'] ?? '' ) ) {
				$best_no_access = $status;
			}
		}

		if ( null !== $best_expired ) {
			return $best_expired;
		}

		return self::apply_display_metadata( null !== $best_no_access ? $best_no_access : $result, $context );
	}

	public static function get_product_statuses( $user_id, array $product_ids, array $context = [] ) {
		$user_id     = absint( $user_id );
		$product_ids = array_values( array_filter( array_unique( array_map( 'absint', $product_ids ) ) ) );
		$statuses    = [];

		// Scope the shared order index to this complete evaluation. The central
		// context resolver may evaluate several candidate rules as one atomic
		// operation, in which case it resets once and asks subsequent batches to
		// preserve that index for query sharing.
		if ( empty( $context['_preserve_purchase_index'] ) ) {
			WooCommercePurchaseIndex::reset_request_cache();
		}

		foreach ( $product_ids as $product_id ) {
			$statuses[] = self::get_single_product_status( $user_id, $product_id, $context );
		}

		return $statuses;
	}

	private static function get_single_product_status( $user_id, $product_id, array $context = [] ) {
		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );
		$title      = $product ? $product->get_name() : get_the_title( $product_id );
		$duration   = self::resolve_duration_days( $product_id, $context );
		$mode       = self::sanitize_duration_mode( $context['mode'] ?? self::MODE_LEGACY );
		$renewal    = self::sanitize_renewal_mode( $context['renewal_mode'] ?? self::get_renewal_mode() );
		$messages   = self::get_default_status_messages();

		$base = [
			'allowed'           => false,
			'status'            => 'no_purchase',
			'message'           => $messages['no_purchase'],
			'user_id'           => absint( $user_id ),
			'product_id'        => $product_id,
			'product_title'     => $title ? sanitize_text_field( $title ) : sprintf( '#%d', $product_id ),
			'order_id'          => 0,
			'purchased_at'      => 0,
			'purchased_at_text' => '',
			'expires_at'        => 0,
			'expires_at_text'   => '',
			'remaining_seconds' => 0,
			'remaining_text'    => '',
			'period_started_at'  => 0,
			'period_seconds'     => 0,
			'remaining_percent' => 0,
			'display_state'     => 'no_purchase',
			'duration_days'     => null === $duration ? 0 : $duration,
			'duration_mode'     => $mode,
			'renewal_mode'      => $renewal,
		];

		if ( null === $duration ) {
			$base['status']  = 'invalid_duration';
			$base['message'] = $messages['invalid_duration'];
			return self::apply_display_metadata( $base, $context );
		}

		$purchase = self::find_latest_purchase_for_product( $user_id, $product_id );

		if ( empty( $purchase ) ) {
			return self::apply_display_metadata( $base, $context );
		}

		$purchased_at = absint( $purchase['purchased_at'] ?? 0 );
		$order_id     = absint( $purchase['order_id'] ?? 0 );

		$base['order_id']          = $order_id;
		$base['purchased_at']      = $purchased_at;
		$base['purchased_at_text'] = self::format_timestamp( $purchased_at );

		if ( $duration < 1 ) {
			$base['allowed']        = true;
			$base['status']         = 'unlimited';
			$base['message']        = $messages['unlimited'];
			$base['remaining_text'] = __( 'بدون انقضا', 'ma-co-access-control-pro' );
			return self::apply_display_metadata( $base, $context );
		}

		$base['period_started_at'] = $purchased_at;
		$base['period_seconds']    = $duration * DAY_IN_SECONDS;
		$expires_at = $purchased_at + $base['period_seconds'];

		if ( self::RENEWAL_STACK === $renewal ) {
			$stacked = self::calculate_stacked_period( $user_id, $product_id, $duration );

			if ( ! empty( $stacked ) ) {
				$purchased_at = absint( $stacked['purchased_at'] ?? $purchased_at );
				$order_id     = absint( $stacked['order_id'] ?? $order_id );
				$expires_at   = absint( $stacked['expires_at'] ?? $expires_at );

				$base['order_id']          = $order_id;
				$base['purchased_at']      = $purchased_at;
				$base['purchased_at_text'] = self::format_timestamp( $purchased_at );
				$base['period_started_at']  = absint( $stacked['period_started_at'] ?? $purchased_at );
				$base['period_seconds']     = absint( $stacked['period_seconds'] ?? ( $expires_at - $base['period_started_at'] ) );
			}
		}

		$remaining  = $expires_at - TimeHelper::get_wc_validity_now_timestamp();
		$is_active  = $remaining > 0;

		$base['allowed']           = $is_active;
		$base['status']            = $is_active ? 'active' : 'expired';
		$base['message']           = $is_active ? $messages['active'] : $messages['expired'];
		$base['expires_at']        = $expires_at;
		$base['expires_at_text']   = self::format_timestamp( $expires_at );
		$base['remaining_seconds'] = max( 0, $remaining );
		$base['remaining_text']    = $is_active ? self::format_remaining( $remaining ) : __( 'منقضی شده', 'ma-co-access-control-pro' );

		return self::apply_display_metadata( $base, $context );
	}

	private static function calculate_stacked_period( $user_id, $product_id, $duration_days ) {
		$purchases = self::find_purchases_for_product( $user_id, $product_id, 'ASC' );

		if ( empty( $purchases ) ) {
			return null;
		}

		$expires_at       = 0;
		$period_started_at = 0;
		$last             = null;

		foreach ( $purchases as $purchase ) {
			$purchased_at = absint( $purchase['purchased_at'] ?? 0 );
			$quantity     = max( 1, absint( $purchase['quantity'] ?? 1 ) );

			// A renewal bought before (or exactly at) expiry extends the current
			// entitlement. A later purchase starts a new contiguous period, so an
			// ancient expired order cannot distort today's progress percentage.
			if ( 0 === $expires_at || $purchased_at > $expires_at ) {
				$period_started_at = $purchased_at;
				$expires_at        = $purchased_at;
			}
			$expires_at += $quantity * $duration_days * DAY_IN_SECONDS;

			$last = $purchase;
		}

		if ( empty( $last ) || ! $expires_at ) {
			return null;
		}

		return [
			'order_id'     => absint( $last['order_id'] ?? 0 ),
			'purchased_at' => absint( $last['purchased_at'] ?? 0 ),
			'expires_at'   => $expires_at,
			'period_started_at' => $period_started_at,
			'period_seconds' => max( 0, $expires_at - $period_started_at ),
		];
	}

	private static function find_latest_purchase_for_product( $user_id, $product_id ) {
		$purchases = self::find_purchases_for_product( $user_id, $product_id, 'DESC' );

		return empty( $purchases ) ? null : reset( $purchases );
	}

	private static function find_purchases_for_product( $user_id, $product_id, $order_direction = 'DESC' ) {
		return WooCommercePurchaseIndex::find( $user_id, $product_id, $order_direction );
	}

	public static function format_timestamp( $timestamp ) {
		$timestamp = absint( $timestamp );

		if ( ! $timestamp ) {
			return '';
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( 0 === strpos( strtolower( $locale ), 'fa' ) ) {
			$date = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
			$text = \MA_CO_AccessControlPro\Utilities\PersianDate::format_datetime( $date, false );
			$text .= ' ' . wp_date( get_option( 'time_format' ), $timestamp, wp_timezone() );
			return strtr( $text, array_combine( range( 0, 9 ), [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ] ) );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, wp_timezone() );
	}

	public static function format_remaining( $seconds ) {
		$seconds = max( 0, absint( $seconds ) );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return __( 'کمتر از یک دقیقه', 'ma-co-access-control-pro' );
		}

		$days    = (int) floor( $seconds / DAY_IN_SECONDS );
		$hours   = (int) floor( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
		$parts   = [];

		if ( $days > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: days */
				_n( '%d روز', '%d روز', $days, 'ma-co-access-control-pro' ),
				$days
			);
		}

		if ( $hours > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: hours */
				_n( '%d ساعت', '%d ساعت', $hours, 'ma-co-access-control-pro' ),
				$hours
			);
		}

		if ( empty( $parts ) && $minutes > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: minutes */
				_n( '%d دقیقه', '%d دقیقه', $minutes, 'ma-co-access-control-pro' ),
				$minutes
			);
		}

		return implode( ' و ', array_slice( $parts, 0, 2 ) );
	}
}
