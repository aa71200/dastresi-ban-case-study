<?php
/** Explicit first-activation defaults with a compatible sparse-document boundary. */
namespace MA_CO_AccessControlPro\Features\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }
final class ElementorRuleDefaults {
	public static function fresh() {
		return array(
			'ma_co_acp_rule_defaults_version'     => '2',
			'ma_co_acp_rule_origin'               => 'fresh',
			'ma_co_acp_mode'                      => 'allow',
			'ma_co_acp_who'                       => 'everyone',
			'ma_co_acp_roles'                     => array(),
			'ma_co_acp_target_users'              => '',
			'ma_co_acp_specific_users'            => '',
			'ma_co_acp_wc_products'               => '',
			'ma_co_acp_wc_access_duration_mode'   => 'legacy',
			'ma_co_acp_wc_access_duration_preset' => '',
			'ma_co_acp_wc_access_duration_days'   => 0,
			'ma_co_acp_schedule_type'             => 'always',
			'ma_co_acp_time_ranges'               => array(),
			'ma_co_acp_meta_key'                  => '',
			'ma_co_acp_meta_value'                => '',
			'ma_co_acp_meta_compare'              => '=',
			'ma_co_acp_fallback_type'             => 'simple',
			'ma_co_acp_fallback_theme'            => 'dark',
			'ma_co_acp_fallback_message'          => '',
			'ma_co_acp_custom_html'               => '',
			'ma_co_acp_show_loading'              => 'yes',
			'ma_co_acp_loading_type'              => 'spinner',
			'ma_co_acp_custom_loading'            => '',
			'ma_co_acp_atomic_delivery'           => 'ajax',
		);
	}
	/** Read saved document data without re-entering Elementor control registration. */
	public static function origins( $post_id ) {
		$data = get_post_meta( (int) $post_id, '_elementor_data', true );
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true ); }
		$origins = array();
		$walk    = static function ( $elements ) use ( &$walk, &$origins ) {
			foreach ( (array) $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue; }
				if ( isset( $element['id'] ) ) {
					$origin = 'fresh';
					foreach ( (array) ( $element['settings'] ?? array() ) as $key => $value ) {
						if ( 0 === strpos( (string) $key, 'ma_co_acp_' ) ) {
							$origin = 'legacy';
							break; }
					}
					$origins[ (string) $element['id'] ] = $origin;
				}
				if ( ! empty( $element['elements'] ) ) {
					$walk( $element['elements'] ); }
			}
		};
		$walk( $data );
		return $origins;
	}
	public static function effective( array $settings, array $raw = null ) {
		$raw      = null === $raw ? $settings : $raw;
		$defaults = self::fresh();
		if ( '2' !== (string) ( $raw['ma_co_acp_rule_defaults_version'] ?? '' ) ) {
			$defaults['ma_co_acp_rule_defaults_version'] = '';
			$defaults['ma_co_acp_rule_origin']           = 'legacy';
			$defaults['ma_co_acp_who']                   = 'logged_in';
			$defaults['ma_co_acp_atomic_delivery']       = 'server';
		}
		// Do not let get_settings_for_display's schema defaults reinterpret omitted legacy fields.
		foreach ( $defaults as $key => $default ) {
			$settings[ $key ] = array_key_exists( $key, $raw ) && null !== $raw[ $key ] ? $raw[ $key ] : $default; }
		return $settings;
	}
}
