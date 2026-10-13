<?php
/**
 * Smoke tests, run on the development site (after dev/setup.sh):
 *
 *   podman exec relecture_dev_cli wp eval-file wp-content/plugins/relecture/tests/smoke.php
 *
 * Works on the seeded contents, cleans up the reviews it creates, exits non-zero on failure.
 *
 * @package Relecture
 */

use Relecture\Export\Csv_Writer;
use Relecture\Export\Export_Data;
use Relecture\Export\Exporter;
use Relecture\Export\Guidelines_Exporter;
use Relecture\Extractor;
use Relecture\Permissions;
use Relecture\Repository;
use Relecture\Review_Service;

defined( 'ABSPATH' ) || exit;

$relecture_failures = 0;

/**
 * Records one check.
 *
 * @param bool   $condition Expected to be true.
 * @param string $label     What is checked.
 * @return void
 */
function relecture_check( $condition, $label ) {
	global $relecture_failures;
	if ( $condition ) {
		WP_CLI::log( '  ✔ ' . $label );
		return;
	}
	++$relecture_failures;
	WP_CLI::log( WP_CLI::colorize( '  %R✘ ' . $label . '%n' ) );
}

/**
 * A seeded post.
 *
 * @param string $slug Slug.
 * @param string $type Post type.
 * @return WP_Post
 */
function relecture_post( $slug, $type = 'post' ) {
	$post = get_page_by_path( $slug, OBJECT, $type );
	if ( ! $post ) {
		WP_CLI::error( "Seed content $slug is missing: run dev/setup.sh first." );
	}
	return $post;
}

/**
 * First item whose original text contains a needle.
 *
 * @param array[] $items  Items.
 * @param string  $needle Text.
 * @return array|null
 */
function relecture_find( array $items, $needle ) {
	foreach ( $items as $item ) {
		if ( false !== strpos( $item['original'], $needle ) ) {
			return $item;
		}
	}
	return null;
}

$relecture_admin  = get_user_by( 'login', 'admin' );
$relecture_editor = get_user_by( 'login', 'editor' );
$relecture_author = get_user_by( 'login', 'author' );
$relecture_contri = get_user_by( 'login', 'contributor' );
wp_set_current_user( $relecture_admin->ID );

$relecture_blocks  = relecture_post( 'relecture-demo-blocs' );
$relecture_classic = relecture_post( 'relecture-demo-classique' );
$relecture_traps   = relecture_post( 'relecture-demo-pieges' );
$relecture_mine    = relecture_post( 'relecture-demo-auteur' );

// Start from a clean slate for the posts used here.
foreach ( array( $relecture_blocks, $relecture_classic, $relecture_traps, $relecture_mine ) as $relecture_p ) {
	Review_Service::delete_post_reviews( $relecture_p->ID );
}

WP_CLI::log( 'Extraction' );
$relecture_segments = Extractor::extract( $relecture_blocks );
$relecture_texts    = wp_list_pluck( $relecture_segments, 'original' );
$relecture_joined   = implode( "\n", $relecture_texts );
relecture_check( 32 === count( $relecture_segments ), 'block post gives 32 segments (got ' . count( $relecture_segments ) . ')' );
relecture_check( false !== strpos( $relecture_joined, "démonstration\u{00A0}:" ), 'non-breaking space before the colon is kept' );
relecture_check( false === strpos( $relecture_joined, 'ce code ne doit pas' ), 'code block is skipped' );
relecture_check( false === strpos( $relecture_joined, 'gallery' ), 'shortcode block is skipped' );
relecture_check( false === strpos( $relecture_joined, 'script invisible' ), 'script inside Custom HTML is skipped' );
relecture_check( false === strpos( $relecture_joined, 'example.test/video' ), 'embed URL is skipped, caption kept' );
relecture_check( in_array( "Premier vers,\n  second vers indenté.", $relecture_texts, true ), 'verse keeps line break and indentation' );
relecture_check( 6 === count( wp_list_filter( $relecture_segments, array( 'segment_type' => 'cell' ) ) ), 'table gives one segment per cell' );
$relecture_order = array_search( 'Une autrice imaginaire', $relecture_texts, true ) > array_search( 'La relecture est une politesse faite au lecteur.', $relecture_texts, true );
relecture_check( $relecture_order, 'quote source comes after the quoted text' );
relecture_check( 12 === count( Extractor::extract( $relecture_classic ) ), 'classic post gives 12 segments' );

