<?php
/**
 * Parts shared by the Excel and Word packages.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Office Open XML helpers.
 *
 * The packages are written by hand: a handful of XML parts is all a table needs, and it
 * spares the plugin a multi-megabyte library.
 */
final class Ooxml {

	/**
	 * XML declaration of every part.
	 */
	const DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

	/**
	 * Escapes text for an XML element or attribute, dropping characters XML 1.0 forbids.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape( $text ) {
		$text  = wp_check_invalid_utf8( (string) $text, true );
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		if ( null === $clean ) {
			// Not valid UTF-8 after all: keep the printable ASCII, never fail the export.
			$clean = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '', $text );
		}
		return htmlspecialchars( (string) $clean, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * [Content_Types].xml.
	 *
	 * @param array<string, string> $overrides Part name => content type.
	 * @return string
	 */
	public static function content_types( array $overrides ) {
		$xml                             = self::DECLARATION . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
		$xml                            .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
		$xml                            .= '<Default Extension="xml" ContentType="application/xml"/>';
		$overrides['/docProps/core.xml'] = 'application/vnd.openxmlformats-package.core-properties+xml';
		$overrides['/docProps/app.xml']  = 'application/vnd.openxmlformats-officedocument.extended-properties+xml';
		foreach ( $overrides as $part => $type ) {
			$xml .= '<Override PartName="' . self::escape( $part ) . '" ContentType="' . self::escape( $type ) . '"/>';
		}
		return $xml . '</Types>';
	}

	/**
	 * A relationships part.
	 *
	 * @param array<string, array{0: string, 1: string}> $relationships Id => [type URI, target].
	 * @return string
	 */
	public static function relationships( array $relationships ) {
		$xml = self::DECLARATION . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ( $relationships as $id => $relationship ) {
			$xml .= '<Relationship Id="' . self::escape( $id ) . '" Type="' . self::escape( $relationship[0] ) . '" Target="' . self::escape( $relationship[1] ) . '"/>';
		}
		return $xml . '</Relationships>';
	}

	/**
	 * Package-level relationships: main document and properties.
	 *
	 * @param string $main_target Path of the main part (xl/workbook.xml, word/document.xml).
	 * @return string
	 */
	public static function package_relationships( $main_target ) {
		return self::relationships(
			array(
				'rId1' => array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', $main_target ),
				'rId2' => array( 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml' ),
				'rId3' => array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties', 'docProps/app.xml' ),
			)
		);
	}

	/**
	 * The docProps/core.xml part.
	 *
	 * @param string $title   Document title.
	 * @param string $creator Author.
	 * @return string
	 */
	public static function core_properties( $title, $creator ) {
		return self::DECLARATION
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:title>' . self::escape( $title ) . '</dc:title>'
			. '<dc:creator>' . self::escape( $creator ) . '</dc:creator>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created>'
			. '</cp:coreProperties>';
	}

	/**
	 * The docProps/app.xml part.
	 *
	 * @return string
	 */
	public static function app_properties() {
		return self::DECLARATION
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Relecture ' . self::escape( RELECTURE_VERSION ) . '</Application></Properties>';
	}
}
