<?php
/**
 * Shared pill style tokens for every Shift64 control that renders as a pill.
 *
 * WordPress 7.1 shipped per-block interactive states (`:hover`, `:focus`,
 * `:active`), but the opt-in is a hardcoded core allowlist — third-party blocks
 * cannot register for it. So a pill-shaped control stores one `pillStyle`
 * attribute shaped exactly like core's `style` object, including the `:hover`
 * key, and resolves it to CSS custom properties here. When core opens the
 * allowlist the stored data already matches and this class becomes a deletion,
 * not a migration.
 *
 * Keep the maps in sync with src/blocks/shared/pill-style.js.
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves stored pill style objects to inline CSS custom properties.
 */
final class Shift64_Woo_Search_Pill_Style {

	/**
	 * Pill trigger tokens, mapped to their path inside the stored style.
	 *
	 * @var array<string,array<int,string>>
	 */
	public const PILL_VARS = array(
		'--s64ws-pill-color'              => array( 'color', 'text' ),
		'--s64ws-pill-bg'                 => array( 'color', 'background' ),
		'--s64ws-pill-border-color'       => array( 'border', 'color' ),
		'--s64ws-pill-border-width'       => array( 'border', 'width' ),
		'--s64ws-pill-radius'             => array( 'border', 'radius' ),
		'--s64ws-pill-color-hover'        => array( ':hover', 'color', 'text' ),
		'--s64ws-pill-bg-hover'           => array( ':hover', 'color', 'background' ),
		'--s64ws-pill-border-color-hover' => array( ':hover', 'border', 'color' ),
	);

	/**
	 * Mobile action-button tokens shared by Apply and Clear.
	 *
	 * @var array<string,array<int,string>>
	 */
	public const ACTION_VARS = array(
		'--s64ws-action-font-size' => array( 'typography', 'fontSize' ),
		'--s64ws-action-radius'    => array( 'border', 'radius' ),
	);

	/**
	 * Build the pill trigger style declarations.
	 *
	 * @param mixed $pill_style Stored pill style attribute.
	 * @return string Declarations without a trailing semicolon; '' when unstyled.
	 */
	public static function pill_vars( $pill_style ) {
		return self::vars( $pill_style, self::PILL_VARS );
	}

	/**
	 * Build the action-button style declarations.
	 *
	 * @param mixed $action_style Stored action style attribute.
	 * @return string Declarations without a trailing semicolon; '' when unstyled.
	 */
	public static function action_vars( $action_style ) {
		return self::vars( $action_style, self::ACTION_VARS );
	}

	/**
	 * Resolve a stored style object against a token map.
	 *
	 * @param mixed                           $style     Stored style attribute.
	 * @param array<string,array<int,string>> $variables Token map.
	 * @return string Declarations without a trailing semicolon; '' when unstyled.
	 */
	public static function vars( $style, $variables ) {
		if ( ! is_array( $style ) || empty( $style ) ) {
			return '';
		}

		$declarations = array();
		foreach ( $variables as $token => $path ) {
			$value = $style;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}

			$value = self::sanitize_style_value( $value );
			if ( '' !== $value ) {
				$declarations[] = $token . ':' . $value;
			}
		}

		return implode( ';', $declarations );
	}

	/**
	 * Resolve a theme preset reference to a CSS custom property reference.
	 *
	 * Mirrors core's wp_normalize_state_preset_vars(), which is 7.1-only while
	 * this plugin still supports WordPress 6.0.
	 *
	 * @param string $value Stored style value.
	 * @return string CSS-ready value.
	 */
	private static function normalize_preset_value( $value ) {
		if ( ! str_starts_with( $value, 'var:preset|' ) ) {
			return $value;
		}

		return 'var(--wp--' . str_replace( '|', '--', substr( $value, strlen( 'var:' ) ) ) . ')';
	}

	/**
	 * Accept only value shapes a colour or length control can produce.
	 *
	 * Core's safecss_filter_attr() drops the *entire* style attribute when one
	 * declaration looks hostile, so a single bad stored value would silently
	 * strip every other token. Filtering per value keeps the blast radius to
	 * the offending token.
	 *
	 * @param mixed $value Stored style value.
	 * @return string Safe CSS value, or '' when unusable.
	 */
	private static function sanitize_style_value( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( self::normalize_preset_value( trim( $value ) ) );
		if ( '' === $value ) {
			return '';
		}

		// Accepted shapes: hex colours, the rgb/rgba/hsl/hsla colour
		// functions, a custom-property reference with an optional fallback,
		// CSS lengths, and bare keywords such as `transparent`.
		$patterns = array(
			'/^#[0-9a-f]{3,8}$/i',
			'/^(rgb|rgba|hsl|hsla)\(\s*[0-9a-z%.,\/\s+-]+\)$/i',
			'/^var\(\s*--[a-z0-9-]+\s*(,\s*[#a-z0-9%.\s-]+)?\)$/i',
			'/^-?[0-9]*\.?[0-9]+(px|em|rem|%|vh|vw|ch)?$/i',
			'/^[a-z-]+$/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return $value;
			}
		}

		return '';
	}
}
