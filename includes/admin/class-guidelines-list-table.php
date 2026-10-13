<?php
/**
 * List of the guidelines gathered across reviews.
 *
 * @package Relecture
 */

namespace Relecture\Admin;

use Relecture\Export\Export_Data;
use Relecture\Labels;
use Relecture\Permissions;
use Relecture\Plugin;
use Relecture\Repository;
use Relecture\Text;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters and paging of a list: read-only navigation parameters.

/**
 * Notes flagged “for future content”, newest first, with bulk actions to mark them as
 * integrated into the style guide (or as new again).
 */
final class Guidelines_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'relecture_guideline',
				'plural'   => 'relecture_guidelines',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Status shown: new by default (what is left to integrate).
	 *
	 * @return string 'new', 'integrated' or '' for all.
	 */
	public static function current_status() {
		$status = isset( $_GET['guideline_status'] ) ? sanitize_key( wp_unslash( $_GET['guideline_status'] ) ) : 'new';
		if ( 'all' === $status ) {
			return '';
		}
		return in_array( $status, array( 'new', 'integrated' ), true ) ? $status : 'new';
	}

	/**
	 * Query arguments shared by the list, the counts and the export.
	 *
	 * @param string $status Status.
	 * @return array
	 */
	private static function query_args( $status ) {
		return array(
			'status'     => $status,
			'post_types' => Permissions::post_types(),
			'author'     => Permissions::can_review_others() ? 0 : get_current_user_id(),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox" />',
			'note'    => __( 'Guideline', 'relecture' ),
			'context' => __( 'Written about', 'relecture' ),
			'source'  => __( 'Content', 'relecture' ),
			'status'  => __( 'Status', 'relecture' ),
			'updated' => __( 'Last change', 'relecture' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array(
			'integrate' => __( 'Mark as integrated', 'relecture' ),
			'renew'     => __( 'Mark as new', 'relecture' ),
		);
	}

	/**
	 * Views: new / integrated / all, with counts.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$current = self::current_status();
		$views   = array();
		$labels  = array(
			'new'        => _x( 'New', 'guidelines', 'relecture' ),
			'integrated' => _x( 'Integrated', 'guidelines', 'relecture' ),
			'all'        => _x( 'All', 'guidelines', 'relecture' ),
		);
		foreach ( $labels as $status => $label ) {
			$value            = 'all' === $status ? '' : $status;
			$count            = Repository::query_guidelines( self::query_args( $value ) + array( 'count' => true ) );
			$url              = add_query_arg(
				array(
					'page'             => 'relecture-guidelines',
					'guideline_status' => $status,
				),
				admin_url( 'admin.php' )
			);
			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$value === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
	}

	/**
	 * Queries the guidelines.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'relecture_per_page', 20 );
		$args     = self::query_args( self::current_status() );
		$total    = Repository::query_guidelines( $args + array( 'count' => true ) );
		$rows     = Repository::query_guidelines(
			$args + array(
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		// Second check, row by row: the author filter does not know about custom rules.
		$this->items = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return Permissions::can_review_post( $row['post_id'] );
				}
			)
		);
		$this->set_pagination_args(
			array(
				'total_items' => (int) $total,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'note' );
	}

	/**
	 * Message when nothing matches.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No guideline in this view. In a review, tick “Guideline for future content” under a note to collect it here.', 'relecture' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Guideline.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="relecture-guideline-%1$d">%2$s</label><input type="checkbox" id="relecture-guideline-%1$d" name="guideline[]" value="%1$d" />',
			(int) $item['id'],
			esc_html__( 'Select this guideline', 'relecture' )
		);
	}

	/**
	 * Guideline text.
	 *
	 * @param array $item Guideline.
	 * @return string
	 */
	protected function column_note( $item ) {
		return '<div class="relecture-guideline-text">' . esc_html( $item['note'] ) . '</div>';
	}

	/**
	 * Other columns.
	 *
	 * @param array  $item        Guideline.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'context':
				return '<span class="relecture-badge">' . esc_html( Labels::segment( $item ) ) . '</span> ' . esc_html( Text::excerpt( $item['original'], 140 ) );
			case 'source':
				return sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( Plugin::review_url( $item['post_id'], $item['review_id'] ) ),
					esc_html( Text::from_html( $item['post_title'] ) )
				);
			case 'status':
				return sprintf(
					'<span class="relecture-state relecture-state-%1$s">%2$s</span>',
					esc_attr( $item['guideline_status'] ),
					esc_html( Labels::of( Labels::guideline_statuses(), $item['guideline_status'] ) )
				);
			case 'updated':
				return esc_html( Export_Data::human_date( Export_Data::iso( $item['updated_at'] ) ) );
		}
		return '';
	}
}
