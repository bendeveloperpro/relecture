<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap: hooks shared by every context (admin, front end, REST, WP-CLI).
 */
final class Plugin {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( Rest_Controller::class, 'register_routes' ) );
		add_action( 'before_delete_post', array( Review_Service::class, 'delete_post_reviews' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_tables_with_site' ) );

		if ( is_admin() ) {
			Admin\Admin::boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'relecture', CLI_Command::class );
		}
	}

	/**
	 * Loads the bundled translations.
	 *
	 * Needed as long as the plugin is not hosted on WordPress.org: language packs from
	 * translate.wordpress.org will then take over.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Bundled fr_FR translation, see above.
		load_plugin_textdomain( 'relecture', false, dirname( plugin_basename( RELECTURE_FILE ) ) . '/languages' );
	}

	/**
	 * URL of the review screen of a post.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $review_id Optional review (round) to display; the latest one by default.
	 * @return string
	 */
	public static function review_url( $post_id, $review_id = 0 ) {
		$args = array(
			'page' => 'relecture',
			'post' => (int) $post_id,
		);
		if ( $review_id ) {
			$args['review'] = (int) $review_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * URL that opens the review of a post, starting it if none exists yet.
	 *
	 * Goes through admin-post.php with a nonce: starting a review writes to the database,
	 * so a plain link must not be able to trigger it on behalf of someone else.
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $new_round Start a new round even if a closed review exists.
	 * @return string
	 */
	public static function start_url( $post_id, $new_round = false ) {
		$args = array(
			'action' => 'relecture_start',
			'post'   => (int) $post_id,
		);
		if ( $new_round ) {
			$args['new_round'] = 1;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'relecture_start_' . (int) $post_id );
	}

	/**
	 * Adds “Review this page” to the toolbar on the front end.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 * @return void
	 */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! Permissions::can_review_post( $post ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'relecture',
				'title' => esc_html__( 'Review this page', 'relecture' ),
				'href'  => self::start_url( $post->ID ),
			)
		);
	}
}
