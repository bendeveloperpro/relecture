<?php
/**
 * Business rules of a review.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Starting, syncing, editing and closing reviews.
 *
 * Callers check permissions first (Permissions::can_review_post()); this class enforces
 * the rules that do not depend on who asks (closed reviews are read-only, a correction
 * identical to the original is no correction…).
 */
final class Review_Service {

	/**
	 * Review statuses, in workflow order.
	 */
	const REVIEW_STATUSES = array( 'in_progress', 'ready', 'done' );

	/**
	 * Statuses of a correction.
	 */
	const CORRECTION_STATUSES = array( 'pending', 'accepted', 'rejected', 'applied' );

	/**
	 * Types of note.
	 */
	const NOTE_TYPES = array( 'post', 'guideline' );

	/**
	 * Statuses of a guideline.
	 */
	const GUIDELINE_STATUSES = array( 'new', 'integrated' );

	/**
	 * Current time, UTC, MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Fingerprint of everything a review is built from.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function content_hash( \WP_Post $post ) {
		return hash( 'sha256', $post->post_title . "\0" . $post->post_excerpt . "\0" . $post->post_content );
	}

	/**
	 * Opens the review of a post: the latest one, or a new one if there is none (or if a
	 * new round is asked for and the latest one is closed).
	 *
	 * @param \WP_Post $post      Post.
	 * @param int      $user_id   User starting the review.
	 * @param bool     $new_round Start a new round after a closed review.
	 * @return array Review.
	 */
	public static function start( \WP_Post $post, $user_id, $new_round = false ) {
		$latest = Repository::get_latest_review( $post->ID );
		if ( $latest && ( 'done' !== $latest['status'] || ! $new_round ) ) {
			return $latest;
		}

		$now       = self::now();
		$review_id = Repository::insert_review(
			array(
				'post_id'      => $post->ID,
				'status'       => 'in_progress',
				'content_hash' => self::content_hash( $post ),
				'created_by'   => (int) $user_id,
				'created_at'   => $now,
				'updated_by'   => (int) $user_id,
				'updated_at'   => $now,
				'synced_at'    => $now,
			)
		);

		$rows     = array();
		$position = 0;
		foreach ( Extractor::extract( $post ) as $segment ) {
			$rows[] = self::item_row( $review_id, $segment, ++$position );
		}
		Repository::insert_items( $rows );

		/**
		 * Fires after a review was started.
		 *
		 * @param int $review_id Review ID.
		 * @param int $post_id   Post ID.
		 */
		do_action( 'relecture_review_started', $review_id, $post->ID );

		return Repository::get_review( $review_id );
	}

	/**
	 * Database row of a new item.
	 *
	 * @param int   $review_id Review ID.
	 * @param array $segment   Segment from the extractor.
	 * @param int   $position  Position in reading order.
	 * @return array
	 */
	private static function item_row( $review_id, array $segment, $position ) {
		return array(
			'review_id'         => (int) $review_id,
			'position'          => (int) $position,
			'segment_type'      => $segment['segment_type'],
			'block_name'        => $segment['block_name'],
			'block_path'        => $segment['block_path'],
			'meta'              => wp_json_encode( (object) $segment['meta'] ),
			'fingerprint'       => $segment['fingerprint'],
			'original'          => $segment['original'],
			'original_html'     => $segment['original_html'],
			'correction'        => null,
			'correction_status' => '',
			'note'              => null,
			'note_type'         => 'post',
			'guideline_status'  => 'new',
			'orphaned'          => 0,
			'updated_by'        => 0,
			'updated_at'        => null,
		);
	}

	/**
	 * Whether the post changed since the review's snapshot.
	 *
	 * @param array    $review Review.
	 * @param \WP_Post $post   Post.
	 * @return bool
	 */
	public static function is_outdated( array $review, \WP_Post $post ) {
		return ! hash_equals( (string) $review['content_hash'], self::content_hash( $post ) );
	}

