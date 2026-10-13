<?php
/**
 * The data of a review, as handed to every export format and to the REST API.
 *
 * @package Relecture
 */

namespace Relecture\Export;

use Relecture\Labels;
use Relecture\Plugin;
use Relecture\Repository;
use Relecture\Review_Service;
use Relecture\Text;

defined( 'ABSPATH' ) || exit;

/**
 * One builder for every consumer: the REST API, the five export formats and WP-CLI see
 * the same fields, so what an AI reads in JSON is what a person reads in Word.
 */
final class Export_Data {

	/**
	 * Identifier and version of the JSON structure.
	 */
	const SCHEMA = 'relecture/review@1';

	/**
	 * Full data of a review.
	 *
	 * @param array    $review Review.
	 * @param \WP_Post $post   Reviewed post.
	 * @param array    $args {
	 *     Optional. Export options.
	 *
	 *     @type bool $only_with_feedback Keep only the rows with a correction or a note.
	 *     @type bool $include_html       Include the source HTML of each row.
	 * }
	 * @return array
	 */
	public static function review( array $review, \WP_Post $post, array $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'only_with_feedback' => false,
				'include_html'       => true,
			)
		);
		$items = Repository::get_items( $review['id'] );
		$stats = Repository::stats( array( $review['id'] ) );

		$rows       = array();
		$references = array();
		$guidelines = array();
		foreach ( self::numbered( $items ) as $number => $item ) {
			if ( 'reference' === $item['segment_type'] ) {
				$references[] = array(
					'title'      => $item['original'],
					'post_id'    => isset( $item['meta']['ref'] ) ? (int) $item['meta']['ref'] : 0,
					'block_path' => $item['block_path'],
				);
				continue;
			}
			if ( 'guideline' === $item['note_type'] && Text::filled( $item['note'] ) ) {
				$guidelines[] = array(
					'number'           => $number,
					'note'             => $item['note'],
					'guideline_status' => $item['guideline_status'],
					'context'          => Text::excerpt( $item['original'] ),
				);
			}
			if ( $args['only_with_feedback'] && ! Review_Service::has_feedback( $item ) ) {
				continue;
			}
			$rows[] = self::item( $item, $number, (bool) $args['include_html'] );
		}

		return array(
			'schema'       => self::SCHEMA,
			'generated_at' => self::iso( Review_Service::now() ),
			'site'         => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
			'post'         => self::post( $post ),
			'review'       => self::review_meta( $review, $post ),
			'filters'      => array( 'only_with_feedback' => (bool) $args['only_with_feedback'] ),
			'stats'        => isset( $stats[ $review['id'] ] ) ? $stats[ $review['id'] ] : Repository::empty_stats(),
			'items'        => $rows,
			'guidelines'   => $guidelines,
			'references'   => $references,
		);
	}

	/**
	 * Items keyed by their row number as displayed (synced-pattern rows are not counted).
	 *
	 * @param array[] $items Items in display order.
	 * @return array<int, array>
	 */
	public static function numbered( array $items ) {
		$numbered = array();
		$number   = 0;
		$info     = 0;
		foreach ( $items as $item ) {
			if ( 'reference' === $item['segment_type'] ) {
				// Negative keys keep information rows in place without consuming a number.
				$numbered[ --$info ] = $item;
				continue;
			}
			$numbered[ ++$number ] = $item;
		}
		return $numbered;
	}

	/**
	 * Public fields of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function post( \WP_Post $post ) {
		$type   = get_post_type_object( $post->post_type );
		$status = get_post_status_object( $post->post_status );
		$edit   = get_edit_post_link( $post->ID, 'raw' );
		return array(
			'id'           => (int) $post->ID,
			'type'         => $post->post_type,
			'type_label'   => $type ? (string) $type->labels->singular_name : $post->post_type,
			'title'        => Text::from_html( $post->post_title ),
			'status'       => $post->post_status,
			'status_label' => $status ? (string) $status->label : $post->post_status,
			'link'         => (string) get_permalink( $post ),
			'edit_link'    => $edit ? (string) $edit : admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' ),
		);
	}

	/**
	 * Public fields of a review.
	 *
	 * @param array    $review Review.
	 * @param \WP_Post $post   Reviewed post.
	 * @return array
	 */
	public static function review_meta( array $review, \WP_Post $post ) {
		$round = 0;
		foreach ( Repository::get_post_reviews( $post->ID ) as $other ) {
			if ( $other['id'] <= $review['id'] ) {
				++$round;
			}
		}
		return array(
			'id'           => (int) $review['id'],
			'round'        => $round,
			'status'       => $review['status'],
			'status_label' => Labels::of( Labels::review_statuses(), $review['status'] ),
			'created_at'   => self::iso( $review['created_at'] ),
			'created_by'   => self::user_name( $review['created_by'] ),
			'updated_at'   => self::iso( $review['updated_at'] ),
			'updated_by'   => self::user_name( $review['updated_by'] ),
			'synced_at'    => self::iso( $review['synced_at'] ),
			'outdated'     => Review_Service::is_outdated( $review, $post ),
			'url'          => Plugin::review_url( $post->ID, $review['id'] ),
		);
	}

	/**
	 * Public fields of an item.
	 *
	 * @param array $item         Item.
	 * @param int   $number       Row number as displayed.
	 * @param bool  $include_html Include the source HTML.
	 * @return array
	 */
	public static function item( array $item, $number = 0, $include_html = true ) {
		$data = array(
			'id'                      => (int) $item['id'],
			'number'                  => (int) $number,
			'type'                    => $item['segment_type'],
			'label'                   => Labels::segment( $item ),
			'block_name'              => $item['block_name'],
			'block_path'              => $item['block_path'],
			'meta'                    => (object) $item['meta'],
			'original'                => $item['original'],
			'correction'              => (string) $item['correction'],
			'correction_status'       => (string) $item['correction_status'],
			'correction_status_label' => '' === $item['correction_status'] ? '' : Labels::of( Labels::correction_statuses(), $item['correction_status'] ),
			'note'                    => (string) $item['note'],
			'note_type'               => $item['note_type'],
			'note_type_label'         => Text::filled( $item['note'] ) ? Labels::of( Labels::note_types(), $item['note_type'] ) : '',
			'guideline_status'        => $item['guideline_status'],
			'orphaned'                => (bool) $item['orphaned'],
			'version'                 => (int) $item['version'],
			'updated_at'              => self::iso( $item['updated_at'] ),
			'updated_by'              => self::user_name( $item['updated_by'] ),
		);
		if ( $include_html ) {
			$data['original_html'] = (string) $item['original_html'];
		}
		return $data;
	}

	/**
	 * ISO 8601 date (UTC) from a MySQL GMT date, or null.
	 *
	 * @param string|null $mysql_gmt Date.
	 * @return string|null
	 */
	public static function iso( $mysql_gmt ) {
		if ( empty( $mysql_gmt ) || 0 === strpos( (string) $mysql_gmt, '0000' ) ) {
			return null;
		}
		$time = strtotime( $mysql_gmt . ' UTC' );
		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}

	/**
	 * Date for people, in the site's time zone and formats.
	 *
	 * @param string|null $iso ISO 8601 date.
	 * @return string
	 */
	public static function human_date( $iso ) {
		$time = $iso ? strtotime( $iso ) : false;
		if ( false === $time ) {
			return '';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Display name of a user ('' when unknown).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_name( $user_id ) {
		$user = $user_id ? get_userdata( (int) $user_id ) : false;
		return $user ? (string) $user->display_name : '';
	}
}
