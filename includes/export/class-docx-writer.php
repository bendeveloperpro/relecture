<?php
/**
 * Word (.docx) output.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal WordprocessingML document: headings, paragraphs and tables, A4 landscape.
 *
 * The document is described as a list of blocks:
 * - array( 'type' => 'heading', 'level' => 1|2, 'text' => '…' )
 * - array( 'type' => 'paragraph', 'runs' => array( array( 'text' => '…', 'bold' => true, 'italic' => true, 'color' => '646970' ) ) )
 * - array( 'type' => 'table', 'widths' => int[] (twentieths of a point), 'header' => string[],
 *          'rows' => array( array( cell, … ) ) ) — a cell is a list of paragraphs, a paragraph a list of runs.
 */
final class Docx_Writer {

	/**
	 * A4 landscape, in twentieths of a point.
	 */
	const PAGE_WIDTH  = 16838;
	const PAGE_HEIGHT = 11906;
	const MARGIN      = 720;

	/**
	 * Width available for a table.
	 */
	const CONTENT_WIDTH = 15398;

	/**
	 * Builds a document.
	 *
	 * @param array[] $blocks Blocks (see the class description).
	 * @param array   $props  Document properties: title, creator, lang (BCP 47, e.g. fr-FR).
	 * @return string|\WP_Error Path of a temporary file.
	 */
	public static function build( array $blocks, array $props ) {
		$body = '';
		foreach ( $blocks as $block ) {
			$type = isset( $block['type'] ) ? $block['type'] : 'paragraph';
			if ( 'heading' === $type ) {
				$level = isset( $block['level'] ) && 2 === (int) $block['level'] ? 2 : 1;
				$body .= self::paragraph( array( array( 'text' => isset( $block['text'] ) ? $block['text'] : '' ) ), 'Heading' . $level );
			} elseif ( 'table' === $type ) {
				$body .= self::table( $block );
				$body .= '<w:p/>'; // Word expects a paragraph after a table.
			} else {
				$body .= self::paragraph( isset( $block['runs'] ) ? $block['runs'] : array() );
			}
		}

		$files = array(
			'[Content_Types].xml'          => Ooxml::content_types(
				array(
					'/word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
					'/word/styles.xml'   => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
				)
			),
			'_rels/.rels'                  => Ooxml::package_relationships( 'word/document.xml' ),
			'docProps/core.xml'            => Ooxml::core_properties( isset( $props['title'] ) ? $props['title'] : '', isset( $props['creator'] ) ? $props['creator'] : '' ),
			'docProps/app.xml'             => Ooxml::app_properties(),
			'word/_rels/document.xml.rels' => Ooxml::relationships(
				array( 'rId1' => array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'styles.xml' ) )
			),
			'word/styles.xml'              => self::styles( isset( $props['lang'] ) ? $props['lang'] : 'en-US' ),
			'word/document.xml'            => Ooxml::DECLARATION
				. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
				. $body
				. '<w:sectPr><w:pgSz w:w="' . self::PAGE_WIDTH . '" w:h="' . self::PAGE_HEIGHT . '" w:orient="landscape"/>'
				. '<w:pgMar w:top="' . self::MARGIN . '" w:right="' . self::MARGIN . '" w:bottom="' . self::MARGIN . '" w:left="' . self::MARGIN . '" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>'
				. '</w:body></w:document>',
		);
		return Zip_Writer::create( $files );
	}

	/**
	 * A paragraph.
	 *
	 * @param array[] $runs  Runs.
	 * @param string  $style Paragraph style ID ('' for Normal).
	 * @return string
	 */
	private static function paragraph( array $runs, $style = '' ) {
		$xml = '<w:p>';
		if ( '' !== $style ) {
			$xml .= '<w:pPr><w:pStyle w:val="' . Ooxml::escape( $style ) . '"/></w:pPr>';
		}
		foreach ( $runs as $run ) {
			$xml .= self::run( $run );
		}
		return $xml . '</w:p>';
	}

	/**
	 * A run of text; line breaks and tabs become Word breaks and tabs.
	 *
	 * @param array $run Run: text, bold, italic, color (hex RRGGBB).
	 * @return string
	 */
	private static function run( array $run ) {
		$props = '';
		if ( ! empty( $run['bold'] ) ) {
			$props .= '<w:b/>';
		}
		if ( ! empty( $run['italic'] ) ) {
			$props .= '<w:i/>';
		}
		if ( ! empty( $run['color'] ) && preg_match( '/^[0-9A-Fa-f]{6}$/', $run['color'] ) ) {
			$props .= '<w:color w:val="' . $run['color'] . '"/>';
		}
		$content = '';
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", isset( $run['text'] ) ? (string) $run['text'] : '' ) ) as $l => $line ) {
			if ( $l > 0 ) {
				$content .= '<w:br/>';
			}
			foreach ( explode( "\t", $line ) as $t => $part ) {
				if ( $t > 0 ) {
					$content .= '<w:tab/>';
				}
				if ( '' !== $part ) {
					$content .= '<w:t xml:space="preserve">' . Ooxml::escape( $part ) . '</w:t>';
				}
			}
		}
		return '<w:r>' . ( $props ? '<w:rPr>' . $props . '</w:rPr>' : '' ) . $content . '</w:r>';
	}

	/**
	 * A table whose header row repeats on every page.
	 *
	 * @param array $table Table block.
	 * @return string
	 */
	private static function table( array $table ) {
		$widths = isset( $table['widths'] ) ? array_map( 'intval', $table['widths'] ) : array();
		$border = 'w:val="single" w:sz="4" w:space="0" w:color="C3C4C7"';
		$xml    = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum( $widths ) . '" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
			. '<w:tblBorders><w:top ' . $border . '/><w:left ' . $border . '/><w:bottom ' . $border . '/><w:right ' . $border . '/><w:insideH ' . $border . '/><w:insideV ' . $border . '/></w:tblBorders>'
			. '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar>'
			. '</w:tblPr><w:tblGrid>';
		foreach ( $widths as $width ) {
			$xml .= '<w:gridCol w:w="' . $width . '"/>';
		}
		$xml .= '</w:tblGrid>';

		if ( ! empty( $table['header'] ) ) {
			$xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
			foreach ( array_values( $table['header'] ) as $c => $label ) {
				$xml .= self::cell(
					array(
						array(
							array(
								'text' => $label,
								'bold' => true,
							),
						),
					),
					isset( $widths[ $c ] ) ? $widths[ $c ] : 0,
					'E8EEF4'
				);
			}
			$xml .= '</w:tr>';
		}
		foreach ( isset( $table['rows'] ) ? $table['rows'] : array() as $row ) {
			$xml .= '<w:tr>';
			foreach ( array_values( $row ) as $c => $paragraphs ) {
				$xml .= self::cell( $paragraphs, isset( $widths[ $c ] ) ? $widths[ $c ] : 0 );
			}
			$xml .= '</w:tr>';
		}
		return $xml . '</w:tbl>';
	}

	/**
	 * A table cell (always holds at least one paragraph, as Word requires).
	 *
	 * @param array[] $paragraphs Paragraphs (lists of runs).
	 * @param int     $width      Width.
	 * @param string  $fill       Background color (hex) or ''.
	 * @return string
	 */
	private static function cell( array $paragraphs, $width, $fill = '' ) {
		$xml = '<w:tc><w:tcPr><w:tcW w:w="' . (int) $width . '" w:type="dxa"/>';
		if ( '' !== $fill ) {
			$xml .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>';
		}
		$xml       .= '</w:tcPr>';
		$paragraphs = array_filter( $paragraphs );
		if ( ! $paragraphs ) {
			$paragraphs = array( array() );
		}
		foreach ( $paragraphs as $runs ) {
			$xml .= self::paragraph( $runs );
		}
		return $xml . '</w:tc>';
	}

	/**
	 * The styles.xml part: Normal (10 pt), Heading 1 and Heading 2.
	 *
	 * @param string $lang Language of the text.
	 * @return string
	 */
	private static function styles( $lang ) {
		$lang = preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang ) ? $lang : 'en-US';
		return Ooxml::DECLARATION
			. '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="' . $lang . '"/></w:rPr></w:rPrDefault>'
			. '<w:pPrDefault><w:pPr><w:spacing w:after="60" w:line="259" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="0" w:after="160"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/><w:szCs w:val="26"/></w:rPr></w:style>'
			. '</w:styles>';
	}
}
