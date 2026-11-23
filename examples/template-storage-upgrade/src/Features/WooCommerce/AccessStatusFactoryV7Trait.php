<?php
/**
 * Current access-status designs. Historical factories live in the repository;
 * these definitions never inherit an older skin or an older block arrangement.
 *
 * @package MA_CO_AccessControlPro\Features\WooCommerce
 */

namespace MA_CO_AccessControlPro\Features\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** @internal Factory implementation, not a separate template API. */
trait AccessStatusFactoryV7Trait {

	private function build_factory_v7_templates() {
		$names = [
			'classic' => __( 'کلاسیک', 'ma-co-access-control-pro' ),
			'atlas' => __( 'اطلس (Atlas)', 'ma-co-access-control-pro' ),
			'aurora' => __( 'شفق (Aurora)', 'ma-co-access-control-pro' ),
			'pass' => __( 'کارت عبور (Pass)', 'ma-co-access-control-pro' ),
			'horizon' => __( 'افق (Horizon)', 'ma-co-access-control-pro' ),
			'velvet' => __( 'مخمل (Velvet)', 'ma-co-access-control-pro' ),
		];
		$result = [];
		foreach ( $names as $id => $name ) {
			$blocks = $this->factory_v7_blocks( $id );
			$result[ $id ] = [
				'id' => $id, 'name' => $name, 'origin' => 'factory', 'factory_id' => $id,
				'factory_revision' => 7, 'status' => 'active', 'archived_at' => 0, 'archive_expires_at' => 0,
				'mode' => 'blocks', 'blocks' => $blocks,
				'code' => [
					'product_html' => $this->factory_v7_code_canvas( $blocks['product'], false ),
					'message_html' => $this->factory_v7_code_canvas( $blocks['message'], true ),
					'css' => $this->factory_v7_css( $id ),
				],
				'styles' => $this->factory_v7_styles( $id ),
				'overrides' => [], 'states' => [], 'default_orientation' => [],
				'info_columns' => [ 'desktop' => 3, 'tablet' => 3, 'mobile' => 1 ],
				'orientations' => $this->factory_v7_orientations( $id ),
				'behavior' => AccessStatusTemplateSanitizer::get_default_behavior(),
			];
		}
		return $result;
	}

	private function factory_v7_palette( $id ) {
		$palettes = [
			'classic' => [ '#ffffff', '#172235', '#546278', '#2457d6', '#e1e7ef', '#f3f6fc', '#ffffff' ],
			'atlas' => [ '#101b2d', '#f2f6fc', '#adbed3', '#6bdad2', '#314057', '#19283d', '#101b2d' ],
			'aurora' => [ '#fafaff', '#24233b', '#626078', '#6553c7', '#e4e0f2', '#f0edfb', '#ffffff' ],
			'pass' => [ '#fffbf2', '#35291f', '#77614e', '#8b4b25', '#ddcdb8', '#f6edde', '#ffffff' ],
			'horizon' => [ '#ffffff', '#142d31', '#536e72', '#116d68', '#dbe7e6', '#edf6f4', '#ffffff' ],
			'velvet' => [ '#201d24', '#f9f4ea', '#c4bacb', '#d9bc82', '#49404f', '#2c2732', '#201d24' ],
		];
		return array_combine( [ 'surface', 'text', 'muted', 'accent', 'border', 'soft', 'button_text' ], $palettes[ $id ] );
	}

