<?php
/** The boundary between classic settings and Atomic typed properties. */
namespace MA_CO_AccessControlPro\Features\Elementor;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ElementorSettingsCodec {

	public static function is_atomic_data( array $data ) {
		return 0 === strpos( (string) ( $data['widgetType'] ?? $data['elType'] ?? '' ), 'e-' );
	}

	public static function is_rule_element( array $data ) {
		return 'widget' === ( $data['elType'] ?? '' ) || self::is_atomic_data( $data );
	}

	public static function unwrap( $value, $depth = 0 ) {
		if ( $depth > 12 ) { return null; }
		if ( ! is_array( $value ) ) { return $value; }
		if ( isset( $value['$$type'] ) ) {
			if ( ! in_array( $value['$$type'], [ 'string', 'number', 'boolean', 'html', 'escaped-html', 'array', 'object' ], true ) || ! array_key_exists( 'value', $value ) ) { return null; }
			return self::unwrap( $value['value'], $depth + 1 );
		}
		$result = [];
		foreach ( $value as $key => $item ) { $result[ $key ] = self::unwrap( $item, $depth + 1 ); }
		return $result;
	}

	public static function normalize( array $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'ma_co_acp_' ) ) { continue; }
			$clean = self::unwrap( $value );
			if ( self::invalid_type( $value ) || ( null === $clean && null !== $value ) ) { $settings['ma_co_acp_invalid_rule'] = true; }
			$settings[ $key ] = $clean;
		}
		foreach ( [ 'ma_co_acp_roles', 'ma_co_acp_time_ranges' ] as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) { continue; }
			$decoded = json_decode( $settings[ $key ], true );
			if ( is_array( $decoded ) ) { $settings[ $key ] = $decoded; }
			elseif ( 'ma_co_acp_roles' === $key ) { $settings[ $key ] = array_values( array_filter( array_map( 'trim', explode( ',', $settings[ $key ] ) ) ) ); }
			elseif ( '' !== trim( $settings[ $key ] ) ) { $settings['ma_co_acp_invalid_rule'] = true; $settings[ $key ] = []; }
		}
		foreach ( [ 'ma_co_acp_enabled', 'ma_co_acp_show_loading' ] as $key ) {
			if ( isset( $settings[ $key ] ) ) { $settings[ $key ] = in_array( $settings[ $key ], [ true, 'yes' ], true ) ? 'yes' : ''; }
		}
		return $settings;
	}

	private static function invalid_type( $value, $depth = 0 ) {
		if ( $depth > 12 ) { return true; }
		if ( ! is_array( $value ) ) { return false; }
		if ( isset( $value['$$type'] ) && ( ! in_array( $value['$$type'], [ 'string', 'number', 'boolean', 'html', 'escaped-html', 'array', 'object' ], true ) || ! array_key_exists( 'value', $value ) ) ) { return true; }
		foreach ( $value as $item ) { if ( self::invalid_type( $item, $depth + 1 ) ) { return true; } }
		return false;
	}

	/** Keep lifecycle/Quick Edit writes typed without rewriting other Atomic props. */
	public static function restore_types( array $settings, array $original ) {
		foreach ( $settings as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'ma_co_acp_' ) ) { continue; }
			if ( isset( $original[ $key ]['$$type'] ) ) {
				$type = $original[ $key ]['$$type'];
				$settings[ $key ] = [ '$$type' => $type, 'value' => is_array( $value ) && 'string' === $type ? wp_json_encode( $value ) : $value ];
			} elseif ( self::has_typed_rule( $original ) ) {
				$settings[ $key ] = [ '$$type' => 'string', 'value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value ];
			}
		}
		unset( $settings['ma_co_acp_invalid_rule'] );
		return $settings;
	}

	public static function has_typed_rule( array $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'ma_co_acp_' ) && is_array( $value ) && isset( $value['$$type'] ) ) { return true; }
		}
		return false;
	}

    public static function element_settings( $element ) {
        $data = method_exists( $element, 'get_data' ) ? $element->get_data() : [];
        $raw = self::normalize( (array) ( $data['settings'] ?? [] ) );
        if ( is_array( $data ) && self::is_atomic_data( $data ) ) { return ElementorRuleDefaults::effective( $raw ); }
        $display = self::normalize( (array) $element->get_settings_for_display() );
        // Public renders may omit controls that are only registered in the editor.
        // Persisted access fields must still govern the decision in that request.
        foreach ( $raw as $key => $value ) {
            if ( 0 === strpos( (string) $key, 'ma_co_acp_' ) ) {
                $display[ $key ] = $value;
            }
        }
        return ElementorRuleDefaults::effective( $display, is_array( $data ) && array_key_exists( 'settings', $data ) ? $raw : $display );
    }
}