	/**
	 * Takes a new snapshot of the text, keeping the feedback already given.
	 *
	 * Rows are matched by fingerprint (type + block + exact text), in reading order for
	 * duplicates. Rows whose text disappeared are kept as “orphaned” when they carry a
	 * correction or a note, deleted otherwise.
	 *
	 * @param array    $review  Review.
	 * @param \WP_Post $post    Post.
	 * @param int      $user_id User asking for the sync.
	 * @return array Counters: kept, added, orphaned, removed.
	 */
	public static function resync( array $review, \WP_Post $post, $user_id ) {
		$pool = array();
		foreach ( Repository::get_items( $review['id'] ) as $item ) {
			$pool[ $item['fingerprint'] ][] = $item;
		}

		$counts   = array(
			'kept'     => 0,
			'added'    => 0,
			'orphaned' => 0,
			'removed'  => 0,
		);
		$new_rows = array();
		$position = 0;
		foreach ( Extractor::extract( $post ) as $segment ) {
			++$position;
			if ( ! empty( $pool[ $segment['fingerprint'] ] ) ) {
				$item = array_shift( $pool[ $segment['fingerprint'] ] );
				Repository::update_item(
					$item['id'],
					array(
						'position'      => $position,
						'block_path'    => $segment['block_path'],
						'meta'          => wp_json_encode( (object) $segment['meta'] ),
						'original_html' => $segment['original_html'],
						'orphaned'      => 0,
					),
					null,
					false
				);
				++$counts['kept'];
			} else {
				$new_rows[] = self::item_row( $review['id'], $segment, $position );
				++$counts['added'];
			}
		}
		Repository::insert_items( $new_rows );

		$delete = array();
		foreach ( $pool as $leftovers ) {
			foreach ( $leftovers as $item ) {
				if ( self::has_feedback( $item ) ) {
					Repository::update_item(
						$item['id'],
						array(
							'orphaned' => 1,
							'position' => ++$position,
						),
						null,
						false
					);
					++$counts['orphaned'];
				} else {
					$delete[] = $item['id'];
					++$counts['removed'];
				}
			}
		}
		Repository::delete_items( $delete );

		$now = self::now();
		Repository::update_review(
			$review['id'],
			array(
				'content_hash' => self::content_hash( $post ),
				'synced_at'    => $now,
				'updated_at'   => $now,
				'updated_by'   => (int) $user_id,
			)
		);

		return $counts;
	}

