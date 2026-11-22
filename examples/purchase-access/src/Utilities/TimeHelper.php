<?php
/**
 * Time helper for access schedules.
 *
 * @package MA_CO_AccessControlPro\Utilities
 */

namespace MA_CO_AccessControlPro\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TimeHelper {

	const APPLY_TO_WC_VALIDITY_OPTION = 'ma_co_acp_time_apply_to_wc_validity';

	/** Return the same schedule explanation in every rule editor. */
	public static function get_range_help_text() : string {
		return __( 'اگر روزی انتخاب نکنید، بازه در تمام روزهای هفته اجرا می‌شود.', 'ma-co-access-control-pro' );
	}

	/**
	 * Get the effective timezone for schedule checks.
	 *
	 * The options are intentionally supported here before the settings UI is added,
	 * so older saved rules keep working and phase-2 settings can plug into one place.
	 *
	 * @return \DateTimeZone
	 */
	public static function get_timezone() : \DateTimeZone {
		$source          = sanitize_key( get_option( 'ma_co_acp_time_source', 'site' ) );
		$custom_timezone = sanitize_text_field( get_option( 'ma_co_acp_time_timezone', '' ) );
		$custom_offset   = sanitize_text_field( get_option( 'ma_co_acp_time_offset', '' ) );

		if ( 'timezone' === $source && '' !== $custom_timezone ) {
			try {
				return new \DateTimeZone( $custom_timezone );
			} catch ( \Exception $e ) {
				// Fall through to offset/site timezone.
			}
		}

		if ( 'offset' === $source && '' !== $custom_offset && preg_match( '/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $custom_offset ) ) {
			return new \DateTimeZone( $custom_offset );
		}

		$timezone_string = wp_timezone_string();

		if ( ! empty( $timezone_string ) ) {
			return new \DateTimeZone( $timezone_string );
		}

		return wp_timezone();
	}

	/**
	 * Get the saved source for effective time.
	 *
	 * @return string
	 */
	public static function get_time_source() : string {
		$source  = sanitize_key( get_option( 'ma_co_acp_time_source', 'site' ) );
		$allowed = [ 'site', 'custom', 'timezone', 'offset' ];

		return in_array( $source, $allowed, true ) ? $source : 'site';
	}

	/**
	 * Get current time in the effective timezone.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function get_now() : \DateTimeImmutable {
		if ( 'custom' === self::get_time_source() ) {
			return self::get_custom_now();
		}

		return new \DateTimeImmutable( 'now', self::get_timezone() );
	}

	/**
	 * Check whether the effective time source should affect product-purchase validity.
	 *
	 * @return bool
	 */
	public static function applies_to_wc_validity() : bool {
		return (bool) absint( get_option( self::APPLY_TO_WC_VALIDITY_OPTION, 1 ) );
	}

	/**
	 * Get the timestamp used for WooCommerce product-purchase validity checks.
	 *
	 * @return int
	 */
	public static function get_wc_validity_now_timestamp() : int {
		if ( self::applies_to_wc_validity() ) {
			return self::get_now()->getTimestamp();
		}

		return time();
	}

	/**
	 * Get the moving custom time selected by the admin.
	 *
	 * The saved value is a simple HH:MM time. From the moment it is saved,
	 * elapsed real seconds are added so schedule calculations keep moving.
	 *
	 * @return \DateTimeImmutable
	 */
	private static function get_custom_now() : \DateTimeImmutable {
		$timezone     = self::get_timezone();
		$custom_value = self::normalize_time( sanitize_text_field( get_option( 'ma_co_acp_custom_time_value', '' ) ) );
		$set_at       = absint( get_option( 'ma_co_acp_custom_time_set_at', time() ) );

		if ( '' === $custom_value ) {
			return new \DateTimeImmutable( 'now', $timezone );
		}

		try {
			$elapsed = max( 0, time() - $set_at );
			$saved   = ( new \DateTimeImmutable( '@' . $set_at ) )->setTimezone( $timezone );
			$base    = new \DateTimeImmutable( $saved->format( 'Y-m-d' ) . ' ' . $custom_value, $timezone );

			return $base->setTimestamp( $base->getTimestamp() + $elapsed );
		} catch ( \Exception $e ) {
			return new \DateTimeImmutable( 'now', $timezone );
		}
	}

	/**
	 * Get current schedule data.
	 *
	 * @return array{timestamp:int,day:string,day_label:string,time:string,seconds:string,timezone:string}
	 */
	public static function get_current_time() : array {
		$now = self::get_now();

		return [
			'timestamp' => $now->getTimestamp(),
			'day'       => strtolower( $now->format( 'l' ) ),
			'day_label' => self::get_persian_day( strtolower( $now->format( 'l' ) ) ),
			'time'      => $now->format( 'H:i' ),
			'seconds'   => $now->format( 's' ),
			'timezone'  => self::get_timezone()->getName(),
		];
	}

	/**
	 * Check if now is inside a range. Supports overnight ranges such as 22:00-02:00.
	 *
	 * @param string $start_time Start time in HH:MM.
	 * @param string $end_time End time in HH:MM.
	 * @param array  $days Selected weekday keys. Empty means all days.
	 * @return bool
	 */
	public static function is_in_time_range( string $start_time, string $end_time, array $days = [] ) : bool {
		$start_time = self::normalize_time( $start_time );
		$end_time   = self::normalize_time( $end_time );

		if ( '' === $start_time || '' === $end_time ) {
			return false;
		}

		$now      = self::get_now();
		$start_dt = $now->modify( 'today ' . $start_time );
		$end_dt   = $now->modify( 'today ' . $end_time );

		if ( false === $start_dt || false === $end_dt ) {
			return false;
		}

		$days        = self::sanitize_days( $days );
		$current_day = strtolower( $now->format( 'l' ) );

		if ( $start_dt <= $end_dt ) {
			if ( ! empty( $days ) && ! in_array( $current_day, $days, true ) ) {
				return false;
			}

			return $now >= $start_dt && $now < $end_dt;
		}

		$in_start_day = $now >= $start_dt;
		$in_next_day  = $now < $end_dt;

		if ( empty( $days ) ) {
			return $in_start_day || $in_next_day;
		}

		if ( $in_start_day && in_array( $current_day, $days, true ) ) {
			return true;
		}

		$previous_day = self::get_previous_day( $current_day );

		return $in_next_day && in_array( $previous_day, $days, true );
	}

	/**
	 * Check if any saved range is active now.
	 *
	 * @param array $ranges Saved time ranges.
	 * @return bool
	 */
	public static function is_in_any_range( array $ranges ) : bool {
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) ) {
				continue;
			}

