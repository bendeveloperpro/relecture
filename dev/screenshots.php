<?php
/**
 * Content for the WordPress.org screenshots, in English (run by dev/setup.sh):
 *
 *   RELECTURE_ENV=shots RELECTURE_PORT=8092 podman-compose -p relecture-shots up -d
 *   RELECTURE_ENV=shots RELECTURE_PORT=8092 WP_LOCALE=en_US RELECTURE_SEED=screenshots ./setup.sh
 *
 * A short sample article with typos to correct, plus a few posts whose reviews are at
 * different stages. Neutral fixtures, rewritten on every run.
 *
 * @package Relecture
 */

use Relecture\Repository;
use Relecture\Review_Service;

defined( 'ABSPATH' ) || exit;

$relecture_admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $relecture_admin->ID );
wp_update_user(
	array(
		'ID'           => $relecture_admin->ID,
		'display_name' => 'Alex',
	)
);

/**
 * Creates or rewrites a post, and drops its previous reviews.
 *
 * @param string $slug    Slug.
 * @param string $title   Title.
 * @param string $content Block content.
 * @param string $status  Post status.
 * @return WP_Post
 */
function relecture_shots_post( $slug, $title, $content, $status = 'draft' ) {
	$existing = get_page_by_path( $slug, OBJECT, 'post' );
	$id       = wp_insert_post(
		wp_slash(
			array(
				'ID'           => $existing ? $existing->ID : 0,
				'post_type'    => 'post',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_status'  => $status,
				'post_content' => $content,
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	Review_Service::delete_post_reviews( $id );
	return get_post( $id );
}

/**
 * Paragraph block.
 *
 * @param string $text Text.
 * @return string
 */
function relecture_shots_p( $text ) {
	return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->\n\n";
}

/**
 * Heading block.
 *
 * @param string $text Text.
 * @return string
 */
function relecture_shots_h2( $text ) {
	return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">$text</h2>\n<!-- /wp:heading -->\n\n";
}

/**
 * Applies feedback to the rows of a review whose original text contains a needle.
 *
 * @param array $review   Review.
 * @param array $feedback Needle => changes.
 * @return void
 */
function relecture_shots_feedback( array $review, array $feedback ) {
	foreach ( Repository::get_items( $review['id'] ) as $item ) {
		foreach ( $feedback as $needle => $changes ) {
			if ( false !== strpos( $item['original'], $needle ) ) {
				Review_Service::update_item( $item, $changes, get_current_user_id() );
			}
		}
	}
}

$relecture_list = "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Use water at room temperature.</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Empty the saucer after half an hour.</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>Never let a pot stand in water.</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n";

$relecture_main = relecture_shots_post(
	'houseplants-in-winter',
	'How to keep your houseplants alive in winter',
	relecture_shots_p( 'Winter is hard on houseplants: shorter days, dry air and cold windows slow them down. A few simple habbits are enough to get them through to spring.' )
	. relecture_shots_h2( 'Give them as much light as posible' )
	. relecture_shots_p( "Move your plants closer to the brightest window, but keep the leaves away from the cold glass. Turn the pots every week so that every side gets it's share of light." )
	. relecture_shots_h2( 'Water less, but water well' )
	. relecture_shots_p( 'Plants grow slower in winter and need less water. Check the soil with a finger: if the first two centimetres are dry, water slowly until it drains.' )
	. $relecture_list
	. relecture_shots_h2( 'Fight the dry air' )
	. relecture_shots_p( 'Radiators dry the air. Group your plants together, or set them on a tray of wet pebbles to raise the humidity around the leaves.' )
);
$relecture_review = Review_Service::start( $relecture_main, get_current_user_id() );
relecture_shots_feedback(
	$relecture_review,
	array(
		'Winter is hard'   => array(
			'correction'        => 'Winter is hard on houseplants: shorter days, dry air and cold windows slow them down. A few simple habits are enough to get them through to spring.',
			'correction_status' => 'accepted',
			'note'              => 'Good opening. Keep it under 30 words.',
		),
		'as posible'       => array( 'correction' => 'Give them as much light as possible' ),
		'Move your plants' => array(
			'correction' => 'Move your plants closer to the brightest window, but keep the leaves away from the cold glass. Turn the pots every week so that every side gets its share of light.',
			'note'       => 'Always explain why: plants lean towards the light.',
			'note_type'  => 'guideline',
		),
		'two centimetres'  => array(
			'note'      => 'Give metric units first, then imperial ones in brackets.',
			'note_type' => 'guideline',
		),
		'room temperature' => array( 'note' => 'Introduce the list with a short sentence.' ),
	)
);

$relecture_states = array(
	array( 'composting-for-beginners', "A beginner's guide to composting", 'ready' ),
	array( 'choosing-a-pot', 'Choosing the right pot size', 'done' ),
	array( 'repotting-without-stress', 'Repotting without stress', '' ),
);
foreach ( $relecture_states as $relecture_state ) {
	$relecture_post = relecture_shots_post(
		$relecture_state[0],
		$relecture_state[1],
		relecture_shots_p( 'A short introduction to the topic.' ) . relecture_shots_h2( 'What you need' ) . relecture_shots_p( 'A list of the tools and the materials.' ),
		'done' === $relecture_state[2] ? 'publish' : 'draft'
	);
	if ( '' === $relecture_state[2] ) {
		continue;
	}
	$relecture_other = Review_Service::start( $relecture_post, get_current_user_id() );
	relecture_shots_feedback(
		$relecture_other,
		array(
			'introduction' => array(
				'correction' => 'A short and friendly introduction to the topic.',
				'note'       => 'Start with the reader’s problem, not with the topic.',
				'note_type'  => 'guideline',
			),
		)
	);
	Review_Service::set_status( Repository::get_review( $relecture_other['id'] ), $relecture_state[2], get_current_user_id() );
}

// The default sample content would show up in the lists.
foreach ( array( 'hello-world' => 'post', 'sample-page' => 'page', 'privacy-policy' => 'page' ) as $relecture_slug => $relecture_type ) {
	$relecture_sample = get_page_by_path( $relecture_slug, OBJECT, $relecture_type );
	if ( $relecture_sample ) {
		wp_delete_post( $relecture_sample->ID, true );
	}
}

WP_CLI::success( sprintf( 'Screenshot content ready: review screen at post %d.', $relecture_main->ID ) );
