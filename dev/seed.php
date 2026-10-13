<?php
/**
 * Seeds the development site with test content (run by dev/setup.sh through `wp eval-file`).
 *
 * Idempotent: every item is looked up by slug and rewritten. The texts are neutral fixtures;
 * they only exercise the extractor, the permissions and the output escaping.
 *
 * @package Relecture
 */

defined( 'ABSPATH' ) || exit;

// Save as the administrator so that KSES leaves the fixtures untouched (the <script> inside
// the Custom HTML block must reach the database: the extractor has to skip it, not KSES).
$relecture_admin = get_user_by( 'login', 'admin' );
$relecture_users = array(
	'admin'       => $relecture_admin ? (int) $relecture_admin->ID : 1,
	'editor'      => (int) ( get_user_by( 'login', 'editor' )->ID ?? 1 ),
	'author'      => (int) ( get_user_by( 'login', 'author' )->ID ?? 1 ),
	'contributor' => (int) ( get_user_by( 'login', 'contributor' )->ID ?? 1 ),
);
wp_set_current_user( $relecture_users['admin'] );

/**
 * Creates or rewrites a post identified by its slug.
 *
 * @param array $args Post fields; must contain post_name and post_type.
 * @return int Post ID.
 */
function relecture_seed_upsert( array $args ) {
	$existing = get_page_by_path( $args['post_name'], OBJECT, $args['post_type'] );
	if ( $existing ) {
		$args['ID'] = $existing->ID;
	}
	$id = wp_insert_post( wp_slash( $args ), true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	WP_CLI::log( sprintf( '  %s #%d %s', $args['post_type'], $id, $args['post_name'] ) );
	return (int) $id;
}

$relecture_pattern_id = relecture_seed_upsert(
	array(
		'post_type'    => 'wp_block',
		'post_name'    => 'relecture-demo-composition',
		'post_title'   => 'Composition de démonstration',
		'post_status'  => 'publish',
		'post_content' => "<!-- wp:paragraph -->\n<p>Texte d'une composition synchronisée.</p>\n<!-- /wp:paragraph -->",
	)
);

$relecture_blocks = <<<'HTML'
<!-- wp:paragraph -->
<p>Bienvenue dans l'article de démonstration&nbsp;: il contient <strong>du gras</strong>, <a href="https://example.test/">un lien</a> et une espace insécable avant les deux-points.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Un titre de niveau deux</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Un titre de niveau trois</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Premier élément</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Deuxième élément<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Sous-élément imbriqué</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>La relecture est une politesse faite au lecteur.</p>
<!-- /wp:paragraph --><cite>Une autrice imaginaire</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:pullquote -->
<figure class="wp-block-pullquote"><blockquote><p>Une phrase mise en exergue.</p><cite>Source de l'exergue</cite></blockquote></figure>
<!-- /wp:pullquote -->

<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://example.test/image.jpg" alt="Un chat qui relit un manuscrit"/><figcaption class="wp-element-caption">Légende de l'image</figcaption></figure>
<!-- /wp:image -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Colonne A</th><th>Colonne B</th></tr></thead><tbody><tr><td>Valeur A1</td><td>Valeur B1</td></tr><tr><td>Valeur A2</td><td>Valeur B2</td></tr></tbody></table><figcaption class="wp-element-caption">Légende du tableau</figcaption></figure>
<!-- /wp:table -->

<!-- wp:details -->
<details class="wp-block-details"><summary>Question fréquente</summary><!-- wp:paragraph -->
<p>La réponse cachée dans le bloc Détails.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.test/contact/">Texte du bouton</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Texte de la colonne de gauche.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Texte de la colonne de droite.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:verse -->
<pre class="wp-block-verse">Premier vers,<br>  second vers indenté.</pre>
<!-- /wp:verse -->

<!-- wp:code -->
<pre class="wp-block-code"><code>echo "ce code ne doit pas être relu";</code></pre>
<!-- /wp:code -->

<!-- wp:shortcode -->
[gallery ids="1,2"]
<!-- /wp:shortcode -->

<!-- wp:html -->
<div class="encart"><p>Texte visible dans un bloc HTML.</p><script>console.log("script invisible");</script></div>
<!-- /wp:html -->

<!-- wp:embed {"url":"https://example.test/video","type":"video"} -->
<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">
https://example.test/video
</div><figcaption class="wp-element-caption">Légende de la vidéo</figcaption></figure>
<!-- /wp:embed -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:block {"ref":%PATTERN_ID%} /-->

<!-- wp:paragraph -->
<p>Dernier paragraphe, identique à un autre.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Dernier paragraphe, identique à un autre.</p>
<!-- /wp:paragraph -->
HTML;

relecture_seed_upsert(
	array(
		'post_type'    => 'post',
		'post_name'    => 'relecture-demo-blocs',
		'post_title'   => 'Article de démonstration en blocs',
		'post_excerpt' => 'Extrait de démonstration.',
		'post_status'  => 'publish',
		'post_author'  => $relecture_users['editor'],
		'post_content' => str_replace( '%PATTERN_ID%', (string) $relecture_pattern_id, $relecture_blocks ),
	)
);

$relecture_classic = <<<'HTML'
<p>Un paragraphe de l'éditeur classique&nbsp;!</p>
<h3>Intertitre classique</h3>
<ul>
<li>Point un</li>
<li>Point deux
<ul>
<li>Point imbriqué</li>
</ul>
</li>
</ul>
<blockquote><p>Citation classique.</p></blockquote>
<table><tr><td>Cellule 1</td><td>Cellule 2</td></tr></table>
<p><img src="https://example.test/photo.jpg" alt="Photo d'illustration"> Texte après l'image.</p>

Ligne sans balise, transformée en paragraphe par wpautop.
HTML;

relecture_seed_upsert(
	array(
		'post_type'    => 'post',
		'post_name'    => 'relecture-demo-classique',
		'post_title'   => 'Article de démonstration classique',
		'post_status'  => 'draft',
		'post_author'  => $relecture_users['editor'],
		'post_content' => $relecture_classic,
	)
);

relecture_seed_upsert(
	array(
		'post_type'    => 'page',
		'post_name'    => 'relecture-demo-page',
		'post_title'   => 'Page de démonstration',
		'post_status'  => 'publish',
		'post_author'  => $relecture_users['editor'],
		'post_content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Titre de la page</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Paragraphe de la page de démonstration.</p>\n<!-- /wp:paragraph -->",
	)
);

relecture_seed_upsert(
	array(
		'post_type'    => 'post',
		'post_name'    => 'relecture-demo-auteur',
		'post_title'   => "Article de l'auteur",
		'post_status'  => 'draft',
		'post_author'  => $relecture_users['author'],
		'post_content' => "<!-- wp:paragraph -->\n<p>Texte écrit par le compte auteur.</p>\n<!-- /wp:paragraph -->",
	)
);

$relecture_traps = <<<'HTML'
<!-- wp:paragraph -->
<p>&lt;script&gt;alert("xss-original")&lt;/script&gt; et "&gt;&lt;img src=x onerror=alert(2)&gt;</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>=HYPERLINK("https://example.test","clic")</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>+33 6 00 00 00 00</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>-5 degrés, @mention et 100 %</p>
<!-- /wp:paragraph -->
HTML;

relecture_seed_upsert(
	array(
		'post_type'    => 'post',
		'post_name'    => 'relecture-demo-pieges',
		'post_title'   => 'Article piégé <em>(XSS & formules)</em>',
		'post_status'  => 'draft',
		'post_author'  => $relecture_users['editor'],
		'post_content' => $relecture_traps,
	)
);

WP_CLI::success( 'Test content ready.' );
