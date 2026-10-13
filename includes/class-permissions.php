<?php
/**
 * Who may review what.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Capability checks. Every entry point (screens, admin-post, REST, exports) goes through
 * can_review_post(): reviewing a content requires being allowed to edit it.
 */
final class Permissions {

	/**
	 * Capability required to see the plugin's screens at all.
	 */
	const MENU_CAPABILITY = 'edit_posts';

	/**
	 * Post types that can be reviewed: public types with an editor, plus synced patterns.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types   = get_post_types( array( 'public' => true ), 'names' );
		$types[] = 'wp_block';
		$types   = array_filter(
			array_unique( $types ),
			static function ( $type ) {
				return 'attachment' !== $type && post_type_exists( $type ) && post_type_supports( $type, 'editor' );
			}
		);

		/**
		 * Filters the post types that can be reviewed.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( (array) apply_filters( 'relecture_post_types', array_values( $types ) ) );
	}

	/**
	 * Whether the current user may review (read, annotate, export) a post.
	 *
	 * @param int|\WP_Post $post Post or post ID.
	 * @return bool
	 */
	public static function can_review_post( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return false;
		}

		$allowed = current_user_can( 'edit_post', $post->ID );

		/**
		 * Filters whether the current user may review a post.
		 *
		 * @param bool     $allowed Default: whether the user can edit the post.
		 * @param \WP_Post $post    Post.
		 * @param int      $user_id Current user ID.
		 */
		return (bool) apply_filters( 'relecture_can_review', $allowed, $post, get_current_user_id() );
	}

	/**
	 * Whether the current user may open the plugin's screens.
	 *
	 * @return bool
	 */
	public static function can_use_screens() {
		return current_user_can( self::MENU_CAPABILITY );
	}

	/**
	 * Whether the current user may edit others' content for every reviewable type.
	 *
	 * When false, lists are restricted to the user's own content.
	 *
	 * @return bool
	 */
	public static function can_review_others() {
		foreach ( self::post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object && ! current_user_can( $object->cap->edit_others_posts ) ) {
				return false;
			}
		}
		return true;
	}
}
