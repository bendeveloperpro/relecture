<?php
/**
 * Turns a post into the list of text segments shown in the review table.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Segment extraction.
 *
 * A segment is one row of the review table: the title, the excerpt, the own text of a
 * block (a container's own text is, for instance, a quote's source), a caption, an
 * alternative text, a table cell… Each segment keeps where it comes from (block name and
 * index path in the block tree) so that a person or an AI can find it in the source.
 */
final class Extractor {

	/**
	 * Blocks that hold no prose to proofread.
	 */
	const EXCLUDED_BLOCKS = array(
		'core/code',
		'core/shortcode',
		'core/more',
		'core/nextpage',
		'core/separator',
		'core/spacer',
		'core/missing',
		'core/legacy-widget',
		'core/rss',
		'core/latest-posts',
		'core/latest-comments',
		'core/archives',
		'core/calendar',
		'core/categories',
		'core/tag-cloud',
		'core/search',
		'core/page-list',
		'core/navigation',
		'core/social-links',
	);

	/**
	 * Blocks whose own text is a caption.
	 */
	const CAPTION_BLOCKS = array( 'core/image', 'core/video', 'core/audio', 'core/gallery' );

	/**
	 * Elements that make a segment in classic (non-block) content.
	 */
	const CLASSIC_TAGS = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'figure', 'figcaption', 'caption', 'td', 'th', 'dt', 'dd', 'pre', 'summary', 'details', 'div', 'section', 'article', 'aside', 'header', 'footer', 'address' );

	/**
	 * Elements whose text must never be read as prose.
	 */
	const SKIPPED_TAGS = array( 'ul', 'ol', 'dl', 'table', 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'select', 'textarea' );

	/**
	 * Nesting guard for malformed content.
	 */
	const MAX_DEPTH = 40;

	/**
	 * Segments of a post, in reading order.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[] Each segment: segment_type, block_name, block_path, meta, original, original_html, fingerprint.
	 */
	public static function extract( \WP_Post $post ) {
		$segments = array();

		self::add( $segments, 'title', '', 'title', Text::from_html( $post->post_title ), (string) $post->post_title );
		self::add( $segments, 'excerpt', '', 'excerpt', Text::from_html( $post->post_excerpt ), (string) $post->post_excerpt );

		$content = (string) $post->post_content;
		if ( has_blocks( $content ) ) {
			self::walk( parse_blocks( $content ), '', $segments, 0 );
		} else {
			self::classic( $content, '', 'content', $segments, true );
		}

		/**
		 * Filters the segments of a post (add custom fields, remove rows…).
		 *
		 * Each segment is an array with at least `original` (plain text); optional keys:
		 * `segment_type`, `block_name`, `block_path`, `meta` (array), `original_html`.
		 *
		 * @param array[]  $segments Segments in reading order.
		 * @param \WP_Post $post     Post.
		 */
		$segments = apply_filters( 'relecture_post_segments', $segments, $post );

		$clean = array();
		foreach ( (array) $segments as $segment ) {
			if ( ! is_array( $segment ) || ! isset( $segment['original'] ) || '' === trim( (string) $segment['original'] ) ) {
				continue;
			}
			$segment = wp_parse_args(
				$segment,
				array(
					'segment_type'  => 'block',
					'block_name'    => '',
					'block_path'    => '',
					'meta'          => array(),
					'original_html' => '',
				)
			);

			$segment['original']    = (string) $segment['original'];
			$segment['meta']        = is_array( $segment['meta'] ) ? $segment['meta'] : array();
			$segment['fingerprint'] = sha1( $segment['segment_type'] . "\0" . $segment['block_name'] . "\0" . $segment['original'] );
			$clean[]                = $segment;
		}
		return $clean;
	}

	/**
	 * Blocks skipped by the extraction.
	 *
	 * @return string[]
	 */
	public static function excluded_blocks() {
		/**
		 * Filters the blocks whose content is not proofread.
		 *
		 * @param string[] $blocks Block names.
		 */
		return (array) apply_filters( 'relecture_excluded_blocks', self::EXCLUDED_BLOCKS );
	}

	/**
	 * Walks a block tree.
	 *
	 * @param array[] $blocks      Parsed blocks.
	 * @param string  $parent_path Index path of the parent ('' at the root).
	 * @param array[] $segments    Segments, by reference.
	 * @param int     $depth       Current depth.
	 * @return void
	 */
	private static function walk( array $blocks, $parent_path, array &$segments, $depth ) {
		foreach ( $blocks as $index => $block ) {
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$path  = '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
			$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

			if ( '' === $name ) {
				// Freeform HTML between blocks: classic content kept as is.
				$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
				if ( '' !== trim( wp_strip_all_tags( $html ) ) ) {
					self::classic( $html, '', $path, $segments, true );
				}
				continue;
			}

			/**
			 * Filters the segments of one block, before the default extraction.
			 *
			 * Return an array of segments to replace the default handling (an empty array
			 * skips the block), or null to keep it. Useful for blocks that store their text
			 * in attributes.
			 *
			 * @param array[]|null $segments Null by default.
			 * @param array        $block    Parsed block.
			 * @param string       $path     Index path of the block.
			 */
			$custom = apply_filters( 'relecture_block_segments', null, $block, $path );
			if ( is_array( $custom ) ) {
				foreach ( $custom as $segment ) {
					if ( is_array( $segment ) ) {
						$segments[] = $segment + array(
							'block_name' => $name,
							'block_path' => $path,
						);
					}
				}
				continue;
			}

			if ( in_array( $name, self::excluded_blocks(), true ) ) {
				continue;
			}

			switch ( $name ) {
				case 'core/block':
					self::reference( $block, $path, $segments );
					continue 2;
				case 'core/freeform':
					self::classic( self::own_html( $block ), $name, $path, $segments, true );
					continue 2;
				case 'core/html':
					self::classic( self::own_html( $block ), $name, $path, $segments, false );
					continue 2;
				case 'core/table':
					self::table( $block, $path, $segments );
					continue 2;
				case 'core/pullquote':
					self::quote( $block, $path, $segments );
					continue 2;
				case 'core/embed':
					self::embed_caption( $block, $path, $segments );
					continue 2;
				case 'core/list':
					if ( ! $inner ) {
						self::legacy_list( $block, $path, $segments );
						continue 2;
					}
					break;
				case 'core/quote':
					if ( ! $inner ) {
						self::quote( $block, $path, $segments );
						continue 2;
					}
					break;
			}

			$own_html = self::own_html( $block );
			$preserve = in_array( $name, array( 'core/preformatted', 'core/verse' ), true );
			$type     = 'block';
			$meta     = array();
			if ( in_array( $name, self::CAPTION_BLOCKS, true ) ) {
				$type = 'caption';
			} elseif ( 'core/quote' === $name ) {
				$type = 'citation';
			} elseif ( 'core/details' === $name ) {
				$type = 'summary';
			} elseif ( 'core/heading' === $name ) {
				$meta['level'] = self::heading_level( $block, $own_html );
			}

			self::alternative_texts( $own_html, $name, $path, $segments );
			// A quote's own text is its source, which reads after the quoted paragraphs.
			if ( 'citation' !== $type ) {
				self::add( $segments, $type, $name, $path, Text::from_html( $own_html, $preserve ), $own_html, $meta );
			}
			if ( $inner && $depth < self::MAX_DEPTH ) {
				self::walk( $inner, $path, $segments, $depth + 1 );
			}
			if ( 'citation' === $type ) {
				self::add( $segments, $type, $name, $path, Text::from_html( $own_html ), $own_html, $meta );
			}
		}
	}

	/**
	 * HTML that belongs to the block itself, without its inner blocks.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	private static function own_html( array $block ) {
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			return implode( '', array_filter( $block['innerContent'], 'is_string' ) );
		}
		return isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
	}

	/**
	 * Appends a segment if it holds text.
	 *
	 * @param array[] $segments   Segments, by reference.
	 * @param string  $type       Segment type.
	 * @param string  $block_name Block name ('' for title, excerpt, classic content).
	 * @param string  $path       Index path.
	 * @param string  $text       Plain text.
	 * @param string  $html       Source HTML.
	 * @param array   $meta       Extra data (heading level, cell position, tag…).
	 * @return void
	 */
	private static function add( array &$segments, $type, $block_name, $path, $text, $html = '', array $meta = array() ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return;
		}
		$segments[] = array(
			'segment_type'  => $type,
			'block_name'    => (string) $block_name,
			'block_path'    => (string) $path,
			'meta'          => $meta,
			'original'      => $text,
			'original_html' => trim( (string) $html ),
		);
	}

	/**
	 * Level of a heading block.
	 *
	 * @param array  $block Parsed block.
	 * @param string $html  Its HTML.
	 * @return int
	 */
	private static function heading_level( array $block, $html ) {
		if ( preg_match( '/<h([1-6])\b/i', $html, $match ) ) {
			return (int) $match[1];
		}
		return isset( $block['attrs']['level'] ) ? max( 1, min( 6, (int) $block['attrs']['level'] ) ) : 2;
	}

	/**
	 * Alternative texts of the images found in some HTML.
	 *
	 * @param string  $html       HTML.
	 * @param string  $block_name Block name.
	 * @param string  $path       Index path.
	 * @param array[] $segments   Segments, by reference.
	 * @return void
	 */
	private static function alternative_texts( $html, $block_name, $path, array &$segments ) {
		if ( false === stripos( $html, '<img' ) ) {
			return;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'img' ) ) {
			$alt = $processor->get_attribute( 'alt' );
			if ( is_string( $alt ) ) {
				self::add( $segments, 'alt', $block_name, $path, trim( preg_replace( '/[ \t\r\n]+/', ' ', $alt ) ) );
			}
		}
	}

	/**
	 * A synced pattern: reviewed on its own, shown as an information row.
	 *
	 * @param array   $block    Parsed block.
	 * @param string  $path     Index path.
	 * @param array[] $segments Segments, by reference.
	 * @return void
	 */
	private static function reference( array $block, $path, array &$segments ) {
		$ref     = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;
		$pattern = $ref ? get_post( $ref ) : null;
		$title   = $pattern ? Text::from_html( $pattern->post_title ) : '';
		if ( '' === $title ) {
			/* translators: %d: ID of a synced pattern. */
			$title = sprintf( __( 'Pattern #%d', 'relecture' ), $ref );
		}
		self::add( $segments, 'reference', 'core/block', $path, $title, '', array( 'ref' => $ref ) );
	}

	/**
	 * Table block: one segment per cell, then the caption.
	 *
	 * @param array   $block    Parsed block.
	 * @param string  $path     Index path.
	 * @param array[] $segments Segments, by reference.
	 * @return void
	 */
	private static function table( array $block, $path, array &$segments ) {
		$html     = self::own_html( $block );
		$fragment = Html_Fragment::parse( $html );
		if ( ! $fragment ) {
			self::add( $segments, 'block', 'core/table', $path, Text::from_html( $html ), $html );
			return;
		}
		$row = 0;
		foreach ( $fragment->query( './/tr' ) as $tr ) {
			++$row;
			$col = 0;
			foreach ( $fragment->query( './th|./td', $tr ) as $cell ) {
				++$col;
				$cell_html = $fragment->inner_html( $cell );
				self::add(
					$segments,
					'cell',
					'core/table',
					$path,
					Text::from_html( $cell_html ),
					$cell_html,
					array(
						'row' => $row,
						'col' => $col,
					)
				);
			}
		}
		foreach ( $fragment->query( './/figcaption|.//caption' ) as $caption ) {
			$caption_html = $fragment->inner_html( $caption );
			self::add( $segments, 'caption', 'core/table', $path, Text::from_html( $caption_html ), $caption_html );
		}
	}

	/**
	 * Pullquote, or quote saved before quotes had inner blocks: the text, then its source.
	 *
	 * @param array   $block    Parsed block.
	 * @param string  $path     Index path.
	 * @param array[] $segments Segments, by reference.
	 * @return void
	 */
	private static function quote( array $block, $path, array &$segments ) {
		$name     = (string) $block['blockName'];
		$html     = self::own_html( $block );
		$fragment = Html_Fragment::parse( $html );
		if ( ! $fragment ) {
			self::add( $segments, 'block', $name, $path, Text::from_html( $html ), $html );
			return;
		}
		foreach ( $fragment->query( './/blockquote' ) as $index => $quote ) {
			if ( $index > 0 ) {
				break;
			}
			self::add( $segments, 'block', $name, $path, $fragment->own_text( $quote, './/cite' ), $fragment->inner_html( $quote ) );
		}
		foreach ( $fragment->query( './/cite' ) as $cite ) {
			$cite_html = $fragment->inner_html( $cite );
			self::add( $segments, 'citation', $name, $path, Text::from_html( $cite_html ), $cite_html );
		}
	}

	/**
	 * List saved before list items were blocks: one segment per item.
	 *
	 * @param array   $block    Parsed block.
	 * @param string  $path     Index path.
	 * @param array[] $segments Segments, by reference.
	 * @return void
	 */
	private static function legacy_list( array $block, $path, array &$segments ) {
		$html     = self::own_html( $block );
		$fragment = Html_Fragment::parse( $html );
		if ( ! $fragment ) {
			self::add( $segments, 'block', 'core/list', $path, Text::from_html( $html ), $html );
			return;
		}
		foreach ( $fragment->query( './/li' ) as $li ) {
			self::add( $segments, 'item', 'core/list', $path, $fragment->own_text( $li, './/ul|.//ol' ), $fragment->outer_html( $li ) );
		}
	}

	/**
	 * Embed: only the caption is prose (the wrapper holds the URL).
	 *
	 * @param array   $block    Parsed block.
	 * @param string  $path     Index path.
	 * @param array[] $segments Segments, by reference.
	 * @return void
	 */
	private static function embed_caption( array $block, $path, array &$segments ) {
		if ( preg_match( '#<figcaption\b[^>]*>(.*?)</figcaption>#is', self::own_html( $block ), $match ) ) {
			self::add( $segments, 'caption', (string) $block['blockName'], $path, Text::from_html( $match[1] ), $match[1] );
		}
	}

	/**
	 * Classic content (or Custom HTML): one segment per paragraph, heading, list item, cell…
	 *
	 * @param string  $html       HTML.
	 * @param string  $block_name Block name ('' outside blocks).
	 * @param string  $path       Index path.
	 * @param array[] $segments   Segments, by reference.
	 * @param bool    $autop      Apply wpautop() first (classic editor content).
	 * @return void
	 */
	private static function classic( $html, $block_name, $path, array &$segments, $autop ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return;
		}
		// Keep the text of [caption] shortcodes, drop the other shortcodes.
		$html = preg_replace( '#\[caption\b[^\]]*\](.*?)\[/caption\]#is', '<figure>$1</figure>', $html );
		$html = strip_shortcodes( $html );
		if ( $autop ) {
			$html = wpautop( $html );
		}

		$fragment = Html_Fragment::parse( $html );
		if ( ! $fragment ) {
			self::add( $segments, 'classic', $block_name, $path, Text::from_html( $html ), $html, array( 'tag' => 'p' ) );
			return;
		}

		$nested = './/' . implode( '|.//', array_merge( self::CLASSIC_TAGS, self::SKIPPED_TAGS ) );

		// Text that sits directly in the fragment, outside any block element.
		self::add( $segments, 'classic', $block_name, $path, $fragment->own_text( null, $nested ), '', array( 'tag' => 'p' ) );

		foreach ( $fragment->query( './/' . implode( '|.//', array_merge( self::CLASSIC_TAGS, array( 'img' ) ) ) ) as $element ) {
			// Elements inside skipped containers (a script, an SVG…) are not prose.
			if ( $fragment->query( 'ancestor::' . implode( '|ancestor::', array_diff( self::SKIPPED_TAGS, array( 'ul', 'ol', 'dl', 'table' ) ) ), $element ) ) {
				continue;
			}
			$tag = strtolower( $element->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			if ( 'img' === $tag ) {
				self::add( $segments, 'alt', $block_name, $path, Html_Fragment::attribute( $element, 'alt' ), '', array( 'tag' => 'img' ) );
				continue;
			}
			self::add(
				$segments,
				'classic',
				$block_name,
				$path,
				$fragment->own_text( $element, $nested ),
				$fragment->outer_html( $element ),
				array( 'tag' => $tag )
			);
		}
	}
}
