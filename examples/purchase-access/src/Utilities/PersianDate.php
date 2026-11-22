<?php
/**
 * Small dependency-free Jalali date converter for admin-facing filters.
 *
 * @package MA_CO_AccessControlPro\Utilities
 */

namespace MA_CO_AccessControlPro\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PersianDate {
	/**
	 * Convert Persian and Arabic digits to ASCII digits.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_digits( $value ) {
		$value = (string) $value;
		return strtr(
			$value,
			[
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			]
		);
	}

	/**
	 * Parse a Jalali date in YYYY/MM/DD or YYYY-MM-DD form.
	 *
	 * @param string $value Date value.
	 * @return array{year:int,month:int,day:int}|false
	 */
	public static function parse( $value ) {
		$value = trim( self::normalize_digits( $value ) );
		$value = str_replace( '-', '/', $value );

		if ( ! preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $matches ) ) {
			return false;
		}

		$parts = [
			'year'  => (int) $matches[1],
			'month' => (int) $matches[2],
			'day'   => (int) $matches[3],
		];

		return self::is_valid( $parts['year'], $parts['month'], $parts['day'] ) ? $parts : false;
	}

	/**
	 * Validate a Jalali date using the converter round trip.
	 *
	 * @param int $year Jalali year.
	 * @param int $month Jalali month.
	 * @param int $day Jalali day.
	 * @return bool
	 */
	public static function is_valid( $year, $month, $day ) {
		$year  = (int) $year;
		$month = (int) $month;
		$day   = (int) $day;

		if ( $year < 1 || $year > 3177 || $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return false;
		}

		$gregorian = self::jalali_to_gregorian_parts( $year, $month, $day );
		$roundtrip = self::gregorian_to_jalali_parts( $gregorian[0], $gregorian[1], $gregorian[2] );

		return $roundtrip === [ $year, $month, $day ];
	}

	/**
	 * Convert a Jalali value to canonical Gregorian Y-m-d.
	 *
	 * @param string $value Jalali value.
	 * @return string|false
	 */
	public static function to_gregorian( $value ) {
		$parts = is_array( $value ) ? $value : self::parse( $value );
		if ( ! is_array( $parts ) || ! self::is_valid( $parts['year'], $parts['month'], $parts['day'] ) ) {
			return false;
		}

		$gregorian = self::jalali_to_gregorian_parts( $parts['year'], $parts['month'], $parts['day'] );
		return sprintf( '%04d-%02d-%02d', $gregorian[0], $gregorian[1], $gregorian[2] );
	}

	/**
	 * Convert canonical Gregorian Y-m-d to Jalali YYYY/MM/DD.
	 *
	 * @param string $value Gregorian date.
	 * @param string $separator Output separator.
	 * @return string|false
	 */
	public static function from_gregorian( $value, $separator = '/' ) {
		$value = trim( self::normalize_digits( $value ) );
		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches ) ) {
			return false;
		}

		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return false;
		}

		$jalali = self::gregorian_to_jalali_parts( $year, $month, $day );
		$separator = '/' === $separator ? '/' : (string) $separator;
		return sprintf( '%04d%s%02d%s%02d', $jalali[0], $separator, $jalali[1], $separator, $jalali[2] );
	}

	/**
	 * Format a DateTime value for visible Persian admin output.
	 *
	 * @param \DateTimeInterface $date Date value.
	 * @param bool               $include_time Include HH:MM when true.
	 * @return string
	 */
	public static function format_datetime( \DateTimeInterface $date, $include_time = true ) {
		$formatted = self::from_gregorian( $date->format( 'Y-m-d' ) );
		if ( false === $formatted ) {
			return $date->format( $include_time ? 'Y-m-d H:i' : 'Y-m-d' );
		}

		return $include_time ? $formatted . ' ' . $date->format( 'H:i' ) : $formatted;
	}

	/** Format the effective admin clock; keep the original timezone. */
	public static function format_clock( \DateTimeInterface $date ) : string {
		$timezone = $date->format( 'T' );
		if ( preg_match( '/^(?:GMT)?([+-])(\d{2})(\d{2})$/', $timezone, $parts ) ) {
			$timezone = 'UTC' . $parts[1] . $parts[2] . ':' . $parts[3];
		}
		$value = self::format_datetime( $date, false ) . '، ' . $date->format( 'H:i:s' ) . ( '' !== $timezone ? ' (' . $timezone . ')' : '' );
		return strtr( $value, array_combine( range( 0, 9 ), [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ] ) );
	}

	/**
	 * Convert Gregorian date parts to Jalali date parts.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	private static function gregorian_to_jalali_parts( $gy, $gm, $gd ) {
		$g_days_in_month = [ 0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		$gy -= 1600;
		$gd -= 1;
		$days = 365 * $gy + intdiv( $gy + 3, 4 ) - intdiv( $gy + 99, 100 ) + intdiv( $gy + 399, 400 );

		for ( $index = 1; $index < $gm; $index++ ) {
			$days += $g_days_in_month[ $index ];
		}

		if ( $gm > 2 && ( 0 === $gy % 4 || ( 0 === $gy % 100 && 0 !== $gy % 400 ) ) ) {
			$days++;
		}
		$days += $gd;
		$days -= 79;

		$jy = 979 + 33 * intdiv( $days, 12053 );
		$days %= 12053;
		$jy += 4 * intdiv( $days, 1461 );
		$days %= 1461;

		if ( $days > 365 ) {
			$jy += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		$jm = $days < 186 ? 1 + intdiv( $days, 31 ) : 7 + intdiv( $days - 186, 30 );
		$jd = 1 + ( $days < 186 ? $days % 31 : ( $days - 186 ) % 30 );

		return [ $jy, $jm, $jd ];
	}

	/**
	 * Convert Jalali date parts to Gregorian date parts.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	private static function jalali_to_gregorian_parts( $jy, $jm, $jd ) {
		$jy -= 979;
		$days = 365 * $jy + intdiv( $jy, 33 ) * 8 + intdiv( ( $jy % 33 ) + 3, 4 );
		$days += $jm < 7 ? ( $jm - 1 ) * 31 : ( $jm - 7 ) * 30 + 186;
		$days += $jd - 1;
		$days += 79;

		$gy = 1600 + 400 * intdiv( $days, 146097 );
		$days %= 146097;
		$leap = true;

		if ( $days >= 36525 ) {
			$days--;
			$gy += 100 * intdiv( $days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				$days++;
			} else {
				$leap = false;
			}
		}

		$gy += 4 * intdiv( $days, 1461 );
		$days %= 1461;

		if ( $days >= 366 ) {
			$leap = false;
			$days--;
			$gy += intdiv( $days, 365 );
			$days %= 365;
		}

		$gd = $days + 1;
		$g_days_in_month = [ 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		$gm = 1;

		while ( $gm <= 12 && $gd > $g_days_in_month[ $gm ] ) {
			$gd -= $g_days_in_month[ $gm ];
			$gm++;
		}

		return [ $gy, $gm, $gd ];
	}
}