			if ( self::is_in_time_range( $range['start'] ?? '', $range['end'] ?? '', $range['days'] ?? [] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find the currently active range for admin display.
	 *
	 * @param array $ranges Saved time ranges.
	 * @return array|null
	 */
	public static function find_current_range( array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) ) {
				continue;
			}

			if ( self::is_in_time_range( $range['start'] ?? '', $range['end'] ?? '', $range['days'] ?? [] ) ) {
				return $range;
			}
		}

		return null;
	}

	/**
	 * Find the next timestamp where access should open based on schedule only.
	 *
	 * @param string $mode Restriction mode: allow|restrict.
	 * @param array  $ranges Saved time ranges.
	 * @return int
	 */
	public static function get_next_access_timestamp( string $mode, array $ranges ) : int {
		return self::get_next_schedule_timestamp( $mode, $ranges, false );
	}

	/** Next change of the merged schedule, including the end of an open window. */
	public static function get_next_state_timestamp( array $ranges ) : int {
		return self::get_next_schedule_timestamp( 'allow', $ranges, true );
	}

	private static function get_next_schedule_timestamp( string $mode, array $ranges, bool $next_change ) : int {
		if ( empty( $ranges ) ) {
			return 0;
		}

		$now       = self::get_now();
		$now_ts    = $now->getTimestamp();
		$horizon   = $now->modify( '+7 days' )->getTimestamp();
		$intervals = [];

		// Include yesterday's overnight ranges and a complete recurring week.
		for ( $offset = -1; $offset <= 8; ++$offset ) {
			$day = $now->modify( $offset . ' days' );
			foreach ( $ranges as $range ) {
				if ( ! is_array( $range ) ) { continue; }
				$start = self::normalize_time( $range['start'] ?? '' );
				$end   = self::normalize_time( $range['end'] ?? '' );
				$days  = self::sanitize_days( is_array( $range['days'] ?? null ) ? $range['days'] : [] );
				if ( '' === $start || '' === $end || $start === $end || ( $days && ! in_array( strtolower( $day->format( 'l' ) ), $days, true ) ) ) { continue; }
				$from = new \DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $start, $now->getTimezone() );
				$end_day = $start > $end ? $day->modify( '+1 day' ) : $day;
				$to = new \DateTimeImmutable( $end_day->format( 'Y-m-d' ) . ' ' . $end, $now->getTimezone() );
				if ( $to > $from ) { $intervals[] = [ $from->getTimestamp(), $to->getTimestamp() ]; }
			}
		}
		usort( $intervals, static function ( $a, $b ) { return $a[0] <=> $b[0]; } );
		$merged = [];
		foreach ( $intervals as $interval ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $interval[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $interval[1] );
			} else { $merged[] = $interval; }
		}
		foreach ( $merged as $interval ) {
			if ( $now_ts >= $interval[0] && $now_ts < $interval[1] ) {
				return ( $next_change || 'restrict' === $mode ) && $interval[1] <= $horizon ? $interval[1] : 0;
			}
			if ( $interval[0] > $now_ts && ( $next_change || 'restrict' !== $mode ) && $interval[0] <= $horizon ) { return $interval[0]; }
		}
		return 0;
	}


	/**
	 * Weekday labels.
	 *
	 * @return array<string,string>
	 */
	public static function get_all_days() : array {
		return [
			'saturday'  => __( 'شنبه', 'ma-co-access-control-pro' ),
			'sunday'    => __( 'یکشنبه', 'ma-co-access-control-pro' ),
			'monday'    => __( 'دوشنبه', 'ma-co-access-control-pro' ),
			'tuesday'   => __( 'سه‌شنبه', 'ma-co-access-control-pro' ),
			'wednesday' => __( 'چهارشنبه', 'ma-co-access-control-pro' ),
			'thursday'  => __( 'پنج‌شنبه', 'ma-co-access-control-pro' ),
			'friday'    => __( 'جمعه', 'ma-co-access-control-pro' ),
		];
	}

	/**
	 * Get Persian weekday label.
	 *
	 * @param string $day Weekday key.
	 * @return string
	 */
	public static function get_persian_day( string $day ) : string {
		$days = self::get_all_days();
		$key  = strtolower( $day );

		return $days[ $key ] ?? $day;
	}

	/**
	 * Get previous weekday key.
	 *
	 * @param string $day Weekday key.
	 * @return string
	 */
	public static function get_previous_day( string $day ) : string {
		$map = [
			'sunday'    => 'saturday',
			'monday'    => 'sunday',
			'tuesday'   => 'monday',
			'wednesday' => 'tuesday',
			'thursday'  => 'wednesday',
			'friday'    => 'thursday',
			'saturday'  => 'friday',
		];

		$day = strtolower( $day );

		return $map[ $day ] ?? $day;
	}

	/**
	 * Normalize a time string to HH:MM.
	 *
	 * @param string $time Raw time.
	 * @return string
	 */
	public static function normalize_time( string $time ) : string {
		$time = trim( $time );

		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $matches ) ) {
			return '';
		}

		return sprintf( '%02d:%02d', (int) $matches[1], (int) $matches[2] );
	}

	/**
	 * Sanitize weekday keys.
	 *
	 * @param array $days Raw weekdays.
	 * @return array
	 */
	public static function sanitize_days( array $days ) : array {
		$allowed = array_keys( self::get_all_days() );
		$days    = array_map( 'sanitize_key', $days );

		return array_values( array_intersect( $allowed, $days ) );
	}
}