	/**
	 * Whether an item carries a correction or a note.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	public static function has_feedback( array $item ) {
		return Text::filled( $item['correction'] ) || Text::filled( $item['note'] );
	}

	/**
	 * Applies changes to an item.
	 *
	 * @param array    $item             Item (as stored).
	 * @param array    $changes          Validated changes: correction, note, note_type,
	 *                                   correction_status, guideline_status.
	 * @param int      $user_id          Author of the change.
	 * @param int|null $expected_version Version the user started from (optimistic lock).
	 * @return array|\WP_Error Updated item.
	 */
	public static function update_item( array $item, array $changes, $user_id, $expected_version = null ) {
		$review = Repository::get_review( $item['review_id'] );
		if ( ! $review ) {
			return new \WP_Error( 'relecture_not_found', __( 'This review no longer exists.', 'relecture' ), array( 'status' => 404 ) );
		}
		// A closed review is read-only; only the guidelines it produced keep a life of their own.
		if ( 'done' === $review['status'] && array_diff( array_keys( $changes ), array( 'guideline_status' ) ) ) {
			return new \WP_Error( 'relecture_review_closed', __( 'This review is closed. Reopen it to edit it.', 'relecture' ), array( 'status' => 409 ) );
		}

		$data = array();

		if ( array_key_exists( 'correction', $changes ) ) {
			$correction = Text::sanitize_input( $changes['correction'] );
			if ( '' !== $correction && Text::same( $correction, $item['original'] ) ) {
				$correction = '';
			}
			$data['correction'] = '' === $correction ? null : $correction;
		}
		$has_correction = array_key_exists( 'correction', $data ) ? null !== $data['correction'] : Text::filled( $item['correction'] );

		if ( isset( $changes['correction_status'] ) && in_array( $changes['correction_status'], self::CORRECTION_STATUSES, true ) ) {
			$data['correction_status'] = $changes['correction_status'];
		}
		if ( ! $has_correction ) {
			$data['correction_status'] = '';
		} elseif ( '' === $item['correction_status'] && ! isset( $data['correction_status'] ) ) {
			$data['correction_status'] = 'pending';
		}

		$note_changed = false;
		if ( array_key_exists( 'note', $changes ) ) {
			$note         = Text::sanitize_input( $changes['note'] );
			$data['note'] = '' === $note ? null : $note;
			$note_changed = (string) $data['note'] !== (string) $item['note'];
		}
		if ( isset( $changes['note_type'] ) && in_array( $changes['note_type'], self::NOTE_TYPES, true ) ) {
			$data['note_type'] = $changes['note_type'];
		}
		if ( isset( $changes['guideline_status'] ) && in_array( $changes['guideline_status'], self::GUIDELINE_STATUSES, true ) ) {
			$data['guideline_status'] = $changes['guideline_status'];
		} elseif ( $note_changed && 'integrated' === $item['guideline_status'] ) {
			// An integrated guideline whose text changes is a new guideline to integrate.
			$data['guideline_status'] = 'new';
		}

		// Only keep what actually changes.
		$data = array_filter(
			$data,
			static function ( $value, $column ) use ( $item ) {
				$current = isset( $item[ $column ] ) ? $item[ $column ] : null;
				if ( in_array( $column, array( 'correction', 'note' ), true ) ) {
					return (string) $value !== (string) $current;
				}
				return $value !== $current;
			},
			ARRAY_FILTER_USE_BOTH
		);
		// Values identical to the stored ones cannot overwrite anyone's work, even with a
		// stale version: the caller simply gets the current row (and its version) back.
		if ( ! $data ) {
			return $item;
		}

		$data['updated_by'] = (int) $user_id;
		$data['updated_at'] = self::now();
		if ( ! Repository::update_item( $item['id'], $data, $expected_version ) ) {
			return self::conflict();
		}
		Repository::update_review(
			$review['id'],
			array(
				'updated_by' => (int) $user_id,
				'updated_at' => $data['updated_at'],
			)
		);

		$updated = Repository::get_item( $item['id'] );

		/**
		 * Fires after a review row was changed.
		 *
		 * @param array $updated  Row after the change.
		 * @param array $previous Row before the change.
		 */
		do_action( 'relecture_item_updated', $updated, $item );

		return $updated;
	}

	/**
	 * Error returned when a row changed under the user's feet.
	 *
	 * @return \WP_Error
	 */
	private static function conflict() {
		return new \WP_Error(
			'relecture_conflict',
			__( 'This row was changed by someone else in the meantime. Reload the page to see the latest version.', 'relecture' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Changes the status of a review.
	 *
	 * @param array  $review  Review.
	 * @param string $status  New status.
	 * @param int    $user_id Author of the change.
	 * @return array|\WP_Error Updated review.
	 */
	public static function set_status( array $review, $status, $user_id ) {
		if ( ! in_array( $status, self::REVIEW_STATUSES, true ) ) {
			return new \WP_Error( 'relecture_invalid_status', __( 'Unknown review status.', 'relecture' ), array( 'status' => 400 ) );
		}
		// Reopening an old round would leave two open reviews on the same content.
		$latest = Repository::get_latest_review( $review['post_id'] );
		if ( 'done' !== $status && $latest && $latest['id'] !== $review['id'] ) {
			return new \WP_Error( 'relecture_not_latest', __( 'Only the latest review of a content can be reopened.', 'relecture' ), array( 'status' => 409 ) );
		}
		$previous = $review['status'];
		Repository::update_review(
			$review['id'],
			array(
				'status'     => $status,
				'updated_by' => (int) $user_id,
				'updated_at' => self::now(),
			)
		);

		/**
		 * Fires after the status of a review changed.
		 *
		 * @param int    $review_id Review ID.
		 * @param string $status    New status.
		 * @param string $previous  Previous status.
		 */
		do_action( 'relecture_review_status_changed', $review['id'], $status, $previous );

		return Repository::get_review( $review['id'] );
	}

	/**
	 * Deletes the reviews of a post that is being deleted for good.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_post_reviews( $post_id ) {
		if ( 'revision' === get_post_type( $post_id ) ) {
			return;
		}
		$ids = wp_list_pluck( Repository::get_post_reviews( (int) $post_id ), 'id' );
		Repository::delete_reviews( $ids );
	}
}
