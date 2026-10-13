<?php
/**
 * Plain-text helpers: HTML to text, input sanitization, comparisons.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Text utilities.
 *
 * Corrections and notes are PLAIN TEXT. They are stored as typed (a note may legitimately
 * mention “<h2>”), cleaned of what can corrupt storage or exports, and escaped at output
 * for each target: HTML, XML, CSV, JSON or Markdown.
 */
final class Text {

	/**
	 * Maximum length, in characters, of a correction or a note.
	 */
	const MAX_LENGTH = 20000;

	/**
	 * Converts a block's HTML into the text a reader sees.
	 *
	 * HTML whitespace is collapsed, <br> and block boundaries become line breaks,
	 * tags (and the contents of script/style) are removed, character references decoded.
	 * Non-breaking spaces survive: French typography depends on them.
	 *
	 * @param string $html                HTML fragment.
	 * @param bool   $preserve_whitespace Keep spaces and line breaks (preformatted, verse).
	 * @return string
	 */
	public static function from_html( $html, $preserve_whitespace = false ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( $preserve_whitespace ) {
			$html = str_replace( array( "\r\n", "\r" ), "\n", $html );
		} else {
			$html = preg_replace( '/[ \t\r\n]+/', ' ', $html );
		}
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$html = preg_replace( '#</(p|div|li|h[1-6]|blockquote|figcaption|tr|dt|dd|summary|section|article)>#i', "$0\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! $preserve_whitespace ) {
			$text = preg_replace( '/ *\n */', "\n", $text );
			$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		}

		return trim( $text, " \t\n\r\0\x0B" );
	}

	/**
	 * Cleans a correction or a note typed by a user.
	 *
	 * Keeps the text as typed, HTML included (it is escaped at output), but makes it safe
	 * to store and to export: valid UTF-8, no control characters except tab and line feed,
	 * normalized line breaks, bounded length.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_input( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		$value = trim( $value );
		if ( mb_strlen( $value ) > self::MAX_LENGTH ) {
			$value = mb_substr( $value, 0, self::MAX_LENGTH );
		}
		return $value;
	}

	/**
	 * Whether a correction says the same thing as the original (it then counts as none).
	 *
	 * @param string $correction Correction.
	 * @param string $original   Original text.
	 * @return bool
	 */
	public static function same( $correction, $original ) {
		return self::normalize( $correction ) === self::normalize( $original );
	}

	/**
	 * Normalizes line breaks and outer whitespace before a comparison.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$text = preg_replace( '/[ \t]+\n/', "\n", $text );
		return trim( $text );
	}

	/**
	 * Shortens a text for a preview, on a word boundary when possible.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum length in characters.
	 * @return string
	 */
	public static function excerpt( $text, $length = 120 ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		// Byte offsets from here on: cutting at an ASCII space never splits a UTF-8 character.
		$cut   = mb_substr( $text, 0, $length );
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > strlen( $cut ) * 0.6 ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut, ' ,;:' ) . '…';
	}

	/**
	 * Whether a stored value holds something (corrections and notes are NULL when empty).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function filled( $value ) {
		return null !== $value && '' !== trim( (string) $value );
	}
}
