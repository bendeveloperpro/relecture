<?php
/**
 * View: list of the contents to review.
 *
 * @package Relecture
 *
 * @var \Relecture\Admin\Posts_List_Table $table Prepared list table.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap relecture-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Contents to review', 'relecture' ); ?></h1>
	<hr class="wp-header-end">
	<p class="relecture-intro">
		<?php esc_html_e( 'Pick a post or a page: its title and text blocks open in a table with the original text, a correction and a note for each one. Nothing you type changes the content itself.', 'relecture' ); ?>
	</p>
	<form method="get">
		<input type="hidden" name="page" value="relecture">
		<?php
		$table->search_box( __( 'Search contents', 'relecture' ), 'relecture-search' );
		$table->display();
		?>
	</form>
</div>