WP_CLI::log( 'Permissions' );
wp_set_current_user( $relecture_contri->ID );
relecture_check( ! Permissions::can_review_post( $relecture_blocks ), 'contributor cannot review an editor post' );
relecture_check( ! Permissions::can_review_post( $relecture_mine ), 'contributor cannot review an author post' );
wp_set_current_user( $relecture_author->ID );
relecture_check( Permissions::can_review_post( $relecture_mine ), 'author can review own draft' );
relecture_check( ! Permissions::can_review_post( $relecture_blocks ), 'author cannot review an editor post' );
relecture_check( ! Permissions::can_review_others(), 'author is restricted to own content in lists' );
wp_set_current_user( $relecture_editor->ID );
relecture_check( Permissions::can_review_post( $relecture_blocks ) && Permissions::can_review_post( $relecture_mine ), 'editor can review every post' );
wp_set_current_user( 0 );
relecture_check( ! Permissions::can_review_post( $relecture_blocks ), 'logged-out visitor cannot review' );
wp_set_current_user( $relecture_editor->ID );

WP_CLI::log( 'Review service' );
$relecture_review = Review_Service::start( $relecture_blocks, $relecture_editor->ID );
$relecture_items  = Repository::get_items( $relecture_review['id'] );
relecture_check( 'in_progress' === $relecture_review['status'], 'review starts in progress' );
relecture_check( 32 === count( $relecture_items ), 'snapshot holds every segment' );
relecture_check( Review_Service::start( $relecture_blocks, $relecture_editor->ID )['id'] === $relecture_review['id'], 'starting again reopens the same review' );

$relecture_para   = relecture_find( $relecture_items, 'Bienvenue' );
$relecture_result = Review_Service::update_item( $relecture_para, array( 'correction' => 'Bienvenue dans cet article de démonstration.' ), $relecture_editor->ID, $relecture_para['version'] );
relecture_check( ! is_wp_error( $relecture_result ) && 'pending' === $relecture_result['correction_status'], 'a new correction is “to validate”' );
relecture_check( ! is_wp_error( $relecture_result ) && 1 === $relecture_result['version'], 'saving bumps the version' );
$relecture_stale = Review_Service::update_item( $relecture_para, array( 'correction' => 'Autre texte.' ), $relecture_editor->ID, 0 );
relecture_check( is_wp_error( $relecture_stale ) && 409 === $relecture_stale->get_error_data()['status'], 'a stale version is refused with 409' );

$relecture_title = relecture_find( $relecture_items, 'Article de démonstration en blocs' );
$relecture_same  = Review_Service::update_item( $relecture_title, array( 'correction' => "  Article de démonstration en blocs \n" ), $relecture_editor->ID );
relecture_check( '' === $relecture_same['correction'] && '' === $relecture_same['correction_status'], 'a correction identical to the original counts as none' );

$relecture_h2 = relecture_find( $relecture_items, 'Un titre de niveau deux' );
$relecture_g  = Review_Service::update_item(
	$relecture_h2,
	array(
		'note'      => 'Toujours un verbe dans les intertitres.',
		'note_type' => 'guideline',
	),
	$relecture_editor->ID
);
relecture_check( 'guideline' === $relecture_g['note_type'] && 'new' === $relecture_g['guideline_status'], 'a note can become a guideline' );
$relecture_g = Review_Service::update_item( $relecture_g, array( 'guideline_status' => 'integrated' ), $relecture_editor->ID );
$relecture_g = Review_Service::update_item( $relecture_g, array( 'note' => 'Toujours un verbe conjugué dans les intertitres.' ), $relecture_editor->ID );
relecture_check( 'new' === $relecture_g['guideline_status'], 'editing an integrated guideline makes it new again' );

