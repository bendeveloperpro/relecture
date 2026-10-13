<?php
/**
 * REST API: relecture/v1.
 *
 * @package Relecture
 */

namespace Relecture;

use Relecture\Export\Export_Data;
use Relecture\Export\Guidelines_Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Routes used by the review screen (autosave) and by external tools or AIs
 * (application passwords).
 *
 * Every permission callback resolves the object down to its post and checks
 * Permissions::can_review_post(): an ID guessed in a URL gives access to nothing.
 */
final class Rest_Controller {

	/**
	 * Namespace of the routes.
	 */
	const NAMESPACE_V1 = 'relecture/v1';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/reviews',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_reviews' ),
					'permission_callback' => array( __CLASS__, 'can_use' ),
					'args'                => array(
						'post_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'status'   => array(
							'type' => 'string',
							'enum' => Review_Service::REVIEW_STATUSES,
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'start_review' ),
					'permission_callback' => array( __CLASS__, 'can_start' ),
					'args'                => array(
						'post_id'   => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						),
						'new_round' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/reviews/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_review' ),
					'permission_callback' => array( __CLASS__, 'can_access_review' ),
					'args'                => array(
						'only_with_feedback' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_review' ),
					'permission_callback' => array( __CLASS__, 'can_access_review' ),
					'args'                => array(
						'status' => array(
							'type'     => 'string',
							'enum'     => Review_Service::REVIEW_STATUSES,
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/reviews/(?P<id>\d+)/resync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resync_review' ),
				'permission_callback' => array( __CLASS__, 'can_access_review' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/items/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_item' ),
					'permission_callback' => array( __CLASS__, 'can_access_item' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_item' ),
					'permission_callback' => array( __CLASS__, 'can_access_item' ),
					'args'                => array(
						'correction'        => array(
							'type'      => 'string',
							'maxLength' => Text::MAX_LENGTH,
						),
						'note'              => array(
							'type'      => 'string',
							'maxLength' => Text::MAX_LENGTH,
						),
						'correction_status' => array(
							'type' => 'string',
							'enum' => Review_Service::CORRECTION_STATUSES,
						),
						'note_type'         => array(
							'type' => 'string',
							'enum' => Review_Service::NOTE_TYPES,
						),
						'guideline_status'  => array(
							'type' => 'string',
							'enum' => Review_Service::GUIDELINE_STATUSES,
						),
						'expected_version'  => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/guidelines',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_guidelines' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => array_merge( Review_Service::GUIDELINE_STATUSES, array( 'all' ) ),
						'default' => 'new',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 50,
					),
				),
			)
		);
	}

	/* ------------------------------------------------------------- Permissions */

	/**
	 * Error for a refused request: 401 when logged out, 403 otherwise.
	 *
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'relecture' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * May the user use the plugin at all?
	 *
	 * @return true|\WP_Error
	 */
	public static function can_use() {
		return Permissions::can_use_screens() ? true : self::forbidden();
	}

	/**
	 * May the user start a review of this post?
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function can_start( $request ) {
		return Permissions::can_review_post( (int) $request['post_id'] ) ? true : self::forbidden();
	}

	/**
	 * May the user access this review?
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function can_access_review( $request ) {
		$review = Repository::get_review( (int) $request['id'] );
		return $review && Permissions::can_review_post( $review['post_id'] ) ? true : self::forbidden();
	}

	/**
	 * May the user access this item?
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function can_access_item( $request ) {
		$item   = Repository::get_item( (int) $request['id'] );
		$review = $item ? Repository::get_review( $item['review_id'] ) : null;
		return $review && Permissions::can_review_post( $review['post_id'] ) ? true : self::forbidden();
	}

	/* --------------------------------------------------------------- Callbacks */

	/**
	 * GET /reviews.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_reviews( $request ) {
		$reviews = Repository::query_reviews(
			array(
				'post_id'  => (int) $request['post_id'],
				'status'   => (string) $request['status'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);
		$stats   = Repository::stats( wp_list_pluck( $reviews, 'id' ) );
		$data    = array();
		foreach ( $reviews as $review ) {
			$post = get_post( $review['post_id'] );
			// Reviews of content the user may not edit are silently left out.
			if ( ! $post || ! Permissions::can_review_post( $post ) ) {
				continue;
			}
			$data[] = array(
				'post'   => Export_Data::post( $post ),
				'review' => Export_Data::review_meta( $review, $post ),
				'stats'  => $stats[ $review['id'] ],
			);
		}
		return rest_ensure_response( $data );
	}

	/**
	 * POST /reviews: opens the review of a post (starting it if needed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function start_review( $request ) {
		$post   = get_post( (int) $request['post_id'] );
		$before = Repository::get_latest_review( $post->ID );
		$review = Review_Service::start( $post, get_current_user_id(), (bool) $request['new_round'] );
		$data   = Export_Data::review( $review, $post );
		$status = ( ! $before || $before['id'] !== $review['id'] ) ? 201 : 200;
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * GET /reviews/{id}: the review and its rows (same structure as the JSON export).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_review( $request ) {
		$review = Repository::get_review( (int) $request['id'] );
		$post   = get_post( $review['post_id'] );
		return rest_ensure_response( Export_Data::review( $review, $post, array( 'only_with_feedback' => (bool) $request['only_with_feedback'] ) ) );
	}

	/**
	 * PATCH /reviews/{id}: changes the status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_review( $request ) {
		$review  = Repository::get_review( (int) $request['id'] );
		$updated = Review_Service::set_status( $review, (string) $request['status'], get_current_user_id() );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return rest_ensure_response( Export_Data::review_meta( $updated, get_post( $updated['post_id'] ) ) );
	}

	/**
	 * POST /reviews/{id}/resync: new snapshot of the text, feedback kept.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resync_review( $request ) {
		$review = Repository::get_review( (int) $request['id'] );
		if ( 'done' === $review['status'] ) {
			return new \WP_Error( 'relecture_review_closed', __( 'This review is closed. Reopen it to edit it.', 'relecture' ), array( 'status' => 409 ) );
		}
		$post   = get_post( $review['post_id'] );
		$counts = Review_Service::resync( $review, $post, get_current_user_id() );
		return rest_ensure_response(
			array(
				'counts' => $counts,
				'review' => Export_Data::review_meta( Repository::get_review( $review['id'] ), $post ),
			)
		);
	}

	/**
	 * GET /items/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_item( $request ) {
		return rest_ensure_response( Export_Data::item( Repository::get_item( (int) $request['id'] ) ) );
	}

	/**
	 * PATCH /items/{id}: saves a correction, a note, a type or a status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_item( $request ) {
		$item    = Repository::get_item( (int) $request['id'] );
		$changes = array();
		foreach ( array( 'correction', 'note', 'correction_status', 'note_type', 'guideline_status' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$changes[ $field ] = $request->get_param( $field );
			}
		}
		$expected = $request->has_param( 'expected_version' ) ? (int) $request['expected_version'] : null;
		$updated  = Review_Service::update_item( $item, $changes, get_current_user_id(), $expected );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return rest_ensure_response( Export_Data::item( $updated, 0, false ) );
	}

	/**
	 * GET /guidelines.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_guidelines( $request ) {
		$status = 'all' === $request['status'] ? '' : (string) $request['status'];
		$rows   = Repository::query_guidelines(
			array(
				'status'     => $status,
				'post_types' => Permissions::post_types(),
				'author'     => Permissions::can_review_others() ? 0 : get_current_user_id(),
				'page'       => (int) $request['page'],
				'per_page'   => (int) $request['per_page'],
			)
		);
		// Second check, row by row: the author filter above does not know about custom rules.
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return Permissions::can_review_post( $row['post_id'] );
				}
			)
		);
		$data = Guidelines_Exporter::data( $rows, $status );
		return rest_ensure_response( $data['guidelines'] );
	}
}
