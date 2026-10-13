<?php
/**
 * View: guidelines gathered across reviews.
 *
 * @package Relecture
 *
 * @var \Relecture\Admin\Guidelines_List_Table $table Prepared list table.
 * @var int                                    $done  Rows changed by the last bulk action.
 */

use Relecture\Admin\Guidelines_List_Table;
use Relecture\Export\Guidelines_Exporter;

defined( 'ABSPATH' ) || exit;

$relecture_status = Guidelines_List_Table::current_status();
$relecture_action = add_query_arg(
	array(
		'page'             => 'relecture-guidelines',
		'guideline_status' => '' === $relecture_status ? 'all' : $relecture_status,
	),
	admin_url( 'admin.php' )
);
$relecture_labels = array(
	'md'   => __( 'Markdown (for a prompt or a style guide)', 'relecture' ),
	'csv'  => __( 'CSV', 'relecture' ),
	'json' => __( 'JSON', 'relecture' ),
);
?>
<div class="wrap relecture-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Guidelines for future content', 'relecture' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( $done ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of guidelines. */
					_n( '%d guideline updated.', '%d guidelines updated.', $done, 'relecture' ),
					$done
				)
			);
			?>
		</p></div>
	<?php endif; ?>

	<p class="relecture-intro">
		<?php esc_html_e( 'Notes ticked “Guideline for future content” in your reviews. Export them to feed the instructions of your next articles, then mark them as integrated.', 'relecture' ); ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="relecture-export relecture-export-guidelines">
		<input type="hidden" name="action" value="relecture_export_guidelines">
		<input type="hidden" name="guideline_status" value="<?php echo esc_attr( $relecture_status ); ?>">
		<?php wp_nonce_field( 'relecture_export_guidelines', '_wpnonce', false ); ?>
		<label for="relecture-guidelines-format"><?php esc_html_e( 'Export the guidelines shown', 'relecture' ); ?></label>
		<select id="relecture-guidelines-format" name="format">
			<?php foreach ( $relecture_labels as $relecture_format => $relecture_label ) : ?>
				<?php if ( in_array( $relecture_format, Guidelines_Exporter::FORMATS, true ) ) : ?>
					<option value="<?php echo esc_attr( $relecture_format ); ?>"><?php echo esc_html( $relecture_label ); ?></option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Export', 'relecture' ); ?></button>
	</form>

	<?php $table->views(); ?>
	<form method="post" action="<?php echo esc_url( $relecture_action ); ?>">
		<?php $table->display(); ?>
	</form>
</div>
