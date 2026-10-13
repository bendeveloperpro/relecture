<?php
/**
 * Structured access to an HTML fragment.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around DOMDocument for the few blocks that need structure (tables,
 * pullquotes, legacy lists and quotes, classic content).
 *
 * The text of every node is computed by Text::from_html() on its serialized HTML, so all
 * extraction paths share the same normalization. Callers must handle a null result (the
 * dom extension is missing or the fragment cannot be parsed) by falling back to the
 * whole fragment as a single segment.
 */
final class Html_Fragment {

	/**
	 * Document holding the fragment.
	 *
	 * @var \DOMDocument
	 */
	private $document;

	/**
	 * Element wrapping the fragment.
	 *
	 * @var \DOMElement
	 */
	private $root;

	/**
	 * Constructor.
	 *
	 * @param \DOMDocument $document Document.
	 * @param \DOMElement  $root     Wrapper element.
	 */
	private function __construct( \DOMDocument $document, \DOMElement $root ) {
		$this->document = $document;
		$this->root     = $root;
	}

	/**
	 * Parses a fragment.
	 *
	 * @param string $html HTML fragment (UTF-8).
	 * @return self|null
	 */
	public static function parse( $html ) {
		if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) {
			return null;
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML declaration tells libxml the input is UTF-8; LIBXML_NONET forbids any network access.
		$loaded = $document->loadHTML(
			'<?xml encoding="UTF-8"?><html><body><div id="relecture-root">' . $html . '</div></body></html>',
			LIBXML_NONET | LIBXML_COMPACT
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}
		$root = $document->getElementById( 'relecture-root' );
		if ( ! $root instanceof \DOMElement ) {
			$found = ( new \DOMXPath( $document ) )->query( '//div[@id="relecture-root"]' );
			$root  = $found && $found->length ? $found->item( 0 ) : null;
		}
		return $root instanceof \DOMElement ? new self( $document, $root ) : null;
	}

	/**
	 * Elements matching an XPath expression relative to the fragment, in document order.
	 *
	 * @param string           $expression Relative XPath expression (e.g. './/td').
	 * @param \DOMElement|null $context    Context element (the fragment by default).
	 * @return \DOMElement[]
	 */
	public function query( $expression, $context = null ) {
		$nodes = ( new \DOMXPath( $this->document ) )->query( $expression, $context ? $context : $this->root );
		$found = array();
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				if ( $node instanceof \DOMElement ) {
					$found[] = $node;
				}
			}
		}
		return $found;
	}

	/**
	 * HTML inside an element.
	 *
	 * @param \DOMNode $node Element.
	 * @return string
	 */
	public function inner_html( \DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			$html .= $this->document->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * HTML of an element itself.
	 *
	 * @param \DOMNode $node Element.
	 * @return string
	 */
	public function outer_html( \DOMNode $node ) {
		return (string) $this->document->saveHTML( $node );
	}

	/**
	 * Text of an element, without the descendants matched by an XPath expression.
	 *
	 * Used for “own text”: a list item without its nested list, a quote without its
	 * paragraphs. Works on a clone, the document is left untouched.
	 *
	 * @param \DOMElement|null $node    Element, or null for the whole fragment.
	 * @param string           $exclude Relative XPath of the descendants to drop (e.g. './/ul|.//ol').
	 * @return string
	 */
	public function own_text( $node, $exclude ) {
		$clone = ( $node instanceof \DOMElement ? $node : $this->root )->cloneNode( true );
		if ( '' !== $exclude ) {
			$xpath = new \DOMXPath( $this->document );
			// The clone is detached: append it to the document temporarily so XPath can see it.
			$this->root->appendChild( $clone );
			$drop = $xpath->query( $exclude, $clone );
			if ( $drop ) {
				$nodes = array();
				foreach ( $drop as $descendant ) {
					$nodes[] = $descendant;
				}
				foreach ( $nodes as $descendant ) {
					if ( $descendant->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
						$descendant->parentNode->removeChild( $descendant ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
					}
				}
			}
			$this->root->removeChild( $clone );
		}
		return Text::from_html( $this->inner_html( $clone ) );
	}

	/**
	 * Value of an attribute, decoded.
	 *
	 * @param \DOMElement $node Element.
	 * @param string      $name Attribute name.
	 * @return string
	 */
	public static function attribute( \DOMElement $node, $name ) {
		return $node->hasAttribute( $name ) ? trim( preg_replace( '/[ \t\r\n]+/', ' ', $node->getAttribute( $name ) ) ) : '';
	}
}