$relecture_found = Repository::query_guidelines(
	array(
		'status'     => 'new',
		'post_types' => Permissions::post_types(),
	)
);
relecture_check( 1 === count( wp_list_filter( $relecture_found, array( 'id' => $relecture_g['id'] ) ) ), 'the guideline is listed with the new ones' );

$relecture_long = Review_Service::update_item( relecture_find( $relecture_items, 'Premier élément' ), array( 'note' => str_repeat( 'é', 25000 ) . "\x07" ), $relecture_editor->ID );
relecture_check( 20000 === mb_strlen( $relecture_long['note'] ), 'notes are capped at 20,000 characters' );

WP_CLI::log( 'Resync' );
$relecture_orphan = relecture_find( Repository::get_items( $relecture_review['id'] ), 'Texte de la colonne de gauche.' );
Review_Service::update_item( $relecture_orphan, array( 'note' => 'Note sur un texte qui va disparaître.' ), $relecture_editor->ID );
$relecture_original_content = $relecture_blocks->post_content;
wp_update_post(
	array(
		'ID'           => $relecture_blocks->ID,
		'post_content' => str_replace(
			array( 'Texte de la colonne de gauche.', 'Texte de la colonne de droite.' ),
			array( 'Un tout autre texte à gauche.', 'Texte de la colonne de droite.' ),
			$relecture_original_content
		),
	)
);
$relecture_fresh = get_post( $relecture_blocks->ID );
relecture_check( Review_Service::is_outdated( $relecture_review, $relecture_fresh ), 'a changed post makes the review outdated' );
$relecture_counts = Review_Service::resync( $relecture_review, $relecture_fresh, $relecture_editor->ID );
relecture_check( 1 === $relecture_counts['added'] && 1 === $relecture_counts['orphaned'] && 31 === $relecture_counts['kept'], 'resync keeps 31 rows, adds 1, sets 1 aside (' . wp_json_encode( $relecture_counts ) . ')' );
$relecture_after = Repository::get_items( $relecture_review['id'] );
relecture_check( 'Bienvenue dans cet article de démonstration.' === relecture_find( $relecture_after, 'Bienvenue' )['correction'], 'corrections survive the resync' );
relecture_check( 1 === relecture_find( $relecture_after, 'colonne de gauche' )['orphaned'], 'the vanished row is kept as orphaned' );
relecture_check( ! Review_Service::is_outdated( Repository::get_review( $relecture_review['id'] ), $relecture_fresh ), 'the review is up to date after a resync' );
wp_update_post(
	array(
		'ID'           => $relecture_blocks->ID,
		'post_content' => $relecture_original_content,
	)
);

WP_CLI::log( 'Statuses' );
$relecture_done = Review_Service::set_status( Repository::get_review( $relecture_review['id'] ), 'done', $relecture_editor->ID );
$relecture_ro   = Review_Service::update_item( relecture_find( $relecture_after, 'Bienvenue' ), array( 'note' => 'Trop tard.' ), $relecture_editor->ID );
relecture_check( is_wp_error( $relecture_ro ), 'a closed review is read-only' );
$relecture_round = Review_Service::start( $relecture_blocks, $relecture_editor->ID, true );
relecture_check( $relecture_round['id'] !== $relecture_done['id'], 'a new round can start after a closed review' );
relecture_check( is_wp_error( Review_Service::set_status( $relecture_done, 'in_progress', $relecture_editor->ID ) ), 'an old round cannot be reopened' );

WP_CLI::log( 'Exports' );
$relecture_trap_review = Review_Service::start( $relecture_traps, $relecture_editor->ID );
$relecture_trap_items  = Repository::get_items( $relecture_trap_review['id'] );
Review_Service::update_item( relecture_find( $relecture_trap_items, 'HYPERLINK' ), array( 'correction' => '=HYPERLINK("https://evil.test","clic ici")' ), $relecture_editor->ID );
Review_Service::update_item( relecture_find( $relecture_trap_items, 'xss-original' ), array( 'note' => '<script>alert("xss-note")</script>' ), $relecture_editor->ID );
$relecture_data = Export_Data::review( $relecture_trap_review, $relecture_traps );