	private function factory_v7_blocks( $id ) {
		$icon = $this->block( 'status-icon', 'status_icon', [ 'aria_label' => __( 'وضعیت {status_label}', 'ma-co-access-control-pro' ) ] );
		$title = $this->block( 'product-title', 'product_title', [ 'tag' => 'h3' ] );
		$badge = $this->block( 'status-badge', 'status_badge' );
		$message = $this->block( 'message', 'message', [ 'text' => '{message}' ] );
		$remaining = $this->block( 'remaining-time', 'remaining_time', [ 'label' => __( 'باقی‌مانده', 'ma-co-access-control-pro' ) ] );
		$progress = $this->block( 'access-progress', 'access_progress', [
			'label' => __( 'اعتبار باقی‌مانده', 'ma-co-access-control-pro' ), 'show_label' => true, 'show_value' => true,
			'aria_label' => __( 'درصد اعتبار باقی‌مانده', 'ma-co-access-control-pro' ),
		] );
		$purchase = $this->block( 'purchase-date', 'purchase_date', [ 'label' => __( 'تاریخ خرید', 'ma-co-access-control-pro' ) ] );
		$expiry = $this->block( 'expiry-date', 'expiry_date', [ 'label' => __( 'انقضا', 'ma-co-access-control-pro' ) ] );
		$duration = $this->block( 'duration-days', 'duration_days', [ 'label' => __( 'مدت اعتبار', 'ma-co-access-control-pro' ) ] );
		$action = $this->block( 'action', 'button', [ 'label' => '{action_label}', 'url_source' => 'smart', 'class_name' => 'ma-co-acp-template-action' ] );
		$image = $this->block( 'product-image', 'image', [ 'source' => 'product', 'alt' => '{product_title}' ] );
		$product = [ $icon, $title, $badge, $message, $remaining, $progress, $purchase, $expiry, $duration, $action ];
		if ( in_array( $id, [ 'aurora', 'horizon' ], true ) ) { array_unshift( $product, $image ); }
		if ( 'pass' === $id ) {
			$product = [ $icon, $title, $badge, $purchase, $expiry, $duration, $message, $remaining, $progress, $action ];
		}
		$general = [
			$this->block( 'status-icon-message', 'status_icon', [ 'aria_label' => __( 'وضعیت {status_label}', 'ma-co-access-control-pro' ) ] ),
			$this->block( 'message-title', 'custom_html', [ 'html' => '<strong class="ma-co-acp-access-status__message-title">{message_title}</strong>' ] ),
			$this->block( 'message-text', 'message', [ 'text' => '{message}' ] ),
			$this->block( 'message-action', 'button', [ 'label' => '{action_label}', 'url_source' => 'smart', 'class_name' => 'ma-co-acp-template-action' ] ),
		];
		return [ 'product' => $product, 'message' => $general ];
	}

	private function factory_v7_styles( $id ) {
		$p = $this->factory_v7_palette( $id );
		$desktop = [
			'display' => in_array( $id, [ 'atlas', 'aurora', 'pass' ], true ) ? 'flex' : 'grid',
			'background_color' => $p['surface'], 'text_color' => $p['text'], 'muted_color' => $p['muted'], 'accent_color' => $p['accent'],
			'border_color' => $p['border'], 'border_width' => '1px', 'border_radius' => '20px', 'border_style' => 'solid',
			'padding' => '24px', 'gap' => '16px', 'font_size' => '14px', 'line_height' => '1.75',
			'box_shadow' => '0 8px 28px rgba(15,23,42,.06)',
			'title_font_size' => '22px', 'title_font_weight' => '800', 'message_font_size' => '14px', 'meta_font_size' => '14px',
			'button_background' => $p['accent'], 'button_color' => $p['button_text'], 'button_border_radius' => '10px',
			'button_font_size' => '14px', 'button_font_weight' => '700', 'button_padding' => '11px 18px',
			'status_icon_size' => '40px', 'status_icon_background' => $p['soft'], 'status_icon_border_radius' => '12px',
			'badge_font_size' => '12px', 'badge_font_weight' => '700', 'badge_border_radius' => '999px',
		];
		if ( 'pass' === $id ) { $desktop['border_radius'] = '12px'; $desktop['box_shadow'] = '0 6px 20px rgba(69,45,20,.05)'; }
		if ( 'aurora' === $id ) { $desktop['border_radius'] = '24px'; $desktop['image_size'] = '104px'; $desktop['image_aspect_ratio'] = '1/1'; $desktop['image_border_radius'] = '16px'; }
		if ( 'horizon' === $id ) { $desktop['image_size'] = '100%'; $desktop['image_aspect_ratio'] = '16/9'; $desktop['image_border_radius'] = '12px'; }
		if ( 'velvet' === $id ) { $desktop['border_radius'] = '24px'; $desktop['status_icon_size'] = '52px'; $desktop['status_icon_border_radius'] = '50%'; }
		if ( in_array( $id, [ 'aurora', 'horizon' ], true ) ) { $desktop['image_object_fit'] = 'cover'; }
		return [
			'desktop' => $desktop,
			'tablet' => [ 'padding' => '20px', 'gap' => '14px' ],
			'mobile' => [ 'padding' => '16px', 'gap' => '12px', 'title_font_size' => '20px' ],
		];
	}

