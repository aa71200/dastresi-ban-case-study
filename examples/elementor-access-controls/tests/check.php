<?php
/** Minimal editor contracts for the focused example. */

declare(strict_types=1);

namespace Elementor {
	final class Controls_Manager {
		public const RAW_HTML = 'raw_html';
	}
}

namespace Elementor\Modules\AtomicWidgets\PropTypes\Primitives {
	class String_Prop_Type {
		public static function make(): static {
			return new static();
		}

		public function default( string $value ): array {
			return array(
				'type'    => static::class,
				'default' => $value,
			);
		}
	}
}

namespace MA_CO_AccessControlPro\Features\Elementor {
	use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;

	final class ElementorAtomicHtmlProp extends String_Prop_Type {}
	final class ElementorAtomicLoadingProp extends String_Prop_Type {}

	final class ElementorControlRegistrar {
		public static function add_elementor_storage_controls( object $element ): void {
			$element->add_control(
				'ma_co_acp_enabled',
				array(
					'type'    => 'switcher',
					'default' => 'no',
				)
			);
			$element->add_control(
				'ma_co_acp_target_users',
				array(
					'type'    => 'select',
					'default' => array(),
				)
			);
			$element->add_control( 'ma_co_acp_custom_html', array( 'type' => 'textarea' ) );
			$element->add_control( 'ma_co_acp_custom_loading', array( 'type' => 'select' ) );
			$element->add_control( 'ma_co_acp_heading', array( 'type' => \Elementor\Controls_Manager::RAW_HTML ) );
		}
	}
}

namespace {
	use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;
	use MA_CO_AccessControlPro\Features\Elementor\ElementorAtomicAccessRegistrar;
	use MA_CO_AccessControlPro\Features\Elementor\ElementorAtomicHtmlProp;
	use MA_CO_AccessControlPro\Features\Elementor\ElementorAtomicLoadingProp;
	use MA_CO_AccessControlPro\Features\Elementor\ElementorSettingsCodec;

	define( 'ABSPATH', __DIR__ );

	function wp_json_encode( $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR );
	}

	require __DIR__ . '/../src/class-elementor-atomic-access-registrar.php';
	require __DIR__ . '/../src/class-elementor-rule-defaults.php';
	require __DIR__ . '/../src/class-elementor-settings-codec.php';

	$classic = new class() {
		public function get_data(): array {
			return array(
				'widgetType' => 'heading',
				'settings'   => array( 'ma_co_acp_enabled' => 'yes', 'ma_co_acp_who' => 'logged_in' ),
			);
		}

		public function get_settings_for_display(): array {
			return array( 'title' => 'Private workshop' );
		}
	};
	$effective = ElementorSettingsCodec::element_settings( $classic );

	$schema = ElementorAtomicAccessRegistrar::extend_schema( array( 'heading' => array( 'native' => true ) ) );
	$checks = array(
		'Native schema fields remain present'            => array( 'native' => true ) === $schema['heading'],
		'Classic toggle uses the same default in Atomic' => array(
			'type'    => String_Prop_Type::class,
			'default' => 'no',
		) === $schema['ma_co_acp_enabled'],
		'Array defaults keep their serialized shape'     => '[]' === $schema['ma_co_acp_target_users']['default'],
		'HTML and loading use their own prop types'      => ElementorAtomicHtmlProp::class === $schema['ma_co_acp_custom_html']['type']
			&& ElementorAtomicLoadingProp::class === $schema['ma_co_acp_custom_loading']['type'],
		'Presentation-only headings are omitted from storage' => ! isset( $schema['ma_co_acp_heading'] ),
		'Sparse legacy documents retain server delivery' => 'server' === $schema['ma_co_acp_atomic_delivery']['default'],
		'Public Classic rendering retains stored access fields' => 'yes' === ( $effective['ma_co_acp_enabled'] ?? '' )
			&& 'logged_in' === $effective['ma_co_acp_who'] && 'Private workshop' === $effective['title'],
	);
	foreach ( $checks as $label => $pass ) {
		if ( ! $pass ) {
			throw new RuntimeException( $label );
		}
		echo "PASS: $label\n";
	}
}
