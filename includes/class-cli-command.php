<?php
/**
 * WP-CLI commands.
 *
 * @package Relecture
 */

namespace Relecture;

use Relecture\Export\Export_Data;
use Relecture\Export\Exporter;
use Relecture\Export\Guidelines_Exporter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Command line: writing to STDOUT or to the file the operator named.

/**
 * Reads and updates reviews from the command line.
 *
 * Meant for scripts and AI agents working next to the site: export a review, apply the
 * corrections in the source, then mark them as applied.
 *
 * ## EXAMPLES
 *
 *     # Review of post 42 as Markdown, only the rows with feedback.
 *     $ wp relecture export 42 --format=md --only-with-feedback
 *
 *     # Mark rows 118 and 119 as applied, then close the review.
 *     $ wp relecture set-item-status 118 119 --correction=applied
 *     $ wp relecture set-status 7 done
 */
class CLI_Command extends \WP_CLI_Command {

	/**
	 * Lists reviews.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only reviews with this status.
	 * ---
	 * options:
	 *   - in_progress
	 *   - ready
	 *   - done
	 * ---
	 *
	 * [--post=<id>]
	 * : Only the reviews of this post.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$reviews = Repository::query_reviews(
			array(
				'status'   => isset( $assoc_args['status'] ) ? $assoc_args['status'] : '',
				'post_id'  => isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0,
				'per_page' => 100,
			)
		);
		$stats   = Repository::stats( wp_list_pluck( $reviews, 'id' ) );
		$rows    = array();
		foreach ( $reviews as $review ) {
			$post   = get_post( $review['post_id'] );
			$rows[] = array(
				'review'      => $review['id'],
				'post'        => $review['post_id'],
				'title'       => $post ? Text::from_html( $post->post_title ) : '',
				'status'      => $review['status'],
				'corrections' => $stats[ $review['id'] ]['corrections'],
				'notes'       => $stats[ $review['id'] ]['notes'],
				'guidelines'  => $stats[ $review['id'] ]['guidelines'],
				'updated'     => $review['updated_at'],
				'outdated'    => $post && Review_Service::is_outdated( $review, $post ) ? 'yes' : 'no',
			);
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'review', 'post', 'title', 'status', 'corrections', 'notes', 'guidelines', 'updated', 'outdated' ) );
	}

	/**
	 * Starts the review of a post (or returns the open one).
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * [--new-round]
	 * : Start a new round if the latest review is closed.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function start( $args, $assoc_args ) {
		$post   = $this->post( $args[0] );
		$review = Review_Service::start( $post, get_current_user_id(), ! empty( $assoc_args['new-round'] ) );
		\WP_CLI::success( sprintf( 'Review %d of post %d (%s).', $review['id'], $post->ID, $review['status'] ) );
	}

	/**
	 * Exports the review of a post.
	 *
	 * Text formats go to STDOUT unless --output is given; Excel and Word need --output.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * [--review=<id>]
	 * : A specific round (the latest one by default).
	 *
	 * [--format=<format>]
	 * : Export format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - md
	 *   - csv
	 *   - xlsx
	 *   - docx
	 * ---
	 *
	 * [--only-with-feedback]
	 * : Keep only the rows with a correction or a note.
	 *
	 * [--output=<file>]
	 * : Write to this file.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$post   = $this->post( $args[0] );
		$review = $this->review_of( $post, isset( $assoc_args['review'] ) ? (int) $assoc_args['review'] : 0 );
		$format = $assoc_args['format'];
		$data   = Export_Data::review( $review, $post, array( 'only_with_feedback' => ! empty( $assoc_args['only-with-feedback'] ) ) );
		$this->output( Exporter::render( $data, $format ), $format, isset( $assoc_args['output'] ) ? $assoc_args['output'] : '' );
	}

	/**
	 * Exports the guidelines gathered across reviews.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Guidelines to export.
	 * ---
	 * default: new
	 * options:
	 *   - new
	 *   - integrated
	 *   - all
	 * ---
	 *
	 * [--format=<format>]
	 * : Export format.
	 * ---
	 * default: md
	 * options:
	 *   - md
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--output=<file>]
	 * : Write to this file.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function guidelines( $args, $assoc_args ) {
		$status = 'all' === $assoc_args['status'] ? '' : $assoc_args['status'];
		$rows   = Repository::query_guidelines(
			array(
				'status'     => $status,
				'post_types' => Permissions::post_types(),
			)
		);
		$data   = Guidelines_Exporter::data( $rows, $status );
		$this->output( Guidelines_Exporter::render( $data, $assoc_args['format'] ), $assoc_args['format'], isset( $assoc_args['output'] ) ? $assoc_args['output'] : '' );
	}

	/**
	 * Changes the status of a review.
	 *
	 * ## OPTIONS
	 *
	 * <review>
	 * : Review ID.
	 *
	 * <status>
	 * : New status.
	 * ---
	 * options:
	 *   - in_progress
	 *   - ready
	 *   - done
	 * ---
	 *
	 * @subcommand set-status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function set_status( $args, $assoc_args ) {
		$review = Repository::get_review( (int) $args[0] );
		if ( ! $review ) {
			\WP_CLI::error( sprintf( 'Review %d not found.', (int) $args[0] ) );
		}
		$result = Review_Service::set_status( $review, $args[1], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Review %d is now %s.', $review['id'], $args[1] ) );
	}

	/**
	 * Changes the status of rows: their correction, or their guideline.
	 *
	 * ## OPTIONS
	 *
	 * <item>...
	 * : Row IDs (the “id” of each row in the JSON export).
	 *
	 * [--correction=<status>]
	 * : New status of the correction.
	 * ---
	 * options:
	 *   - pending
	 *   - accepted
	 *   - rejected
	 *   - applied
	 * ---
	 *
	 * [--guideline=<status>]
	 * : New status of the guideline.
	 * ---
	 * options:
	 *   - new
	 *   - integrated
	 * ---
	 *
	 * @subcommand set-item-status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function set_item_status( $args, $assoc_args ) {
		$changes = array();
		if ( isset( $assoc_args['correction'] ) ) {
			$changes['correction_status'] = $assoc_args['correction'];
		}
		if ( isset( $assoc_args['guideline'] ) ) {
			$changes['guideline_status'] = $assoc_args['guideline'];
		}
		if ( ! $changes ) {
			\WP_CLI::error( 'Pass --correction=<status> and/or --guideline=<status>.' );
		}
		$done = 0;
		foreach ( $args as $id ) {
			$item = Repository::get_item( (int) $id );
			if ( ! $item ) {
				\WP_CLI::warning( sprintf( 'Row %d not found.', (int) $id ) );
				continue;
			}
			if ( isset( $changes['correction_status'] ) && ! Text::filled( $item['correction'] ) ) {
				\WP_CLI::warning( sprintf( 'Row %d has no correction: its correction status is left empty.', $item['id'] ) );
			}
			$result = Review_Service::update_item( $item, $changes, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'Row %d: %s', $item['id'], $result->get_error_message() ) );
				continue;
			}
			++$done;
		}
		\WP_CLI::success( sprintf( '%d row(s) updated.', $done ) );
	}

	/**
	 * A reviewable post, or an error.
	 *
	 * @param mixed $id Post ID.
	 * @return \WP_Post
	 */
	private function post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, Permissions::post_types(), true ) ) {
			\WP_CLI::error( sprintf( 'Post %d not found or not reviewable.', (int) $id ) );
		}
		return $post;
	}

	/**
	 * A review of a post: the given round, or the latest.
	 *
	 * @param \WP_Post $post      Post.
	 * @param int      $review_id Review ID (0 for the latest).
	 * @return array
	 */
	private function review_of( \WP_Post $post, $review_id ) {
		$review = $review_id ? Repository::get_review( $review_id ) : Repository::get_latest_review( $post->ID );
		if ( ! $review || (int) $review['post_id'] !== (int) $post->ID ) {
			\WP_CLI::error( sprintf( 'No review for post %d. Start one with: wp relecture start %d', $post->ID, $post->ID ) );
		}
		return $review;
	}

	/**
	 * Writes a rendered export to a file or STDOUT.
	 *
	 * @param array|\WP_Error $result Rendered export.
	 * @param string          $format Format.
	 * @param string          $output Target file ('' for STDOUT).
	 * @return void
	 */
	private function output( $result, $format, $output ) {
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( '' === $output ) {
			if ( isset( $result['path'] ) ) {
				wp_delete_file( $result['path'] );
				\WP_CLI::error( sprintf( 'The %s format is binary: pass --output=<file>.', $format ) );
			}
			fwrite( STDOUT, $result['contents'] );
			return;
		}
		if ( isset( $result['path'] ) ) {
			$ok = copy( $result['path'], $output );
			wp_delete_file( $result['path'] );
		} else {
			$ok = false !== file_put_contents( $output, $result['contents'] );
		}
		if ( ! $ok ) {
			\WP_CLI::error( sprintf( 'Could not write %s.', $output ) );
		}
		\WP_CLI::success( sprintf( 'Written to %s.', $output ) );
	}
}