	private function factory_v7_orientations( $id ) {
		$horizontal = $vertical = [ 'desktop' => [], 'tablet' => [], 'mobile' => [] ];
		if ( in_array( $id, [ 'aurora', 'horizon' ], true ) ) {
			foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
				$horizontal[ $device ] = [ 'image_size' => '100%', 'image_position' => 'start' ];
				$vertical[ $device ] = [ 'image_size' => 'horizon' === $id ? '100%' : '104px', 'image_position' => 'top' ];
			}
		}
		return [ 'horizontal' => [ 'styles' => $horizontal ], 'vertical' => [ 'styles' => $vertical ] ];
	}

	/** Compile editable HTML from the same ordered semantic blocks as the canvas. */
	private function factory_v7_code_canvas( array $blocks, $message_canvas ) {
		$html = '';
		$open = '';
		foreach ( $blocks as $block ) {
			$type = $block['type'];
			$group = '';
			if ( ! $message_canvas ) {
				if ( in_array( $type, [ 'status_icon', 'product_title', 'status_badge' ], true ) ) { $group = 'head'; }
				elseif ( 'remaining_time' === $type ) { $group = 'summary'; }
				elseif ( in_array( $type, [ 'purchase_date', 'expiry_date', 'duration_days' ], true ) ) { $group = 'meta'; }
			}
			if ( $group !== $open ) {
				if ( '' !== $open ) { $html .= 'head' === $open ? '</div>' : '</dl>'; }
				if ( '' !== $group ) {
					$html .= 'head' === $group ? '<div class="ma-co-acp-access-status__head">'
						: '<dl class="ma-co-acp-access-status__meta' . ( 'summary' === $group ? ' ma-co-acp-access-status__meta--summary' : ' ma-co-acp-access-status__meta--details' ) . '">';
				}
				$open = $group;
			}
			$class = 'ma-co-acp-access-status__block ma-co-acp-access-status__block--' . $type . ' ma-co-acp-access-status__block-id--' . $block['id'];
			if ( ! empty( $block['settings']['class_name'] ) ) { $class .= ' ' . $block['settings']['class_name']; }
			$attribute = ' class="' . $class . '"';
			switch ( $type ) {
				case 'status_icon':
					$html .= '<span' . $attribute . ' role="img" aria-label="' . esc_attr( $block['settings']['aria_label'] ) . '"></span>';
					break;
				case 'product_title': $html .= '<h3' . $attribute . '>{product_title}</h3>'; break;
				case 'status_badge': $html .= '<span' . $attribute . '>{status_label}</span>'; break;
				case 'message': $html .= '<p' . $attribute . '>{message}</p>'; break;
				case 'image': $html .= '<img' . $attribute . ' src="{product_image_url}" alt="{product_title}" loading="lazy">'; break;
				case 'button': $html .= '<a' . $attribute . ' href="{action_url}">{action_label}</a>'; break;
				case 'custom_html': $html .= '<div' . $attribute . '>' . $block['settings']['html'] . '</div>'; break;
				case 'access_progress':
					$html .= '<div' . $attribute . '><span class="ma-co-acp-access-status__progress-label"><span>' . esc_html( $block['settings']['label'] ) . '</span><strong>{remaining_percent}%</strong></span><progress max="100" value="{remaining_percent}" aria-label="' . esc_attr( $block['settings']['aria_label'] ) . '">{remaining_percent}%</progress></div>';
					break;
				default: $html .= '<div' . $attribute . '><dt>{' . $type . '_label}</dt><dd>{' . $type . '}</dd></div>';
			}
		}
		if ( '' !== $open ) { $html .= 'head' === $open ? '</div>' : '</dl>'; }
		return $message_canvas ? '<div class="ma-co-acp-access-status__message">' . $html . '</div>' : $html;
	}

	private function factory_v7_css( $id ) {
		$p = $this->factory_v7_palette( $id );
		$css = <<<'CSS'
.ma-co-acp-access-status__item,.ma-co-acp-access-status__message{position:relative;min-width:0;align-content:start;align-items:start;flex-wrap:wrap}
.ma-co-acp-access-status__item>*{min-width:0;max-width:100%}
.ma-co-acp-access-status__head{display:flex;flex-direction:row;align-items:center;flex-wrap:wrap;gap:12px;margin:0;width:100%;flex:1 1 180px}
.ma-co-acp-access-status__head .ma-co-acp-access-status__block--product_title{flex:1 1 130px;line-height:1.55;text-align:start}
.ma-co-acp-access-status__head .ma-co-acp-access-status__block--status_icon{flex:0 0 auto}
.ma-co-acp-access-status__block--status_badge{padding:5px 11px;line-height:1.5;border:1px solid transparent}
.ma-co-acp-access-status__block--message{line-height:1.85;flex:1 1 100%;width:100%}
.ma-co-acp-access-status__meta{width:100%;gap:12px;flex:1 1 100%}
.ma-co-acp-access-status__meta>div{display:block;flex-direction:row;min-width:0;padding:0}
.ma-co-acp-access-status__meta dt{font-size:12px;margin-bottom:5px;line-height:1.6}
.ma-co-acp-access-status__meta dd{font-weight:700;text-align:start;line-height:1.7;overflow-wrap:anywhere}
.ma-co-acp-access-status__meta--summary{display:block;padding:0;border:0}
.ma-co-acp-access-status__item.is-no_purchase .ma-co-acp-access-status__meta--summary,.ma-co-acp-access-status__item.is-invalid_duration .ma-co-acp-access-status__meta{display:none}
.ma-co-acp-access-status__meta--summary dd{font-size:2.15em;font-weight:800;line-height:1.45}
.ma-co-acp-access-status__block--access_progress{gap:8px;width:100%;flex:1 1 100%}
.ma-co-acp-access-status__progress-label{font-size:12px;line-height:1.6}
.ma-co-acp-access-status__progress-label strong{font-size:13px;font-weight:700}
.ma-co-acp-access-status__progress-track{height:6px}
.ma-co-acp-access-status__block--access_progress progress{appearance:none;border:0;border-radius:999px;height:6px;background:rgba(100,105,112,.16);overflow:hidden}
.ma-co-acp-access-status__block--access_progress progress::-webkit-progress-bar{background:rgba(100,105,112,.16);border-radius:999px}
.ma-co-acp-access-status__block--access_progress progress::-webkit-progress-value{background:var(--ma-co-acp-progress-color);border-radius:999px}
.ma-co-acp-access-status__block--access_progress progress::-moz-progress-bar{background:var(--ma-co-acp-progress-color);border-radius:999px}
.ma-co-acp-access-status__block--button{min-height:44px;justify-self:start;line-height:1.6;text-align:center}
.ma-co-acp-access-status__block--button:focus-visible{outline:3px solid currentColor;outline-offset:3px}
.ma-co-acp-access-status__block--button:hover{text-decoration:underline}
.ma-co-acp-access-status__message{flex-direction:column;flex-wrap:nowrap;justify-items:start}
.ma-co-acp-access-status__message>.ma-co-acp-access-status__block{flex:0 1 auto;max-width:100%;min-width:0}
.ma-co-acp-access-status__message>.ma-co-acp-access-status__block--custom_html{width:100%;line-height:1.55}
.ma-co-acp-access-status__message-title{display:block;font-weight:800}
.ma-co-acp-access-status__item.is-active .ma-co-acp-access-status__block--status_badge,.ma-co-acp-access-status__item.is-unlimited .ma-co-acp-access-status__block--status_badge{background:#e6f4ed;color:#17633f}
.ma-co-acp-access-status__item.is-expiring-soon .ma-co-acp-access-status__block--status_badge{background:#fff0ce;color:#81510a}
.ma-co-acp-access-status__item.is-expired .ma-co-acp-access-status__block--status_badge{background:#fce8e8;color:#a12732}
.ma-co-acp-access-status__item.is-no_purchase .ma-co-acp-access-status__block--status_badge,.ma-co-acp-access-status__item.is-invalid_duration .ma-co-acp-access-status__block--status_badge{background:#edf0f5;color:#4c586b}
CSS;
		$css .= '.ma-co-acp-access-status__meta:not(.ma-co-acp-access-status__meta--summary){border-top:1px solid ' . $p['border'] . ';padding-top:16px}'
			. '.ma-co-acp-access-status__progress-label{color:' . $p['muted'] . '}'
			. '.ma-co-acp-access-status__progress-label strong{color:' . $p['text'] . '}'
			. '.ma-co-acp-access-status__block--button:focus-visible{outline:3px solid ' . $p['accent'] . ';outline-offset:3px}';
		if ( 'classic' === $id ) {
			$css .= '.ma-co-acp-access-status__head{padding-bottom:16px;border-bottom:1px solid ' . $p['border'] . '}';
		} elseif ( 'atlas' === $id ) {
			$css .= '.ma-co-acp-access-status__head{order:0;flex-basis:100%}.ma-co-acp-access-status__block--message{order:1}'
				. '.ma-co-acp-access-status__meta--summary{order:2;flex:1 1 130px;width:auto;align-self:center}'
				. '.ma-co-acp-access-status__meta:not(.ma-co-acp-access-status__meta--summary){order:3;flex:2 1 200px;width:auto;display:block;padding:0;border:0}'
				. '.ma-co-acp-access-status__meta:not(.ma-co-acp-access-status__meta--summary)>div{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;padding:9px 0;border-bottom:1px solid ' . $p['border'] . '}'
				. '.ma-co-acp-access-status__meta dt{margin:0}.ma-co-acp-access-status__block--access_progress{order:4}.ma-co-acp-access-status__block--button{order:5;margin-inline-start:auto}'
				. '.ma-co-acp-access-status__message{border-top:3px solid ' . $p['accent'] . '}';
		} elseif ( 'aurora' === $id ) {
			$css .= '.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__block--image{flex:0 1 var(--ma-co-acp-image-size,104px)}'
				. '.ma-co-acp-access-status__item.has-product-image>.ma-co-acp-access-status__head{flex:1 1 160px;width:auto;align-self:center}'
				. '.ma-co-acp-access-status__meta--summary{background:' . $p['soft'] . ';border-radius:16px;padding:14px 18px}'
				. '.ma-co-acp-access-status__block--button{width:100%}';
		} elseif ( 'pass' === $id ) {
			$css .= '.ma-co-acp-access-status__head{flex:1 1 170px;align-self:start;align-items:start}'
				. '.ma-co-acp-access-status__meta:not(.ma-co-acp-access-status__meta--summary){flex:1 1 200px;width:auto;display:block;border-top:0;padding:0}'
				. '.ma-co-acp-access-status__meta:not(.ma-co-acp-access-status__meta--summary)>div{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;padding:5px 0}'
				. '.ma-co-acp-access-status__meta dt{margin:0}.ma-co-acp-access-status__block--message{border-top:1px dashed ' . $p['border'] . ';padding-top:16px}'
				. '.ma-co-acp-access-status__meta--summary>div{display:flex;flex-wrap:wrap;align-items:baseline;gap:12px;justify-content:space-between}'
				. '.ma-co-acp-access-status__block--button{margin-inline-start:auto}.ma-co-acp-access-status__message{border-top:3px solid ' . $p['accent'] . '}';
		} elseif ( 'horizon' === $id ) {
			$css .= '.ma-co-acp-access-status__block--button{width:100%}.ma-co-acp-access-status__message{border-inline-start-width:4px;border-inline-start-color:' . $p['accent'] . '}';
		} else {
			$css .= '.ma-co-acp-access-status__head{flex-direction:column;text-align:center}.ma-co-acp-access-status__head .ma-co-acp-access-status__block--product_title{flex:0 1 auto;text-align:center}'
				. '.ma-co-acp-access-status__block--message{text-align:center}.ma-co-acp-access-status__meta--summary dd,.ma-co-acp-access-status__meta--summary dt{text-align:center}'
				. '.ma-co-acp-access-status__meta:not(.ma-co-acp-access-status__meta--summary){display:block}'
				. '.ma-co-acp-access-status__meta:not(.ma-co-acp-access-status__meta--summary)>div{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;padding:9px 0;border-bottom:1px solid ' . $p['border'] . '}'
				. '.ma-co-acp-access-status__meta dt{margin:0}.ma-co-acp-access-status__block--button{width:100%}'
				. '.ma-co-acp-access-status__message{text-align:center;justify-items:center;align-items:center}';
		}
		return $css;
	}
}
