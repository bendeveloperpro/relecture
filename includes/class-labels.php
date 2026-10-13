<?php
/**
 * Human-readable labels, translated at display time.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Labels of statuses and segments.
 *
 * Only machine values are stored; labels are computed when displayed, so each user sees
 * them in their own language.
 */
final class Labels {

	/**
	 * Review statuses.
	 *
	 * @return array<string, string>
	 */
	public static function review_statuses() {
		return array(
			'in_progress' => __( 'In progress', 'relecture' ),
			'ready'       => __( 'Review complete', 'relecture' ),
			'done'        => __( 'Corrections applied', 'relecture' ),
		);
	}

	/**
	 * Statuses of a correction.
	 *
	 * @return array<string, string>
	 */
	public static function correction_statuses() {
		return array(
			'pending'  => __( 'To validate', 'relecture' ),
			'accepted' => __( 'Accepted', 'relecture' ),
			'rejected' => __( 'Rejected', 'relecture' ),
			'applied'  => __( 'Applied', 'relecture' ),
		);
	}

	/**
	 * Types of note.
	 *
	 * @return array<string, string>
	 */
	public static function note_types() {
		return array(
			'post'      => __( 'Note on this content', 'relecture' ),
			'guideline' => __( 'Guideline for future content', 'relecture' ),
		);
	}

	/**
	 * Statuses of a guideline.
	 *
	 * @return array<string, string>
	 */
	public static function guideline_statuses() {
		return array(
			'new'        => _x( 'New', 'one guideline', 'relecture' ),
			'integrated' => _x( 'Integrated', 'one guideline', 'relecture' ),
		);
	}

	/**
	 * Label of a value in one of the lists above, or the value itself if unknown.
	 *
	 * @param array<string, string> $labels List of labels.
	 * @param string                $value  Machine value.
	 * @return string
	 */
	public static function of( array $labels, $value ) {
		return isset( $labels[ $value ] ) ? $labels[ $value ] : (string) $value;
	}

	/**
	 * Short label of a segment: “Title”, “H2”, “Paragraph”, “Caption”…
	 *
	 * @param array $item Item (or segment) with segment_type, block_name and meta.
	 * @return string
	 */
	public static function segment( array $item ) {
		$type  = isset( $item['segment_type'] ) ? (string) $item['segment_type'] : '';
		$block = isset( $item['block_name'] ) ? (string) $item['block_name'] : '';
		$meta  = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();

		switch ( $type ) {
			case 'title':
				return __( 'Title', 'relecture' );
			case 'excerpt':
				return __( 'Excerpt', 'relecture' );
			case 'caption':
				return __( 'Caption', 'relecture' );
			case 'alt':
				return __( 'Alternative text', 'relecture' );
			case 'citation':
				return __( 'Quote source', 'relecture' );
			case 'summary':
				return __( 'Summary', 'relecture' );
			case 'item':
				return __( 'List item', 'relecture' );
			case 'reference':
				return __( 'Synced pattern', 'relecture' );
			case 'cell':
				return sprintf(
					/* translators: 1: row number, 2: column number. */
					__( 'Table cell (row %1$d, column %2$d)', 'relecture' ),
					isset( $meta['row'] ) ? (int) $meta['row'] : 0,
					isset( $meta['col'] ) ? (int) $meta['col'] : 0
				);
			case 'classic':
				return self::tag( isset( $meta['tag'] ) ? (string) $meta['tag'] : '' );
		}

		if ( 'core/heading' === $block && ! empty( $meta['level'] ) ) {
			return 'H' . (int) $meta['level'];
		}
		if ( '' !== $block ) {
			$type_object = \WP_Block_Type_Registry::get_instance()->get_registered( $block );
			if ( $type_object && ! empty( $type_object->title ) ) {
				return (string) $type_object->title;
			}
			return $block;
		}
		return __( 'Text', 'relecture' );
	}

	/**
	 * Label of an HTML element found in classic content.
	 *
	 * @param string $tag Lowercase tag name.
	 * @return string
	 */
	private static function tag( $tag ) {
		if ( preg_match( '/^h([1-6])$/', $tag, $match ) ) {
			return 'H' . $match[1];
		}
		switch ( $tag ) {
			case 'p':
				return __( 'Paragraph', 'relecture' );
			case 'li':
				return __( 'List item', 'relecture' );
			case 'blockquote':
				return __( 'Quote', 'relecture' );
			case 'figcaption':
			case 'caption':
				return __( 'Caption', 'relecture' );
			case 'td':
			case 'th':
				return __( 'Table cell', 'relecture' );
			case 'dt':
			case 'dd':
				return __( 'Definition list', 'relecture' );
			case 'pre':
				return __( 'Preformatted text', 'relecture' );
			case 'img':
				return __( 'Alternative text', 'relecture' );
		}
		return __( 'Text', 'relecture' );
	}
}
