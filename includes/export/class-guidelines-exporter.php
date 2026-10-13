<?php
/**
 * Export of the guidelines gathered across reviews.
 *
 * @package Relecture
 */

namespace Relecture\Export;

use Relecture\Labels;
use Relecture\Plugin;
use Relecture\Review_Service;
use Relecture\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Guidelines (“for future content” notes) as Markdown, CSV or JSON: ready to paste into a
 * style guide or a prompt.
 */
final class Guidelines_Exporter {

	/**
	 * Supported formats.
	 */
	const FORMATS = array( 'md', 'csv', 'json' );

	/**
	 * Data of a guidelines export.
	 *
	 * @param array[] $guidelines Rows from Repository::query_guidelines().
	 * @param string  $status     Status filter ('' for all).
	 * @return array
	 */
	public static function data( array $guidelines, $status ) {
		$rows = array();
		foreach ( $guidelines as $guideline ) {
			$rows[] = array(
				'id'                     => (int) $guideline['id'],
				'note'                   => $guideline['note'],
				'guideline_status'       => $guideline['guideline_status'],
				'guideline_status_label' => Labels::of( Labels::guideline_statuses(), $guideline['guideline_status'] ),
				'element'                => Labels::segment( $guideline ),
				'context'                => $guideline['original'],
				'post'                   => array(
					'id'         => (int) $guideline['post_id'],
					'title'      => Text::from_html( $guideline['post_title'] ),
					'type'       => $guideline['post_type'],
					'link'       => (string) get_permalink( $guideline['post_id'] ),
					'review_url' => Plugin::review_url( $guideline['post_id'], $guideline['review_id'] ),
				),
				'updated_at'             => Export_Data::iso( $guideline['updated_at'] ),
				'updated_by'             => Export_Data::user_name( $guideline['updated_by'] ),
			);
		}
		return array(
			'schema'       => 'relecture/guidelines@1',
			'generated_at' => Export_Data::iso( Review_Service::now() ),
			'site'         => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
			'status'       => '' === $status ? 'all' : $status,
			'guidelines'   => $rows,
		);
	}

	/**
	 * Renders an export.
	 *
	 * @param array  $data   Data from data().
	 * @param string $format Format.
	 * @return array|\WP_Error array( 'contents' => string ).
	 */
	public static function render( array $data, $format ) {
		switch ( $format ) {
			case 'json':
				return array( 'contents' => Exporter::json( $data ) );
			case 'csv':
				return array( 'contents' => Csv_Writer::build( self::rows( $data ) ) );
			case 'md':
				return array( 'contents' => self::markdown( $data ) );
		}
		return new \WP_Error( 'relecture_format', __( 'Unknown export format.', 'relecture' ), array( 'status' => 400 ) );
	}

	/**
	 * Sends an export to the browser and ends the request.
	 *
	 * @param array  $data   Data from data().
	 * @param string $format Format.
	 * @return void
	 */
	public static function send( array $data, $format ) {
		Exporter::deliver( self::render( $data, $format ), Exporter::filename( __( 'guidelines', 'relecture' ), $format ), Exporter::mime( $format ) );
	}

	/**
	 * CSV rows.
	 *
	 * @param array $data Data from data().
	 * @return array[]
	 */
	private static function rows( array $data ) {
		$rows = array(
			array(
				__( 'Guideline', 'relecture' ),
				__( 'Status', 'relecture' ),
				__( 'Content', 'relecture' ),
				__( 'Element', 'relecture' ),
				__( 'Original text', 'relecture' ),
				__( 'Last change', 'relecture' ),
				__( 'Address', 'relecture' ),
				__( 'ID', 'relecture' ),
			),
		);
		foreach ( $data['guidelines'] as $guideline ) {
			$rows[] = array(
				$guideline['note'],
				$guideline['guideline_status_label'],
				$guideline['post']['title'],
				$guideline['element'],
				$guideline['context'],
				Export_Data::human_date( $guideline['updated_at'] ),
				$guideline['post']['link'],
				$guideline['id'],
			);
		}
		return $rows;
	}

	/**
	 * Markdown, grouped by content.
	 *
	 * @param array $data Data from data().
	 * @return string
	 */
	private static function markdown( array $data ) {
		/* translators: %s: site name. */
		$md  = '# ' . Markdown_Writer::inline( sprintf( __( 'Writing guidelines — %s', 'relecture' ), $data['site']['name'] ) ) . "\n\n";
		$md .= '_' . Markdown_Writer::inline(
			sprintf(
				/* translators: 1: number of guidelines, 2: export date. */
				_n( '%1$d guideline, exported on %2$s.', '%1$d guidelines, exported on %2$s.', count( $data['guidelines'] ), 'relecture' ),
				count( $data['guidelines'] ),
				Export_Data::human_date( $data['generated_at'] )
			)
		) . "_\n\n";

		$by_post = array();
		foreach ( $data['guidelines'] as $guideline ) {
			$by_post[ $guideline['post']['id'] ][] = $guideline;
		}
		foreach ( $by_post as $guidelines ) {
			$post = $guidelines[0]['post'];
			$md  .= '## ' . Markdown_Writer::inline( $post['title'] ) . "\n\n";
			if ( '' !== $post['link'] ) {
				$md .= '<' . esc_url_raw( $post['link'] ) . ">\n\n";
			}
			foreach ( $guidelines as $guideline ) {
				$md .= Markdown_Writer::list_item( $guideline['note'] ) . "\n";
				/* translators: %s: excerpt of the text the guideline was written about. */
				$md .= '  _' . Markdown_Writer::inline( sprintf( __( 'About: “%s”', 'relecture' ), Text::excerpt( $guideline['context'], 90 ) ) ) . "_\n";
			}
			$md .= "\n";
		}
		return $md;
	}
}