$relecture_csv = Exporter::render( $relecture_data, 'csv' )['contents'];
relecture_check( 0 === strpos( $relecture_csv, "\xEF\xBB\xBF" ), 'CSV starts with a UTF-8 BOM' );
relecture_check( 2 === substr_count( $relecture_csv, "'=HYPERLINK(" ) && 0 === preg_match( '/(^|[;\n])"?=HYPERLINK/m', $relecture_csv ), 'CSV neutralizes formulas (original and correction)' );
relecture_check( false !== strpos( $relecture_csv, "'+33" ) && false !== strpos( $relecture_csv, "'-5" ), 'CSV prefixes cells starting with + and -' );
relecture_check( "'=x" === Csv_Writer::cell( '=x' ) && "'@x" === Csv_Writer::cell( '@x' ) && 'a=x' === Csv_Writer::cell( 'a=x' ), 'CSV cell neutralization rule' );
relecture_check( ';' === Csv_Writer::delimiter(), 'CSV uses “;” in fr_FR' );

foreach ( array( 'xlsx', 'docx' ) as $relecture_format ) {
	$relecture_file = Exporter::render( $relecture_data, $relecture_format );
	$relecture_zip  = new ZipArchive();
	$relecture_ok   = isset( $relecture_file['path'] ) && true === $relecture_zip->open( $relecture_file['path'] );
	relecture_check( $relecture_ok, strtoupper( $relecture_format ) . ' is a valid zip' );
	if ( ! $relecture_ok ) {
		continue;
	}
	$relecture_xml_ok = true;
	$relecture_all    = '';
	for ( $relecture_i = 0; $relecture_i < $relecture_zip->numFiles; $relecture_i++ ) {
		$relecture_name = $relecture_zip->getNameIndex( $relecture_i );
		$relecture_xml  = $relecture_zip->getFromIndex( $relecture_i );
		$relecture_all .= $relecture_xml;
		$relecture_doc  = new DOMDocument();
		if ( ! @$relecture_doc->loadXML( $relecture_xml ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$relecture_xml_ok = false;
			WP_CLI::log( "    malformed: $relecture_name" );
		}
	}
	$relecture_zip->close();
	wp_delete_file( $relecture_file['path'] );
	relecture_check( $relecture_xml_ok, strtoupper( $relecture_format ) . ': every part is well-formed XML' );
	relecture_check( false === strpos( $relecture_all, '<script>' ) && false !== strpos( $relecture_all, '&lt;script&gt;' ), strtoupper( $relecture_format ) . ': payloads are escaped' );
}

$relecture_json = json_decode( Exporter::render( $relecture_data, 'json' )['contents'], true );
relecture_check( 'relecture/review@1' === $relecture_json['schema'] && 5 === count( $relecture_json['items'] ), 'JSON export decodes with its schema and 5 rows' );
$relecture_md = Exporter::render( $relecture_data, 'md' )['contents'];
relecture_check( false !== strpos( $relecture_md, "```text\n<script>alert(\"xss-original\")" ), 'Markdown keeps texts verbatim inside fences' );
relecture_check( false === strpos( preg_replace( '/```text.*?```/s', '', $relecture_md ), '<script>' ), 'Markdown escapes HTML outside fences' );

$relecture_only = Export_Data::review( $relecture_trap_review, $relecture_traps, array( 'only_with_feedback' => true ) );
relecture_check( 2 === count( $relecture_only['items'] ), 'export can keep only the rows with feedback' );

$relecture_guidelines = Guidelines_Exporter::render( Guidelines_Exporter::data( $relecture_found, 'new' ), 'md' );
relecture_check( false !== strpos( $relecture_guidelines['contents'], 'intertitres' ), 'guidelines export as Markdown' );

// Clean up what this run created.
foreach ( array( $relecture_blocks, $relecture_classic, $relecture_traps, $relecture_mine ) as $relecture_p ) {
	Review_Service::delete_post_reviews( $relecture_p->ID );
}

if ( $relecture_failures ) {
	WP_CLI::error( $relecture_failures . ' check(s) failed.' );
}
WP_CLI::success( 'All checks passed.' );
