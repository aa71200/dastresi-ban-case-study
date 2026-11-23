<?php
/** Selected shared-control schema adapter from Dastresi-Ban 2.0.0. */
namespace MA_CO_AccessControlPro\Features\Elementor;

use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;

defined( 'ABSPATH' ) || exit;

final class ElementorAtomicAccessRegistrar {
	private static $definitions;

	/** Reuse the classic field definitions so both editors keep exactly one rule vocabulary. */
	private static function definitions() {
		if ( null !== self::$definitions ) { return self::$definitions; }
		$collector = new class {
			public $controls = [];
			public function add_control( $name, $definition ) { $this->controls[ $name ] = $definition; }
		};
		ElementorControlRegistrar::add_elementor_storage_controls( $collector );
		self::$definitions = $collector->controls;
		return self::$definitions;
	}

	public static function extend_schema( array $schema ) {
		foreach ( self::definitions() as $name => $definition ) {
			if ( \Elementor\Controls_Manager::RAW_HTML === $definition['type'] ) { continue; }
			$is_html = in_array( $name, [ 'ma_co_acp_custom_html', 'ma_co_acp_fallback_message' ], true );
			$type = 'ma_co_acp_custom_loading' === $name ? ElementorAtomicLoadingProp::make() : ( $is_html ? ElementorAtomicHtmlProp::make() : String_Prop_Type::make() );
			$default = $definition['default'] ?? '';
			$schema[ $name ] = $type->default( is_array( $default ) ? wp_json_encode( $default ) : (string) $default );
		}
		// Native creation materializes schema defaults; keep sparse legacy documents compatible.
        // First activation explicitly writes the versioned AJAX default in one history command.
		$schema['ma_co_acp_atomic_delivery'] = String_Prop_Type::make()->default( 'server' );
		return $schema;
	}
}
