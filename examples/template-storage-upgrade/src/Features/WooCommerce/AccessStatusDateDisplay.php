<?php
/** Typed presentation of purchase/expiry timestamps. Does not alter access decisions. */
namespace MA_CO_AccessControlPro\Features\WooCommerce;

use MA_CO_AccessControlPro\Utilities\PersianDate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class AccessStatusDateDisplay {

    public static function defaults() {
        return [ 'calendar' => 'auto', 'digits' => 'auto', 'date_style' => 'numeric', 'year' => true, 'month' => true, 'day' => true, 'hour_cycle' => '24', 'hour' => true, 'minute' => true, 'second' => false, 'period' => true ];
    }

    /** Sparse configuration: absence preserves the existing WordPress display. */
    public static function sanitize( $raw ) {
        if ( ! is_array( $raw ) ) { return []; }
        $clean = [];
        foreach ( [ 'calendar' => [ 'auto', 'jalali', 'gregorian' ], 'digits' => [ 'auto', 'persian', 'latin' ], 'date_style' => [ 'numeric', 'textual' ], 'hour_cycle' => [ '12', '24' ], 'visibility' => [ 'show', 'hide' ] ] as $key => $allowed ) {
            if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ) { $clean[ $key ] = $raw[ $key ]; }
        }
        foreach ( [ 'year', 'month', 'day', 'hour', 'minute', 'second', 'period' ] as $key ) {
            if ( isset( $raw[ $key ] ) && is_bool( $raw[ $key ] ) ) { $clean[ $key ] = $raw[ $key ]; }
        }
        return $clean;
    }

    public static function sanitize_map( $raw ) {
        $clean = [];
        foreach ( [ 'purchase_date', 'expiry_date' ] as $type ) {
            $config = self::sanitize( is_array( $raw ) ? ( $raw[ $type ] ?? [] ) : [] );
            if ( $config ) { $clean[ $type ] = $config; }
        }
        return $clean;
    }

    public static function hidden( array $config ) {
        if ( 'hide' === ( $config['visibility'] ?? '' ) ) { return true; }
        if ( ! array_diff( array_keys( $config ), [ 'visibility' ] ) ) { return false; }
        $config = array_merge( self::defaults(), $config );
        return ! ( $config['year'] || $config['month'] || $config['day'] || $config['hour'] || $config['minute'] || $config['second'] );
    }

    public static function format( $timestamp, array $config = [] ) {
        $timestamp = absint( $timestamp );
        $config = self::sanitize( $config );
        if ( ! $timestamp || self::hidden( $config ) ) { return ''; }
        // Visibility alone must not change the legacy date/time pattern.
        if ( ! array_diff( array_keys( $config ), [ 'visibility' ] ) ) { return WooCommerceAccessValidityService::format_timestamp( $timestamp ); }
        $config = array_merge( self::defaults(), $config );
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        $persian = 0 === strpos( (string) $locale, 'fa' );
        $jalali = 'jalali' === $config['calendar'] || ( 'auto' === $config['calendar'] && $persian );
        $date = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
        $parts = $jalali ? explode( '/', PersianDate::from_gregorian( $date->format( 'Y-m-d' ) ) ) : [ $date->format( 'Y' ), $date->format( 'm' ), $date->format( 'd' ) ];
        $labels = [ 'year' => $parts[0], 'month' => $parts[1], 'day' => $parts[2] ];
        if ( 'textual' === $config['date_style'] ) {
            $months = [ 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' ];
            $labels['month'] = $jalali ? $months[ (int) $parts[1] - 1 ] : wp_date( 'F', $timestamp, wp_timezone() );
            $labels['day'] = (string) (int) $parts[2];
        }
        $visible = [];
        foreach ( 'textual' === $config['date_style'] ? [ 'day', 'month', 'year' ] : [ 'year', 'month', 'day' ] as $key ) {
            if ( $config[ $key ] ) { $visible[] = $labels[ $key ]; }
        }
        $output = implode( 'textual' === $config['date_style'] ? ' ' : '/', $visible );
        $clock = [];
        foreach ( [ 'hour' => ( '12' === $config['hour_cycle'] ? 'g' : 'H' ), 'minute' => 'i', 'second' => 's' ] as $key => $pattern ) {
            if ( $config[ $key ] ) { $clock[] = $date->format( $pattern ); }
        }
        if ( $clock ) {
            $output .= ( '' !== $output ? ' ' : '' ) . implode( ':', $clock );
            if ( '12' === $config['hour_cycle'] && $config['period'] ) { $output .= ' ' . wp_date( 'a', $timestamp, wp_timezone() ); }
        }
        // Normalize first, so explicit Latin also works with localized month/period names.
        $output = str_replace( [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ], range( 0, 9 ), $output );
        if ( 'persian' === $config['digits'] || ( 'auto' === $config['digits'] && $persian ) ) {
            $output = str_replace( range( 0, 9 ), [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ], $output );
        }
        return $output;
    }
}
