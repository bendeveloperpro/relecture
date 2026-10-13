<?php
/**
 * Markdown helpers.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Markdown meant to be read by people AND by an AI.
 *
 * Texts to proofread go into fenced code blocks: they are rendered verbatim (no HTML
 * interpreted by a viewer, no Markdown syntax eaten) and an AI reads them exactly.
 */
final class Markdown_Writer {

	/**
	 * A fenced block holding a text verbatim.
	 *
	 * The fence is longer than any run of backticks in the text, so the text cannot close it.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fence( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$fence = '```';
		if ( preg_match_all( '/`{3,}/', $text, $runs ) ) {
			$longest = max( array_map( 'strlen', $runs[0] ) );
			$fence   = str_repeat( '`', $longest + 1 );
		}
		return $fence . "text\n" . $text . "\n" . $fence;
	}

	/**
	 * Text for inline use (titles, list items): HTML-significant characters are escaped so
	 * that a viewer shows them instead of interpreting them, line breaks are flattened.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function inline( $text ) {
		$text = preg_replace( '/\s*\n\s*/', ' ', trim( (string) $text ) );
		return htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' );
	}

	/**
	 * A list item whose continuation lines stay inside the item.
	 *
	 * @param string $text Text (may hold line breaks).
	 * @return string
	 */
	public static function list_item( $text ) {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $text ) ) );
		$lines = array_map(
			static function ( $line ) {
				return htmlspecialchars( $line, ENT_NOQUOTES, 'UTF-8' );
			},
			$lines
		);
		return '- ' . implode( "\n  ", $lines );
	}
}
