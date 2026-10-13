<?php
/**
 * Database access to the plugin's two tables.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, edited on every keystroke pause: an object cache would only add invalidation bugs.

/**
 * Reviews and their items.
 *
 * Every query is a fixed SQL string run through $wpdb->prepare() (or $wpdb->insert() /
 * update() / delete()): table names are passed as %i identifiers, optional filters use
 * “( %d = 0 OR column = %d )” instead of string building, and lists of IDs use the
 * implode( array_fill() ) idiom.
 */
final class Repository {

	/**
	 * Columns of the reviews table and their formats.
	 *
	 * @var array<string, string>
	 */
	const REVIEW_COLUMNS = array(
		'post_id'      => '%d',
		'status'       => '%s',
		'content_hash' => '%s',
		'created_by'   => '%d',
		'created_at'   => '%s',
		'updated_by'   => '%d',
		'updated_at'   => '%s',
		'synced_at'    => '%s',
	);

	/**
	 * Columns of the items table and their formats.
	 *
	 * @var array<string, string>
	 */
	const ITEM_COLUMNS = array(
		'review_id'         => '%d',
		'position'          => '%d',
		'segment_type'      => '%s',
		'block_name'        => '%s',
		'block_path'        => '%s',
		'meta'              => '%s',
		'fingerprint'       => '%s',
		'original'          => '%s',
		'original_html'     => '%s',
		'correction'        => '%s',
		'correction_status' => '%s',
		'note'              => '%s',
		'note_type'         => '%s',
		'guideline_status'  => '%s',
		'orphaned'          => '%d',
		'version'           => '%d',
		'updated_by'        => '%d',
		'updated_at'        => '%s',
	);

	/**
	 * Name of the reviews table.
	 *
	 * @return string
	 */
	public static function reviews_table() {
		global $wpdb;
		return $wpdb->prefix . 'relecture_reviews';
	}

	/**
	 * Name of the items table.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'relecture_items';
	}

	/* ------------------------------------------------------------------ Reviews */

