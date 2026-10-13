<?php
/**
 * Admin screens and form handlers.
 *
 * @package Relecture
 */

namespace Relecture\Admin;

use Relecture\Export\Export_Data;
use Relecture\Export\Exporter;
use Relecture\Export\Guidelines_Exporter;
use Relecture\Permissions;
use Relecture\Plugin;
use Relecture\Repository;
use Relecture\Review_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Menu, screens, assets, row actions and the admin-post handlers (start, status, sync,
 * exports). Every handler checks a nonce and the user's right on the reviewed content.
 */
final class Admin {

	/**
	 * Hook suffixes of the plugin's pages.
	 *
	 * @var array<string, string>
	 */
	private static $hooks = array();

	/**
	 * Data of the review screen, prepared before any output.
	 *
	 * @var array|null
	 */
	private static $screen = null;

	/**
	 * List table of the current screen.
	 *
	 * @var \WP_List_Table|null
	 */
	private static $table = null;

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		require_once RELECTURE_DIR . 'includes/admin/class-posts-list-table.php';
		require_once RELECTURE_DIR . 'includes/admin/class-guidelines-list-table.php';

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_relecture_start', array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_relecture_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_relecture_resync', array( __CLASS__, 'handle_resync' ) );
		add_action( 'admin_post_relecture_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_relecture_export_guidelines', array( __CLASS__, 'handle_export_guidelines' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'set_screen_option_relecture_per_page', array( __CLASS__, 'save_per_page' ), 10, 3 );
	}

	/**
	 * Menu and pages.
	 *
	 * @return void
	 */
	public static function menu() {
		self::$hooks['main'] = (string) add_menu_page(
			__( 'Contents to review', 'relecture' ),
			'Relecture',
			Permissions::MENU_CAPABILITY,
			'relecture',
			array( __CLASS__, 'render_main' ),
			'dashicons-editor-spellcheck',
			26
		);
		add_submenu_page( 'relecture', __( 'Contents to review', 'relecture' ), __( 'Contents', 'relecture' ), Permissions::MENU_CAPABILITY, 'relecture', array( __CLASS__, 'render_main' ) );
		self::$hooks['guidelines'] = (string) add_submenu_page(
			'relecture',
			__( 'Guidelines for future content', 'relecture' ),
			__( 'Guidelines', 'relecture' ),
			Permissions::MENU_CAPABILITY,
			'relecture-guidelines',
			array( __CLASS__, 'render_guidelines' )
		);

		add_action( 'load-' . self::$hooks['main'], array( __CLASS__, 'load_main' ) );
		add_action( 'load-' . self::$hooks['guidelines'], array( __CLASS__, 'load_guidelines' ) );
	}

	/**
	 * Integer from the query string (navigation parameter, no side effect).
	 *
	 * @param string $key Parameter.
	 * @return int
	 */
	private static function query_int( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter.
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
	}

	/**
	 * Key from the query string (navigation parameter, no side effect).
	 *
	 * @param string $key Parameter.
	 * @return string
	 */
	private static function query_key( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter.
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Before the main page is output: prepares the review screen or the list.
	 *
	 * @return void
	 */
	public static function load_main() {
		$post_id = self::query_int( 'post' );
		if ( ! $post_id ) {
			add_screen_option(
				'per_page',
				array(
					'default' => 20,
					'option'  => 'relecture_per_page',
				)
			);
			self::$table = new Posts_List_Table();
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! Permissions::can_review_post( $post ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to review this content.', 'relecture' ), '', array( 'response' => 403 ) );
		}

		$rounds    = Repository::get_post_reviews( $post->ID );
		$review_id = self::query_int( 'review' );
		$review    = null;
		foreach ( $rounds as $round ) {
			if ( ! $review_id || $round['id'] === $review_id ) {
				$review = $round;
				break;
			}
		}

		self::$screen = array(
			'post'   => $post,
			'review' => $review,
			'rounds' => $rounds,
		);
		if ( $review ) {
			$stats                    = Repository::stats( array( $review['id'] ) );
			self::$screen['items']    = Export_Data::numbered( Repository::get_items( $review['id'] ) );
			self::$screen['stats']    = $stats[ $review['id'] ];
			self::$screen['outdated'] = Review_Service::is_outdated( $review, $post );
		}
	}

	/**
	 * Before the guidelines page is output: bulk actions, then the list.
	 *
	 * @return void
	 */
	public static function load_guidelines() {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'relecture_per_page',
			)
		);
		self::$table = new Guidelines_List_Table();

		$action = self::$table->current_action();
		if ( ! in_array( $action, array( 'integrate', 'renew' ), true ) ) {
			return;
		}
		check_admin_referer( 'bulk-relecture_guidelines' );

		$ids     = isset( $_REQUEST['guideline'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['guideline'] ) ) : array();
		$status  = 'integrate' === $action ? 'integrated' : 'new';
		$changed = 0;
		foreach ( $ids as $id ) {
			$item   = Repository::get_item( $id );
			$review = $item ? Repository::get_review( $item['review_id'] ) : null;
			if ( ! $review || ! Permissions::can_review_post( $review['post_id'] ) ) {
				continue;
			}
			if ( ! is_wp_error( Review_Service::update_item( $item, array( 'guideline_status' => $status ), get_current_user_id() ) ) ) {
				++$changed;
			}
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'relecture-guidelines',
					'guideline_status' => self::query_key( 'guideline_status' ),
					'relecture_done'   => $changed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Saves the “rows per page” screen option.
	 *
	 * @param mixed  $screen_option Value to save (false by default).
	 * @param string $option        Option name.
	 * @param int    $value         Submitted value.
	 * @return int|mixed
	 */
	public static function save_per_page( $screen_option, $option, $value ) {
		return 'relecture_per_page' === $option ? max( 1, min( 200, (int) $value ) ) : $screen_option;
	}

	/**
	 * Styles and scripts of the plugin's pages.
	 *
	 * @param string $hook Current page hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'relecture-admin', RELECTURE_URL . 'assets/css/review.css', array(), RELECTURE_VERSION );

		if ( empty( self::$screen['review'] ) ) {
			return;
		}
		wp_enqueue_script( 'relecture-review', RELECTURE_URL . 'assets/js/review.js', array( 'wp-api-fetch', 'wp-i18n' ), RELECTURE_VERSION, array( 'in_footer' => true ) );
		wp_set_script_translations( 'relecture-review', 'relecture', RELECTURE_DIR . 'languages' );
		wp_add_inline_script(
			'relecture-review',
			'window.relectureReview = ' . wp_json_encode(
				array(
					'reviewId' => (int) self::$screen['review']['id'],
					'readOnly' => 'done' === self::$screen['review']['status'],
					'itemPath' => '/relecture/v1/items/',
				)
			) . ';',
			'before'
		);
	}

	/**
	 * “Review” link in the Posts and Pages lists.
	 *
	 * @param string[] $actions Row actions.
	 * @param \WP_Post $post    Post of the row.
	 * @return string[]
	 */
	public static function row_action( $actions, $post ) {
		if ( $post instanceof \WP_Post && Permissions::can_review_post( $post ) ) {
			$actions['relecture'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( Plugin::start_url( $post->ID ) ),
				/* translators: %s: post title. */
				esc_attr( sprintf( __( 'Review “%s”', 'relecture' ), wp_strip_all_tags( get_the_title( $post ) ) ) ),
				esc_html_x( 'Review', 'verb', 'relecture' )
			);
		}
		return $actions;
	}

	/* ----------------------------------------------------------------- Screens */

	/**
	 * Main page: the review screen of a post, or the list of contents.
	 *
	 * @return void
	 */
	public static function render_main() {
		if ( self::$screen ) {
			$screen = self::$screen;
			include RELECTURE_DIR . 'includes/admin/views/review.php';
			return;
		}
		$table = self::$table;
		$table->prepare_items();
		include RELECTURE_DIR . 'includes/admin/views/list.php';
	}

	/**
	 * Guidelines page.
	 *
	 * @return void
	 */
	public static function render_guidelines() {
		$table = self::$table;
		$table->prepare_items();
		$done = self::query_int( 'relecture_done' );
		include RELECTURE_DIR . 'includes/admin/views/guidelines.php';
	}

	/**
	 * Message to show after a redirect.
	 *
	 * @return array|null array( type, text ).
	 */
	public static function notice() {
		$code = self::query_key( 'relecture_notice' );
		switch ( $code ) {
			case 'status':
				return array( 'success', __( 'Review status updated.', 'relecture' ) );
			case 'resynced':
				return array(
					'success',
					sprintf(
						/* translators: 1: rows kept, 2: new rows, 3: rows set aside. */
						__( 'Original text updated. Rows kept: %1$d; new: %2$d; set aside because their text is gone: %3$d.', 'relecture' ),
						self::query_int( 'kept' ),
						self::query_int( 'added' ),
						self::query_int( 'orphaned' )
					),
				);
			case 'error':
				return array( 'error', __( 'The action could not be completed.', 'relecture' ) );
		}
		return null;
	}

	/* ---------------------------------------------------------------- Handlers */

	/**
	 * Review ID posted to a handler, after nonce and permission checks.
	 *
	 * @param string $nonce_prefix Nonce action prefix (the review ID is appended).
	 * @return array array( review, post ).
	 */
	private static function posted_review( $nonce_prefix ) {
		$review_id = isset( $_REQUEST['review'] ) ? absint( wp_unslash( $_REQUEST['review'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( $nonce_prefix . $review_id );
		$review = Repository::get_review( $review_id );
		$post   = $review ? get_post( $review['post_id'] ) : null;
		if ( ! $post || ! Permissions::can_review_post( $post ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to review this content.', 'relecture' ), '', array( 'response' => 403 ) );
		}
		return array( $review, $post );
	}

	/**
	 * Opens (or starts) the review of a post.
	 *
	 * @return void
	 */
	public static function handle_start() {
		$post_id = isset( $_REQUEST['post'] ) ? absint( wp_unslash( $_REQUEST['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		check_admin_referer( 'relecture_start_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post || ! Permissions::can_review_post( $post ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to review this content.', 'relecture' ), '', array( 'response' => 403 ) );
		}
		$review = Review_Service::start( $post, get_current_user_id(), ! empty( $_REQUEST['new_round'] ) );
		wp_safe_redirect( Plugin::review_url( $post->ID, $review['id'] ) );
		exit;
	}

	/**
	 * Changes the status of a review.
	 *
	 * @return void
	 */
	public static function handle_status() {
		list( $review, $post ) = self::posted_review( 'relecture_status_' );
		$status                = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by posted_review().
		$result                = Review_Service::set_status( $review, $status, get_current_user_id() );
		self::back( $post->ID, $review['id'], is_wp_error( $result ) ? 'error' : 'status' );
	}

	/**
	 * Takes a new snapshot of the original text.
	 *
	 * @return void
	 */
	public static function handle_resync() {
		list( $review, $post ) = self::posted_review( 'relecture_resync_' );
		if ( 'done' === $review['status'] ) {
			self::back( $post->ID, $review['id'], 'error' );
		}
		$counts = Review_Service::resync( $review, $post, get_current_user_id() );
		self::back( $post->ID, $review['id'], 'resynced', $counts );
	}

	/**
	 * Downloads a review export.
	 *
	 * @return void
	 */
	public static function handle_export() {
		list( $review, $post ) = self::posted_review( 'relecture_export_' );
		$format                = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by posted_review().
		if ( ! in_array( $format, Exporter::FORMATS, true ) ) {
			wp_die( esc_html__( 'Unknown export format.', 'relecture' ), '', array( 'response' => 400 ) );
		}
		$data = Export_Data::review( $review, $post, array( 'only_with_feedback' => ! empty( $_REQUEST['only_feedback'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by posted_review().
		Exporter::send( $data, $format );
	}

	/**
	 * Downloads the guidelines.
	 *
	 * @return void
	 */
	public static function handle_export_guidelines() {
		check_admin_referer( 'relecture_export_guidelines' );
		if ( ! Permissions::can_use_screens() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'relecture' ), '', array( 'response' => 403 ) );
		}
		$format = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : '';
		$status = isset( $_REQUEST['guideline_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['guideline_status'] ) ) : '';
		if ( ! in_array( $format, Guidelines_Exporter::FORMATS, true ) ) {
			wp_die( esc_html__( 'Unknown export format.', 'relecture' ), '', array( 'response' => 400 ) );
		}
		$status = in_array( $status, Review_Service::GUIDELINE_STATUSES, true ) ? $status : '';
		$rows   = array_filter(
			Repository::query_guidelines(
				array(
					'status'     => $status,
					'post_types' => Permissions::post_types(),
					'author'     => Permissions::can_review_others() ? 0 : get_current_user_id(),
				)
			),
			static function ( $row ) {
				return Permissions::can_review_post( $row['post_id'] );
			}
		);
		Guidelines_Exporter::send( Guidelines_Exporter::data( array_values( $rows ), $status ), $format );
	}

	/**
	 * Redirects back to a review screen with a message.
	 *
	 * @param int    $post_id   Post ID.
	 * @param int    $review_id Review ID.
	 * @param string $notice    Message code.
	 * @param array  $args      Extra query arguments.
	 * @return void
	 */
	private static function back( $post_id, $review_id, $notice, array $args = array() ) {
		$args['relecture_notice'] = $notice;
		wp_safe_redirect( add_query_arg( $args, Plugin::review_url( $post_id, $review_id ) ) );
		exit;
	}
}
