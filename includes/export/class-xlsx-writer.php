<?php
/**
 * Excel (.xlsx) output.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal SpreadsheetML workbook.
 *
 * Text goes into “inline strings”: a spreadsheet never evaluates them, so a cell holding
 * “=HYPERLINK(…)” stays text — no formula injection is possible in this format.
 */
final class Xlsx_Writer {

	/**
	 * Excel's limit for the length of a cell.
	 */
	const MAX_CELL = 32767;

	/**
	 * Cell styles (indexes into cellXfs of styles.xml).
	 */
	const STYLE_HEADER = 1;
	const STYLE_BODY   = 2;
	const STYLE_LABEL  = 3;

	/**
	 * Builds a workbook.
	 *
	 * @param array[] $sheets Sheets, each: name (string), rows (array of cell arrays; int cells
	 *                        are numbers), widths (column widths in characters), header (bool:
	 *                        first row is a header, frozen and filterable), labels (bool: first
	 *                        column in bold).
	 * @param array   $props  Document properties: title, creator.
	 * @return string|\WP_Error Path of a temporary file.
	 */
	public static function build( array $sheets, array $props ) {
		$overrides = array(
			'/xl/workbook.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
			'/xl/styles.xml'   => 'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml',
		);
		$relations = array();
		$entries   = '';
		$names     = '';
		$files     = array();

		$index = 0;
		foreach ( array_values( $sheets ) as $sheet ) {
			++$index;
			$name = self::sheet_name( isset( $sheet['name'] ) ? $sheet['name'] : 'Sheet' . $index, $index );
			$rows = isset( $sheet['rows'] ) ? array_values( $sheet['rows'] ) : array();

			$overrides[ '/xl/worksheets/sheet' . $index . '.xml' ] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';
			$relations[ 'rId' . $index ]                           = array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet' . $index . '.xml' );

			$entries .= '<sheet name="' . Ooxml::escape( $name ) . '" sheetId="' . $index . '" r:id="rId' . $index . '"/>';
			if ( ! empty( $sheet['header'] ) && $rows ) {
				$names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . ( $index - 1 ) . '" hidden="1">'
					. Ooxml::escape( "'" . str_replace( "'", "''", $name ) . "'!" . self::range( $rows, true ) )
					. '</definedName>';
			}
			$files[ 'xl/worksheets/sheet' . $index . '.xml' ] = self::sheet( $sheet, $rows );
		}
		$relations[ 'rId' . ( $index + 1 ) ] = array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'styles.xml' );

		$files['[Content_Types].xml']        = Ooxml::content_types( $overrides );
		$files['_rels/.rels']                = Ooxml::package_relationships( 'xl/workbook.xml' );
		$files['docProps/core.xml']          = Ooxml::core_properties( isset( $props['title'] ) ? $props['title'] : '', isset( $props['creator'] ) ? $props['creator'] : '' );
		$files['docProps/app.xml']           = Ooxml::app_properties();
		$files['xl/_rels/workbook.xml.rels'] = Ooxml::relationships( $relations );
		$files['xl/styles.xml']              = self::styles();
		$files['xl/workbook.xml']            = Ooxml::DECLARATION
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<bookViews><workbookView/></bookViews>'
			. '<sheets>' . $entries . '</sheets>'
			. ( $names ? '<definedNames>' . $names . '</definedNames>' : '' )
			. '</workbook>';

		return Zip_Writer::create( $files );
	}

	/**
	 * One worksheet.
	 *
	 * @param array   $sheet Sheet definition.
	 * @param array[] $rows  Rows.
	 * @return string
	 */
	private static function sheet( array $sheet, array $rows ) {
		$header = ! empty( $sheet['header'] );
		$labels = ! empty( $sheet['labels'] );

		$xml = Ooxml::DECLARATION
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		if ( $header ) {
			$xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
		}
		if ( ! empty( $sheet['widths'] ) ) {
			$xml .= '<cols>';
			foreach ( array_values( $sheet['widths'] ) as $i => $width ) {
				$xml .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $width . '" customWidth="1"/>';
			}
			$xml .= '</cols>';
		}

		$xml .= '<sheetData>';
		foreach ( $rows as $r => $row ) {
			$xml .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( array_values( $row ) as $c => $value ) {
				$ref   = self::column( $c ) . ( $r + 1 );
				$style = self::STYLE_BODY;
				if ( $header && 0 === $r ) {
					$style = self::STYLE_HEADER;
				} elseif ( $labels && 0 === $c ) {
					$style = self::STYLE_LABEL;
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					$xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . ( 0 + $value ) . '</v></c>';
					continue;
				}
				$text = (string) $value;
				if ( '' === $text ) {
					$xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
					continue;
				}
				if ( mb_strlen( $text ) > self::MAX_CELL ) {
					$text = mb_substr( $text, 0, self::MAX_CELL - 1 ) . '…';
				}
				$xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . Ooxml::escape( $text ) . '</t></is></c>';
			}
			$xml .= '</row>';
		}
		$xml .= '</sheetData>';

		if ( $header && $rows ) {
			$xml .= '<autoFilter ref="' . self::range( $rows, false ) . '"/>';
		}
		$xml .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';
		$xml .= '<pageSetup orientation="landscape"/>';
		return $xml . '</worksheet>';
	}

	/**
	 * The styles.xml part: default, header (bold, shaded, bordered, wrapped), body (bordered,
	 * wrapped, top-aligned) and label (bold).
	 *
	 * @return string
	 */
	private static function styles() {
		$border = '<left style="thin"><color rgb="FFC3C4C7"/></left><right style="thin"><color rgb="FFC3C4C7"/></right><top style="thin"><color rgb="FFC3C4C7"/></top><bottom style="thin"><color rgb="FFC3C4C7"/></bottom><diagonal/>';
		return Ooxml::DECLARATION
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
			. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF4"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $border . '</border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="4">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	/**
	 * Letters of a column (0 → A, 25 → Z, 26 → AA).
	 *
	 * @param int $index Zero-based column index.
	 * @return string
	 */
	private static function column( $index ) {
		$letters = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$letters = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $letters;
		}
		return $letters;
	}

	/**
	 * Range covering every cell (A1:J42).
	 *
	 * @param array[] $rows     Rows.
	 * @param bool    $absolute Absolute references ($A$1:$J$42).
	 * @return string
	 */
	private static function range( array $rows, $absolute ) {
		$width = 1;
		foreach ( $rows as $row ) {
			$width = max( $width, count( $row ) );
		}
		$last = self::column( $width - 1 );
		$d    = $absolute ? '$' : '';
		return $d . 'A' . $d . '1:' . $d . $last . $d . count( $rows );
	}

	/**
	 * A valid sheet name: at most 31 characters, none of : \ / ? * [ ].
	 *
	 * @param string $name  Wanted name.
	 * @param int    $index Sheet number, for a fallback name.
	 * @return string
	 */
	private static function sheet_name( $name, $index ) {
		$name = trim( str_replace( array( ':', '\\', '/', '?', '*', '[', ']' ), ' ', (string) $name ), " '" );
		$name = mb_substr( $name, 0, 31 );
		return '' === $name ? 'Sheet' . $index : $name;
	}
}
