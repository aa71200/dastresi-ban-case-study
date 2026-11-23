<?php
/** Sparse instance deltas for the semantic parts already present in a template. */
namespace MA_CO_AccessControlPro\Features\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class AccessStatusLocalParts {
    private const TYPES = [ 'status_icon', 'image', 'product_title', 'status_badge', 'message', 'message_title', 'purchase_date', 'expiry_date', 'remaining_time', 'duration_days', 'access_progress', 'button' ];

    /** One capability contract for the inspector and untrusted stored deltas. */
    public static function capabilities() {
        $result = [];
        $box = [ 'background-color', 'margin', 'padding', 'visibility' ];
        $text = [ 'color', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing' ];
        foreach ( self::TYPES as $type ) {
            foreach ( [ 'main', 'label', 'value' ] as $slot ) {
                $properties = array_merge( $box, $text );
                if ( 'main' === $slot && 'image' === $type ) { $properties = $box; }
                if ( 'main' === $slot && 'status_icon' === $type ) { $properties = array_merge( $box, [ 'color', 'font-size' ] ); }
                if ( 'main' === $slot && 'access_progress' === $type ) { $properties = array_merge( $box, [ 'color' ] ); }
                $result[ $type ][ $slot ] = [ 'text' => self::text_allowed( $type, $slot ), 'properties' => $properties ];
            }
        }
        return $result;
    }

    public static function sanitize( $raw ) {
        if ( is_string( $raw ) ) { $raw = strlen( $raw ) <= 65536 ? json_decode( $raw, true ) : []; }
        $clean = [];
        $capabilities = self::capabilities();
        foreach ( array_slice( is_array( $raw ) ? $raw : [], 0, 200, true ) as $key => $delta ) {
            if ( ! preg_match( '~^[a-zA-Z0-9_-]{1,80}/(?:product|message)/[a-zA-Z0-9_-]{1,80}/([a-z_]+)/(main|label|value)$~', (string) $key, $match ) || ! in_array( $match[1], self::TYPES, true ) || ! is_array( $delta ) ) { continue; }
            $entry = [];
            if ( 'value' === $match[2] && in_array( $match[1], [ 'purchase_date', 'expiry_date' ], true ) ) {
                $date = AccessStatusDateDisplay::sanitize( $delta['date_display'] ?? [] );
                if ( $date ) { $entry['date_display'] = $date; }
            }
            if ( array_key_exists( 'text', $delta ) && self::text_allowed( $match[1], $match[2] ) && is_scalar( $delta['text'] ) ) { $entry['text'] = sanitize_textarea_field( substr( (string) $delta['text'], 0, 4000 ) ); }
            foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
                foreach ( (array) ( $delta[ $device ] ?? [] ) as $property => $value ) {
                    if ( ! is_scalar( $value ) || ! in_array( $property, $capabilities[ $match[1] ][ $match[2] ]['properties'], true ) ) { continue; }
                    $value = trim( (string) $value ); $valid = false;
                    if ( in_array( $property, [ 'color', 'background-color' ], true ) ) { $valid = (bool) preg_match( '/^(?:#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})|transparent|inherit|currentColor)$/', $value ); }
                    elseif ( in_array( $property, [ 'font-size', 'line-height', 'letter-spacing', 'margin', 'padding' ], true ) ) { $valid = self::valid_dimension( $property, $value ); }
                    elseif ( 'font-weight' === $property ) { $valid = (bool) preg_match( '/^(?:[1-9]00|normal|bold)$/', $value ); }
                    elseif ( 'font-family' === $property ) { $valid = strlen( $value ) <= 100 && (bool) preg_match( '/^[\p{L}\p{N}\s,_-]+$/u', $value ); }
                    elseif ( 'visibility' === $property ) { $valid = in_array( $value, [ 'show', 'hide' ], true ); }
                    if ( $valid ) { $entry[ $device ][ $property ] = $value; }
                }
            }
            if ( $entry ) { $clean[ $key ] = $entry; }
        }
        return $clean;
    }

