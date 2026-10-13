<?php
/**
 * List of the contents that can be reviewed.
 *
 * @package Relecture
 */

namespace Relecture\Admin;

use Relecture\Export\Export_Data;
use Relecture\Labels;
use Relecture\Permissions;
use Relecture\Plugin;
use Relecture\Repository;
use Relecture\Review_Service;
use Relecture\Text;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters, search and paging of a list: read-only navigation parameters.

/**
 * Contents with the state of their latest review.
 */
final class Posts_List_Table extends \WP_List_Table {

	/**
	 * Latest review of each listed post.
	 *
	 * @var array<int, array>
	 */
	private $reviews = array();

	/**
	 * Counters of those reviews.
	 *
	 * @var array<int, array>
	 */
	private $stats = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'relecture_content',
				'plural'   => 'relecture_contents',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'title'       => __( 'Title', 'relecture' ),
			'type'        => __( 'Type', 'relecture' ),
			'post_status' => __( 'Publication', 'relecture' ),
			'review'      => _x( 'Review', 'noun', 'relecture' ),
			'corrections' => __( 'Corrections', 'relecture' ),
			'notes'       => __( 'Notes', 'relecture' ),
			'updated'     => __( 'Last review change', 'relecture' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns() {
		return array( 'title' => array( 'title', false ) );
	}

	/**
	 * Queries the posts and their latest reviews.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'relecture_per_page', 20 );
		$types    = Permissions::post_types();
		$type     = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : '';
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order    = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		$filter   = isset( $_GET['review_filter'] ) ? sanitize_key( wp_unslash( $_GET['review_filter'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// Most recently modified first, unless sorted by title (A→Z by default).
		$by_title = 'title' === $orderby;
		$args     = array(
			'post_type'           => in_array( $type, $types, true ) ? $type : $types,
			'post_status'         => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page'      => $per_page,
			'paged'               => $this->get_pagenum(),
			'orderby'             => $by_title ? 'title' : 'modified',
			'order'               => $by_title && 'desc' !== $order ? 'ASC' : 'DESC',
			'ignore_sticky_posts' => true,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( ! Permissions::can_review_others() ) {
			$args['author'] = get_current_user_id();
		}
		if ( 'none' === $filter ) {
			$reviewed = Repository::post_ids_by_latest_status( null );
			if ( $reviewed ) {
				// The reviewed posts of a site are a short list: excluding them is cheap here.
				$args['post__not_in'] = $reviewed; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			}
		} elseif ( in_array( $filter, Review_Service::REVIEW_STATUSES, true ) ) {
			$ids              = Repository::post_ids_by_latest_status( $filter );
			$args['post__in'] = $ids ? $ids : array( 0 );
		}

		$query         = new \WP_Query( $args );
		$this->items   = $query->posts;
		$this->reviews = Repository::get_latest_reviews( wp_list_pluck( $this->items, 'ID' ) );
		$this->stats   = Repository::stats( wp_list_pluck( $this->reviews, 'id' ) );

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * Message when nothing matches.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No content found.', 'relecture' );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$type   = isset( $_GET['post_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['post_type_filter'] ) ) : '';
		$filter = isset( $_GET['review_filter'] ) ? sanitize_key( wp_unslash( $_GET['review_filter'] ) ) : '';
		echo '<div class="alignleft actions">';

		echo '<label for="relecture-type-filter" class="screen-reader-text">' . esc_html__( 'Filter by type', 'relecture' ) . '</label>';
		echo '<select name="post_type_filter" id="relecture-type-filter"><option value="">' . esc_html__( 'All types', 'relecture' ) . '</option>';
		foreach ( Permissions::post_types() as $name ) {
			$object = get_post_type_object( $name );
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $name ), selected( $type, $name, false ), esc_html( $object ? $object->labels->name : $name ) );
		}
		echo '</select>';

		echo '<label for="relecture-review-filter" class="screen-reader-text">' . esc_html__( 'Filter by review state', 'relecture' ) . '</label>';
		echo '<select name="review_filter" id="relecture-review-filter"><option value="">' . esc_html__( 'All review states', 'relecture' ) . '</option>';
		printf( '<option value="none"%1$s>%2$s</option>', selected( $filter, 'none', false ), esc_html__( 'Not reviewed yet', 'relecture' ) );
		foreach ( Labels::review_statuses() as $status => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $status ), selected( $filter, $status, false ), esc_html( $label ) );
		}
		echo '</select>';

		submit_button( __( 'Filter', 'relecture' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Title column, with the row actions.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_title( $post ) {
		$title = Text::from_html( $post->post_title );
		if ( '' === $title ) {
			$title = __( '(no title)', 'relecture' );
		}
		$actions = array(
			'relecture' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( Plugin::start_url( $post->ID ) ), esc_html_x( 'Review', 'verb', 'relecture' ) ),
		);
		$link    = get_permalink( $post );
		if ( $link ) {
			$actions['view'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html__( 'View', 'relecture' ) );
		}
		$edit = get_edit_post_link( $post->ID );
		if ( $edit ) {
			$actions['edit'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $edit ), esc_html__( 'Edit', 'relecture' ) );
		}
		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>%3$s',
			esc_url( Plugin::start_url( $post->ID ) ),
			esc_html( $title ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Other columns.
	 *
	 * @param \WP_Post $post        Post.
	 * @param string   $column_name Column.
	 * @return string
	 */
	protected function column_default( $post, $column_name ) {
		$review = isset( $this->reviews[ $post->ID ] ) ? $this->reviews[ $post->ID ] : null;
		$stats  = $review && isset( $this->stats[ $review['id'] ] ) ? $this->stats[ $review['id'] ] : null;

		switch ( $column_name ) {
			case 'type':
				$object = get_post_type_object( $post->post_type );
				return esc_html( $object ? $object->labels->singular_name : $post->post_type );
			case 'post_status':
				$status = get_post_status_object( $post->post_status );
				return esc_html( $status ? $status->label : $post->post_status );
			case 'review':
				if ( ! $review ) {
					return '<span class="relecture-state relecture-state-none">' . esc_html__( 'Not reviewed yet', 'relecture' ) . '</span>';
				}
				return sprintf(
					'<span class="relecture-state relecture-state-%1$s">%2$s</span>',
					esc_attr( $review['status'] ),
					esc_html( Labels::of( Labels::review_statuses(), $review['status'] ) )
				);
			case 'corrections':
				return $stats ? esc_html( number_format_i18n( $stats['corrections'] ) ) : '—';
			case 'notes':
				return $stats ? esc_html( number_format_i18n( $stats['notes'] ) ) : '—';
			case 'updated':
				if ( ! $review ) {
					return '—';
				}
				$name = Export_Data::user_name( $review['updated_by'] );
				$when = sprintf(
					/* translators: %s: human time difference, e.g. “3 hours”. */
					__( '%s ago', 'relecture' ),
					human_time_diff( (int) strtotime( $review['updated_at'] . ' UTC' ) )
				);
				/* translators: 1: date or time, 2: user name. */
				return esc_html( '' !== $name ? sprintf( __( '%1$s by %2$s', 'relecture' ), $when, $name ) : $when );
		}
		return '';
	}
}