	/**
	 * One review.
	 *
	 * @param int $id Review ID.
	 * @return array|null
	 */
	public static function get_review( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::reviews_table(), $id ),
			ARRAY_A
		);
		return $row ? self::hydrate_review( $row ) : null;
	}

	/**
	 * Latest review (whatever its status) of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_latest_review( $post_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC LIMIT 1', self::reviews_table(), $post_id ),
			ARRAY_A
		);
		return $row ? self::hydrate_review( $row ) : null;
	}

	/**
	 * All reviews (rounds) of a post, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function get_post_reviews( $post_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC', self::reviews_table(), $post_id ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'hydrate_review' ), (array) $rows );
	}

	/**
	 * Latest review of each of the given posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, array> Reviews keyed by post ID.
	 */
	public static function get_latest_reviews( array $post_ids ) {
		global $wpdb;
		$post_ids = self::ids( $post_ids );
		if ( ! $post_ids ) {
			return array();
		}
		$table   = self::reviews_table();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.* FROM %i r INNER JOIN ( SELECT MAX(id) AS id FROM %i WHERE post_id IN ( ' . implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) ) . ' ) GROUP BY post_id ) latest ON latest.id = r.id',
				...array_merge( array( $table, $table ), $post_ids )
			),
			ARRAY_A
		);
		$reviews = array();
		foreach ( (array) $rows as $row ) {
			$review                        = self::hydrate_review( $row );
			$reviews[ $review['post_id'] ] = $review;
		}
		return $reviews;
	}

	/**
	 * IDs of the posts whose latest review has a given status (or that have any review).
	 *
	 * @param string|null $status Review status, or null for “any review”.
	 * @return int[]
	 */
	public static function post_ids_by_latest_status( $status = null ) {
		global $wpdb;
		$table = self::reviews_table();
		if ( null === $status ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_id FROM %i', $table ) );
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT r.post_id FROM %i r INNER JOIN ( SELECT MAX(id) AS id FROM %i GROUP BY post_id ) latest ON latest.id = r.id WHERE r.status = %s',
					$table,
					$table,
					$status
				)
			);
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Reviews matching filters, newest first (REST and WP-CLI listings).
	 *
	 * @param array $args {
	 *     Optional. Filters and paging.
	 *
	 *     @type int    $post_id  Restrict to a post (0: any).
	 *     @type string $status   Restrict to a status ('': any).
	 *     @type int    $per_page Page size (1–100).
	 *     @type int    $page     Page number, from 1.
	 * }
	 * @return array[]
	 */
	public static function query_reviews( array $args = array() ) {
		global $wpdb;
		$post_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %d = 0 OR post_id = %d ) AND ( %s = '' OR status = %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
				self::reviews_table(),
				$post_id,
				$post_id,
				$status,
				$status,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'hydrate_review' ), (array) $rows );
	}

	/**
	 * Inserts a review.
	 *
	 * @param array $data Column values.
	 * @return int New review ID (0 on failure).
	 */
	public static function insert_review( array $data ) {
		global $wpdb;
		$data = array_intersect_key( $data, self::REVIEW_COLUMNS );
		$ok   = $wpdb->insert( self::reviews_table(), $data, self::formats( $data, self::REVIEW_COLUMNS ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates a review.
	 *
	 * @param int   $id   Review ID.
	 * @param array $data Column values.
	 * @return bool
	 */
	public static function update_review( $id, array $data ) {
		global $wpdb;
		$data = array_intersect_key( $data, self::REVIEW_COLUMNS );
		if ( ! $data ) {
			return false;
		}
		return false !== $wpdb->update( self::reviews_table(), $data, array( 'id' => (int) $id ), self::formats( $data, self::REVIEW_COLUMNS ), array( '%d' ) );
	}

	/**
	 * Deletes reviews and their items.
	 *
	 * @param int[] $review_ids Review IDs.
	 * @return void
	 */
	public static function delete_reviews( array $review_ids ) {
		global $wpdb;
		$review_ids = self::ids( $review_ids );
		if ( ! $review_ids ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE review_id IN ( ' . implode( ', ', array_fill( 0, count( $review_ids ), '%d' ) ) . ' )',
				...array_merge( array( self::items_table() ), $review_ids )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id IN ( ' . implode( ', ', array_fill( 0, count( $review_ids ), '%d' ) ) . ' )',
				...array_merge( array( self::reviews_table() ), $review_ids )
			)
		);
	}

	/* -------------------------------------------------------------------- Items */

	/**
	 * Items of a review: current text first (in reading order), then orphaned rows.
	 *
	 * @param int $review_id Review ID.
	 * @return array[]
	 */
	public static function get_items( $review_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE review_id = %d ORDER BY orphaned ASC, position ASC, id ASC', self::items_table(), $review_id ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'hydrate_item' ), (array) $rows );
	}

	/**
	 * One item.
	 *
	 * @param int $id Item ID.
	 * @return array|null
	 */
	public static function get_item( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::items_table(), $id ),
			ARRAY_A
		);
		return $row ? self::hydrate_item( $row ) : null;
	}

	/**
	 * Inserts items.
	 *
	 * @param array[] $items Rows (column => value; null writes NULL).
	 * @return void
	 */
	public static function insert_items( array $items ) {
		global $wpdb;
		foreach ( $items as $item ) {
			$data = array_intersect_key( $item, self::ITEM_COLUMNS );
			$wpdb->insert( self::items_table(), $data, self::formats( $data, self::ITEM_COLUMNS ) );
		}
	}

	/**
	 * Updates an item.
	 *
	 * With $expected_version, the update only happens if nobody changed the row in the
	 * meantime (optimistic lock). Visible changes increment the version.
	 *
	 * @param int      $id               Item ID.
	 * @param array    $data             Column values (null writes NULL).
	 * @param int|null $expected_version Version the caller started from, or null.
	 * @param bool     $bump_version     Whether this change is visible to concurrent editors.
	 * @return bool False when the row was not found or the version did not match.
	 */
	public static function update_item( $id, array $data, $expected_version = null, $bump_version = true ) {
		global $wpdb;
		$data = array_intersect_key( $data, self::ITEM_COLUMNS );
		unset( $data['version'] );

		if ( ! $bump_version ) {
			if ( ! $data ) {
				return true;
			}
			return false !== $wpdb->update( self::items_table(), $data, array( 'id' => (int) $id ), self::formats( $data, self::ITEM_COLUMNS ), array( '%d' ) );
		}

		if ( null === $expected_version ) {
			$current = self::get_item( $id );
			if ( ! $current ) {
				return false;
			}
			$expected_version = $current['version'];
		}
		$data['version'] = (int) $expected_version + 1;

		// The version moves forward, so a matching row always counts as changed: 0 rows means
		// somebody else saved first.
		$changed = $wpdb->update(
			self::items_table(),
			$data,
			array(
				'id'      => (int) $id,
				'version' => (int) $expected_version,
			),
			self::formats( $data, self::ITEM_COLUMNS ),
			array( '%d', '%d' )
		);
		return is_int( $changed ) && $changed > 0;
	}

	/**
	 * Deletes items.
	 *
	 * @param int[] $ids Item IDs.
	 * @return void
	 */
	public static function delete_items( array $ids ) {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( ! $ids ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )',
				...array_merge( array( self::items_table() ), $ids )
			)
		);
	}

	/**
	 * Counters of each review: segments, corrections, notes, guidelines, orphaned rows.
	 *
	 * @param int[] $review_ids Review IDs.
	 * @return array<int, array<string, int>> Counters keyed by review ID.
	 */
	public static function stats( array $review_ids ) {
		global $wpdb;
		$review_ids = self::ids( $review_ids );
		if ( ! $review_ids ) {
			return array();
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT review_id, SUM( segment_type <> 'reference' ) AS segments, SUM( correction IS NOT NULL AND correction <> '' ) AS corrections, SUM( note IS NOT NULL AND note <> '' ) AS notes, SUM( note IS NOT NULL AND note <> '' AND note_type = 'guideline' ) AS guidelines, SUM( orphaned = 1 ) AS orphaned FROM %i WHERE review_id IN ( " . implode( ', ', array_fill( 0, count( $review_ids ), '%d' ) ) . ' ) GROUP BY review_id',
				...array_merge( array( self::items_table() ), $review_ids )
			),
			ARRAY_A
		);
		$stats = array();
		foreach ( $review_ids as $review_id ) {
			$stats[ $review_id ] = self::empty_stats();
		}
		foreach ( (array) $rows as $row ) {
			$stats[ (int) $row['review_id'] ] = array(
				'segments'    => (int) $row['segments'],
				'corrections' => (int) $row['corrections'],
				'notes'       => (int) $row['notes'],
				'guidelines'  => (int) $row['guidelines'],
				'orphaned'    => (int) $row['orphaned'],
			);
		}
		return $stats;
	}

	/**
	 * Zeroed counters.
	 *
	 * @return array<string, int>
	 */
	public static function empty_stats() {
		return array(
			'segments'    => 0,
			'corrections' => 0,
			'notes'       => 0,
			'guidelines'  => 0,
			'orphaned'    => 0,
		);
	}

	/* --------------------------------------------------------------- Guidelines */

	/**
	 * Guidelines: notes flagged “for future content”, across every review, newest first.
	 *
	 * @param array $args {
	 *     Optional. Filters and paging.
	 *
	 *     @type string   $status     'new', 'integrated' or '' for both.
	 *     @type int      $author     Restrict to posts of this author (0: no restriction).
	 *     @type string[] $post_types Reviewable post types.
	 *     @type int      $per_page   Page size (0: no limit).
	 *     @type int      $page       Page number, from 1.
	 *     @type bool     $count      Return the total instead of rows.
	 * }
	 * @return array[]|int
	 */
	public static function query_guidelines( array $args = array() ) {
		global $wpdb;
		$types    = ! empty( $args['post_types'] ) ? array_values( array_map( 'strval', (array) $args['post_types'] ) ) : array( 'post' );
		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$author   = isset( $args['author'] ) ? (int) $args['author'] : 0;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 0;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$tables   = array( self::items_table(), self::reviews_table(), $wpdb->posts );
		$filters  = array_merge( $types, array( $status, $status, $author, $author ) );

		if ( ! empty( $args['count'] ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i i INNER JOIN %i r ON r.id = i.review_id INNER JOIN %i p ON p.ID = r.post_id WHERE i.note_type = 'guideline' AND i.note IS NOT NULL AND i.note <> '' AND p.post_status <> 'trash' AND p.post_type IN ( " . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . " ) AND ( %s = '' OR i.guideline_status = %s ) AND ( %d = 0 OR p.post_author = %d )",
					...array_merge( $tables, $filters )
				)
			);
		}

		$limit = $per_page > 0 ? max( 1, min( 500, $per_page ) ) : PHP_INT_MAX;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*, r.post_id, p.post_title, p.post_type FROM %i i INNER JOIN %i r ON r.id = i.review_id INNER JOIN %i p ON p.ID = r.post_id WHERE i.note_type = 'guideline' AND i.note IS NOT NULL AND i.note <> '' AND p.post_status <> 'trash' AND p.post_type IN ( " . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . " ) AND ( %s = '' OR i.guideline_status = %s ) AND ( %d = 0 OR p.post_author = %d ) ORDER BY i.updated_at DESC, i.id DESC LIMIT %d OFFSET %d",
				...array_merge( $tables, $filters, array( $limit, $per_page > 0 ? ( $page - 1 ) * $limit : 0 ) )
			),
			ARRAY_A
		);

		$guidelines = array();
		foreach ( (array) $rows as $row ) {
			$item               = self::hydrate_item( $row );
			$item['post_id']    = (int) $row['post_id'];
			$item['post_title'] = (string) $row['post_title'];
			$item['post_type']  = (string) $row['post_type'];
			$guidelines[]       = $item;
		}
		return $guidelines;
	}

	/* ---------------------------------------------------------------- Helpers */

	/**
	 * Positive integer IDs, without duplicates.
	 *
	 * @param array $ids IDs.
	 * @return int[]
	 */
	private static function ids( array $ids ) {
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Formats of the given columns, in order.
	 *
	 * @param array                 $data    Column values.
	 * @param array<string, string> $columns Column map.
	 * @return string[]
	 */
	private static function formats( array $data, array $columns ) {
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = $columns[ $column ];
		}
		return $formats;
	}

	/**
	 * Casts a review row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_review( array $row ) {
		$row['id']         = (int) $row['id'];
		$row['post_id']    = (int) $row['post_id'];
		$row['created_by'] = (int) $row['created_by'];
		$row['updated_by'] = (int) $row['updated_by'];
		return $row;
	}

	/**
	 * Casts an item row and decodes its metadata.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate_item( array $row ) {
		foreach ( array( 'id', 'review_id', 'position', 'orphaned', 'version', 'updated_by' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}
		$meta              = isset( $row['meta'] ) ? json_decode( (string) $row['meta'], true ) : null;
		$row['meta']       = is_array( $meta ) ? $meta : array();
		$row['correction'] = isset( $row['correction'] ) ? (string) $row['correction'] : '';
		$row['note']       = isset( $row['note'] ) ? (string) $row['note'] : '';
		return $row;
	}
}