    private static function valid_dimension( $property, $value ) {
        $number = '(?:\d{1,3}(?:\.\d{1,3})?|\.\d{1,3})';
        $length = '(?:' . $number . '(?:px|em|rem|%|vh|vw)|0(?:\.0{1,3})?)';
        if ( 'line-height' === $property ) { return (bool) preg_match( '/^' . $number . '(?:px|em|rem|%|vh|vw)?$/', $value ); }
        if ( in_array( $property, [ 'letter-spacing', 'margin' ], true ) ) { $length = '-?' . $length; }
        $pattern = $length;
        if ( in_array( $property, [ 'margin', 'padding' ], true ) ) { $pattern .= '(?:\s+' . $length . '){0,3}'; }
        return (bool) preg_match( '/^' . $pattern . '$/', $value );
    }

    private static function text_allowed( $type, $slot ) {
        return 'label' === $slot || ( 'main' === $slot && in_array( $type, [ 'product_title', 'status_badge', 'message', 'message_title', 'button', 'image' ], true ) );
    }

    /** Preserve authored variables in code/linked parts before token expansion. */
    public static function prepare_text( $html, array $tokens ) {
        if ( ! class_exists( '\DOMDocument' ) ) { return $html; }
        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        try { $loaded = $dom->loadHTML( '<?xml encoding="UTF-8"><div id="acp-text-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET ); }
        finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
        $root = $dom->getElementById( 'acp-text-root' );
        if ( ! $loaded || ! $root ) { return $html; }
        $xpath = new \DOMXPath( $dom );
        foreach ( $xpath->query( './/*[@class]', $root ) as $node ) {
            if ( ! preg_match( '/(?:^|\s)ma-co-acp-access-status__(?:block--(?:product_title|status_badge|message|image|button)|message-title)(?:\s|$)/', $node->getAttribute( 'class' ) ) ) { continue; }
            $raw = 'img' === $node->tagName ? $node->getAttribute( 'alt' ) : $node->textContent;
            $raw = strtr( $raw, array_intersect_key( $tokens, array_flip( [ '{message}', '{message_title}', '{status_label}', '{action_label}', '{product_action_label}', '{account_action_label}', '{product_title}' ] ) ) );
            $node->setAttribute( 'data-acp-part-inherited-encoded', base64_encode( $raw ) );
        }
        $output = '';
        foreach ( $root->childNodes as $child ) { $output .= $dom->saveHTML( $child ); }
        return $output;
    }

    /** Mark only semantic nodes. Authored HTML outside these markers stays central. */
    public static function render( $html, $template_id, array $view ) {
        if ( '' === $html || empty( $view['instance_scope'] ) || ! class_exists( '\DOMDocument' ) ) { return $html; }
        $dom = new \DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        try { $loaded = $dom->loadHTML( '<?xml encoding="UTF-8"><div id="acp-local-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET ); }
        finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
        if ( ! $loaded ) { return preg_replace( '/\sdata-acp-part-token-map(?:-encoded)?="[^"]*"/', '', $html ); }
        $xpath = new \DOMXPath( $dom ); $root = $dom->getElementById( 'acp-local-root' );
        if ( ! $root ) { return preg_replace( '/\sdata-acp-part-token-map(?:-encoded)?="[^"]*"/', '', $html ); }
        $parts = self::sanitize( $view['instance_overrides']['parts'] ?? [] );
        $css = []; $serial = [];
        $nodes = $xpath->query( './/*[@class]', $root );
        foreach ( $nodes as $node ) {
            if ( $node->hasAttribute( 'data-acp-part-inherited-encoded' ) ) {
                $node->setAttribute( 'data-acp-part-inherited-text', (string) base64_decode( $node->getAttribute( 'data-acp-part-inherited-encoded' ), true ) );
                $node->removeAttribute( 'data-acp-part-inherited-encoded' );
            }
            $classes = $node->getAttribute( 'class' ); $type = '';
            if ( preg_match( '/(?:^|\s)ma-co-acp-access-status__block--([a-z_]+)(?:\s|$)/', $classes, $match ) && in_array( $match[1], self::TYPES, true ) ) { $type = $match[1]; }
            elseif ( preg_match( '/(?:^|\s)ma-co-acp-access-status__message-title(?:\s|$)/', $classes ) ) { $type = 'message_title'; }
            if ( ! $type ) { continue; }
            $canvas = in_array( $view['state'] ?? '', [ 'guest', 'empty', 'inactive' ], true ) ? 'message' : 'product'; $identity = ''; $anchor = '';
            if ( preg_match( '/^[a-zA-Z0-9_-]{1,80}$/', $node->getAttribute( 'data-acp-part-id' ) ) ) { $identity = $node->getAttribute( 'data-acp-part-id' ); }
            if ( preg_match( '/ma-co-acp-access-status__block-id--([a-zA-Z0-9_-]+)/', $classes, $id ) ) { $identity = $id[1]; }
            for ( $parent = $node; $parent instanceof \DOMElement && $parent !== $root; $parent = $parent->parentNode ) {
                $parent_classes = $parent->getAttribute( 'class' );
                if ( preg_match( '/(?:^|\s)ma-co-acp-access-status__item(?:\s|$)/', $parent_classes ) ) { $anchor = $parent->getNodePath(); }
                if ( preg_match( '/(?:^|\s)ma-co-acp-access-status__message(?:\s|$)/', $parent_classes ) && 'p' !== $parent->tagName ) { $canvas = 'message'; }
                if ( 'elementor-field' === $identity && preg_match( '/elementor-widget-ma_co_acp_access_(?:field|part_[a-z_]+)/', $parent_classes ) && preg_match( '/(?:^|\s)elementor-element-([a-zA-Z0-9_-]+)/', $parent_classes, $id ) ) { $identity = $id[1]; }
                if ( ! $identity && preg_match( '/(?:^|\s)elementor-element-([a-zA-Z0-9_-]+)/', $parent_classes, $id ) ) { $identity = $id[1]; }
            }
            if ( ! $identity ) { $serial_key = $canvas . $type . $anchor; $index = $serial[ $serial_key ] ?? 0; $serial[ $serial_key ] = $index + 1; $identity = 'marked-' . $type . '-' . $index; }
            $base = $template_id . '/' . $canvas . '/' . $identity . '/' . $type . '/';
            self::apply( $node, $base . 'main', $type, 'main', $parts, $css, $view );
            if ( in_array( $type, [ 'purchase_date', 'expiry_date', 'remaining_time', 'duration_days' ], true ) ) {
                foreach ( [ 'dt' => 'label', 'dd' => 'value' ] as $tag => $slot ) {
                    foreach ( $xpath->query( './/' . $tag, $node ) as $child ) { self::apply( $child, $base . $slot, $type, $slot, $parts, $css, $view ); }
                }
            } elseif ( 'access_progress' === $type ) {
                foreach ( [ './/*[contains(@class,"__progress-label")]/span' => 'label', './/*[contains(@class,"__progress-label")]/strong' => 'value' ] as $query => $slot ) {
                    foreach ( $xpath->query( $query, $node ) as $child ) { self::apply( $child, $base . $slot, $type, $slot, $parts, $css, $view ); }
                }
            }
        }
        // Token context is request-local implementation data, never public markup.
        foreach ( $xpath->query( './/*[@data-acp-part-token-map or @data-acp-part-token-map-encoded]', $root ) as $context_node ) { $context_node->removeAttribute( 'data-acp-part-token-map' ); $context_node->removeAttribute( 'data-acp-part-token-map-encoded' ); }
        $output = '';
        foreach ( $root->childNodes as $child ) { $output .= $dom->saveHTML( $child ); }
        return ( $css ? '<style>' . implode( '', $css ) . '</style>' : '' ) . $output;
    }

    private static function apply( \DOMElement $node, $key, $type, $slot, array $parts, array &$css, array $view ) {
        $class = 'acp-part-' . substr( hash( 'sha256', $view['instance_scope'] . '|' . $key ), 0, 16 );
        $node->setAttribute( 'class', $node->getAttribute( 'class' ) . ' ' . $class );
        $node->setAttribute( 'data-acp-part', $key );
        $node->setAttribute( 'data-acp-part-type', $type );
        $node->setAttribute( 'data-acp-part-slot', $slot );
        $node->setAttribute( 'data-acp-part-text', self::text_allowed( $type, $slot ) ? 'yes' : 'no' );
        // Capture inherited content before applying a local delta, including image alt.
        if ( self::text_allowed( $type, $slot ) ) {
            $image = 'img' === $node->tagName ? $node : $node->getElementsByTagName( 'img' )->item( 0 );
            $inherited = 'image' === $type && $image instanceof \DOMElement ? $image->getAttribute( 'alt' ) : $node->textContent;
            if ( ! $node->hasAttribute( 'data-acp-part-inherited-text' ) ) { $node->setAttribute( 'data-acp-part-inherited-text', $inherited ); }
        }
        $delta = $parts[ $key ] ?? [];
        if ( array_key_exists( 'text', $delta ) ) {
            $text = $delta['text'];
            for ( $owner = $node; $owner instanceof \DOMElement; $owner = $owner->parentNode ) {
                if ( $owner->hasAttribute( 'data-acp-part-token-map' ) || $owner->hasAttribute( 'data-acp-part-token-map-encoded' ) ) {
                    $encoded = $owner->getAttribute( 'data-acp-part-token-map-encoded' );
                    $tokens = json_decode( $encoded ? (string) base64_decode( $encoded, true ) : $owner->getAttribute( 'data-acp-part-token-map' ), true );
                    if ( is_array( $tokens ) ) { $text = strtr( $text, array_filter( $tokens, 'is_scalar' ) ); }
                    break;
                }
            }
            if ( 'image' === $type ) {
                $image = 'img' === $node->tagName ? $node : $node->getElementsByTagName( 'img' )->item( 0 );
                if ( $image instanceof \DOMElement ) { $image->setAttribute( 'alt', $text ); }
            }
            else {
                while ( $node->firstChild ) { $node->removeChild( $node->firstChild ); }
                $node->appendChild( $node->ownerDocument->createTextNode( $text ) );
            }
        }
        foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
            $rules = '';
            foreach ( $delta[ $device ] ?? [] as $property => $value ) {
                $rules .= 'visibility' === $property ? 'display:' . ( 'hide' === $value ? 'none' : 'revert' ) . '!important;' : $property . ':' . $value . '!important;';
                if ( 'status_icon' === $type && 'main' === $slot ) {
                    if ( 'font-size' === $property ) { $rules .= 'width:' . $value . '!important;height:' . $value . '!important;'; }
                    if ( 'padding' === $property ) {
                        $values = preg_split( '/\s+/', $value );
                        $sides = [ $values[0], $values[1] ?? $values[0], $values[2] ?? $values[0], $values[3] ?? ( $values[1] ?? $values[0] ) ];
                        foreach ( [ 'top', 'right', 'bottom', 'left' ] as $index => $side ) {
                            $rules .= '--ma-icon-padding-' . $side . ':' . ( (float) $sides[ $index ] === 0.0 ? '0px' : $sides[ $index ] ) . '!important;';
                        }
                    }
                }
            }
            if ( ! $rules ) { continue; }
            $rule = '.' . $class . '{' . $rules . '}';
            if ( 'status_icon' === $type && 'main' === $slot ) {
                // Fit the glyph inside the usable box, including local padding.
                $rule .= '.' . $class . ' > i{font-size:max(8px,.72em)!important;}@supports(font-size:1cqw){.' . $class . ' > i{font-size:max(8px,min(.72em,72cqw,72cqh))!important;}}';
            }
            if ( 'access_progress' === $type && 'main' === $slot ) {
                if ( isset( $delta[ $device ]['color'] ) ) { $rule .= '.' . $class . ' .ma-co-acp-access-status__progress-value{background:' . $delta[ $device ]['color'] . '!important;}'; }
                if ( isset( $delta[ $device ]['background-color'] ) ) { $rule .= '.' . $class . ' .ma-co-acp-access-status__progress-track{background:' . $delta[ $device ]['background-color'] . '!important;}'; }
            }
            if ( 'desktop' !== $device ) { $width = absint( $view['responsive_breakpoints'][ $device ] ?? ( 'tablet' === $device ? 1024 : 767 ) ); $rule = '@media(max-width:' . $width . 'px){' . $rule . '}'; }
            $css[ $key . $device ] = $rule;
        }
    }
}
