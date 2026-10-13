<?php
/**
 * CSV output.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- In-memory stream (php://temp), not a file on disk.

/**
 * CSV for spreadsheets.
 *
 * UTF-8 with a byte order mark (Excel needs it to read accents), the separator spreadsheets
 * expect in the site's locale, and protection against formula injection.
 */
final class Csv_Writer {

	/**
	 * Separator: semicolon where the comma is the decimal separator (French, German…),
	 * comma elsewhere. That is what Excel expects in those locales.
	 *
	 * @return string
	 */
	public static function delimiter() {
		global $wp_locale;
		$decimal   = ( $wp_locale instanceof \WP_Locale && isset( $wp_locale->number_format['decimal_point'] ) ) ? $wp_locale->number_format['decimal_point'] : '.';
		$delimiter = ',' === $decimal ? ';' : ',';

		/**
		 * Filters the CSV separator.
		 *
		 * @param string $delimiter One character.
		 */
		$delimiter = (string) apply_filters( 'relecture_csv_delimiter', $delimiter );
		return 1 === strlen( $delimiter ) ? $delimiter : ',';
	}

	/**
	 * Neutralizes a cell a spreadsheet would run as a formula.
	 *
	 * A cell starting with = + - @ tab or carriage return is prefixed with an apostrophe
	 * (OWASP “CSV injection” recommendation). The visible cost: “-5 °C” shows as “'-5 °C”.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Builds a CSV document.
	 *
	 * @param array[]     $rows      Rows of cells, headers first.
	 * @param string|null $delimiter Separator (locale default when null).
	 * @return string
	 */
	public static function build( array $rows, $delimiter = null ) {
		$delimiter = null === $delimiter ? self::delimiter() : $delimiter;
		$handle    = fopen( 'php://temp', 'r+' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( array( __CLASS__, 'cell' ), $row ), $delimiter, '"', '' );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );
		return $csv;
	}
}
