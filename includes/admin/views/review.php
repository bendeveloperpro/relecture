<?php
/**
 * View: review screen of a content (the three-column table).
 *
 * @package Relecture
 *
 * @var array $screen Prepared by Admin::load_main(): post, review, rounds and, when a
 *                    review exists, items (keyed by row number), stats, outdated.
 */

use Relecture\Admin\Admin;
use Relecture\Export\Export_Data;
use Relecture\Export\Exporter;
use Relecture\Labels;
use Relecture\Permissions;
use Relecture\Plugin;
use Relecture\Text;

defined( 'ABSPATH' ) || exit;

$relecture_post   = $screen['post'];
$relecture_review = $screen['review'];
$relecture_title  = Text::from_html( $relecture_post->post_title );
$relecture_type   = get_post_type_object( $relecture_post->post_type );
$relecture_notice = Admin::notice();
$relecture_link   = get_permalink( $relecture_post );
$relecture_edit   = get_edit_post_link( $relecture_post->ID );
?>
<div class="wrap relecture-wrap">
	<h1 class="wp-heading-inline">
		<?php
		/* translators: %s: title of the reviewed content. */
		echo esc_html( sprintf( __( 'Review: %s', 'relecture' ), '' !== $relecture_title ? $relecture_title : __( '(no title)', 'relecture' ) ) );
		?>
	</h1>
	<?php if ( $relecture_link ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( $relecture_link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'relecture' ); ?></a>
	<?php endif; ?>
	<?php if ( $relecture_edit ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( $relecture_edit ); ?>"><?php esc_html_e( 'Edit', 'relecture' ); ?></a>
	<?php endif; ?>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=relecture' ) ); ?>"><?php esc_html_e( 'All contents', 'relecture' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( $relecture_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $relecture_notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $relecture_notice[1] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $relecture_review ) : ?>
		<div class="relecture-start">
			<p><?php esc_html_e( 'This content has not been reviewed yet. Starting a review takes a snapshot of its title and text blocks; the content itself is never changed.', 'relecture' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( Plugin::start_url( $relecture_post->ID ) ); ?>"><?php esc_html_e( 'Start the review', 'relecture' ); ?></a></p>
		</div>
	</div>
		<?php
		return;
	endif;

	$relecture_items     = $screen['items'];
	$relecture_stats     = $screen['stats'];
	$relecture_read_only = 'done' === $relecture_review['status'];
	$relecture_meta      = Export_Data::review_meta( $relecture_review, $relecture_post );
	$relecture_is_latest = $screen['rounds'] && $screen['rounds'][0]['id'] === $relecture_review['id'];
	$relecture_statuses  = Labels::correction_statuses();
	?>

	<p class="relecture-meta">
		<?php
		$relecture_parts = array(
			$relecture_type ? $relecture_type->labels->singular_name : $relecture_post->post_type,
			get_post_status_object( $relecture_post->post_status ) ? get_post_status_object( $relecture_post->post_status )->label : $relecture_post->post_status,
			/* translators: %d: review round number. */
			sprintf( __( 'Round %d', 'relecture' ), $relecture_meta['round'] ),
			'' !== $relecture_meta['created_by']
				/* translators: 1: date, 2: user name. */
				? sprintf( __( 'Started on %1$s by %2$s', 'relecture' ), Export_Data::human_date( $relecture_meta['created_at'] ), $relecture_meta['created_by'] )
				/* translators: %s: date. */
				: sprintf( __( 'Started on %s', 'relecture' ), Export_Data::human_date( $relecture_meta['created_at'] ) ),
		);
		echo esc_html( implode( ' · ', $relecture_parts ) );
		?>
		<span class="relecture-state relecture-state-<?php echo esc_attr( $relecture_review['status'] ); ?>"><?php echo esc_html( $relecture_meta['status_label'] ); ?></span>
	</p>

	<?php if ( count( $screen['rounds'] ) > 1 ) : ?>
		<p class="relecture-rounds">
			<?php esc_html_e( 'Rounds:', 'relecture' ); ?>
			<?php
			$relecture_number = count( $screen['rounds'] );
			foreach ( $screen['rounds'] as $relecture_round ) :
				$relecture_label = sprintf(
					/* translators: 1: round number, 2: review status. */
					__( 'no. %1$d (%2$s)', 'relecture' ),
					$relecture_number,
					Labels::of( Labels::review_statuses(), $relecture_round['status'] )
				);
				--$relecture_number;
				if ( $relecture_round['id'] === $relecture_review['id'] ) :
					?>
					<strong aria-current="page"><?php echo esc_html( $relecture_label ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( Plugin::review_url( $relecture_post->ID, $relecture_round['id'] ) ); ?>"><?php echo esc_html( $relecture_label ); ?></a>
					<?php
				endif;
			endforeach;
			?>
		</p>
	<?php endif; ?>

	<?php if ( $screen['outdated'] && ! $relecture_read_only ) : ?>
		<div class="notice notice-warning inline relecture-outdated">
			<p><?php esc_html_e( 'The content changed after this review started: some original texts may be out of date.', 'relecture' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="relecture_resync">
				<input type="hidden" name="review" value="<?php echo esc_attr( $relecture_review['id'] ); ?>">
				<?php wp_nonce_field( 'relecture_resync_' . $relecture_review['id'] ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Update the original text', 'relecture' ); ?></button>
				<span class="description"><?php esc_html_e( 'Corrections and notes stay attached to their text; rows whose text is gone are kept apart.', 'relecture' ); ?></span></p>
			</form>
		</div>
	<?php endif; ?>

	<div class="relecture-workflow">
		<?php
		$relecture_actions = array();
		if ( 'in_progress' === $relecture_review['status'] ) {
			$relecture_actions['ready'] = array( __( 'Mark the review as complete', 'relecture' ), 'button-primary' );
		} elseif ( 'ready' === $relecture_review['status'] ) {
			$relecture_actions['done']        = array( __( 'Mark the corrections as applied', 'relecture' ), 'button-primary' );
			$relecture_actions['in_progress'] = array( __( 'Back to “in progress”', 'relecture' ), '' );
		} elseif ( $relecture_is_latest ) {
			$relecture_actions['in_progress'] = array( __( 'Reopen this review', 'relecture' ), '' );
		}
		foreach ( $relecture_actions as $relecture_status => $relecture_action ) :
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="relecture_status">
				<input type="hidden" name="review" value="<?php echo esc_attr( $relecture_review['id'] ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $relecture_status ); ?>">
				<?php wp_nonce_field( 'relecture_status_' . $relecture_review['id'] ); ?>
				<button type="submit" class="button <?php echo esc_attr( $relecture_action[1] ); ?>"><?php echo esc_html( $relecture_action[0] ); ?></button>
			</form>
		<?php endforeach; ?>
		<?php if ( $relecture_read_only && $relecture_is_latest ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( Plugin::start_url( $relecture_post->ID, true ) ); ?>"><?php esc_html_e( 'Start a new review round', 'relecture' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( $relecture_read_only ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'This review is closed: its corrections were applied. It stays here for the record; reopen it to change it.', 'relecture' ); ?></p></div>
	<?php endif; ?>

	<div class="relecture-toolbar">
		<div class="relecture-filter" role="group" aria-label="<?php esc_attr_e( 'Rows to show', 'relecture' ); ?>">
			<button type="button" class="button" data-filter="all" aria-pressed="true"><?php esc_html_e( 'All rows', 'relecture' ); ?></button>
			<button type="button" class="button" data-filter="feedback" aria-pressed="false"><?php esc_html_e( 'With feedback', 'relecture' ); ?></button>
			<button type="button" class="button" data-filter="empty" aria-pressed="false"><?php esc_html_e( 'Without feedback', 'relecture' ); ?></button>
		</div>
		<?php if ( ! $relecture_read_only ) : ?>
			<label class="relecture-prefill"><input type="checkbox" id="relecture-prefill" checked> <?php esc_html_e( 'Start corrections from the original text', 'relecture' ); ?></label>
		<?php endif; ?>
		<p class="relecture-counters">
			<?php
			// “Label: number” rather than “number label”: the label never has to agree with the number.
			$relecture_counters = array(
				'corrections' => __( 'Corrections', 'relecture' ),
				'notes'       => __( 'Notes', 'relecture' ),
				'guidelines'  => __( 'Guidelines', 'relecture' ),
			);
			foreach ( $relecture_counters as $relecture_key => $relecture_counter ) :
				?>
				<span>
					<?php
					/* translators: %s: a label such as “Content” or “Corrections”; French typography adds a non-breaking space before the colon. */
					echo esc_html( sprintf( __( '%s:', 'relecture' ), $relecture_counter ) );
					?>
					<strong data-count="<?php echo esc_attr( $relecture_key ); ?>"><?php echo esc_html( number_format_i18n( $relecture_stats[ $relecture_key ] ) ); ?></strong>
				</span>
			<?php endforeach; ?>
		</p>
		<p class="relecture-save-state" role="status" aria-live="polite"></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="relecture-export">
			<input type="hidden" name="action" value="relecture_export">
			<input type="hidden" name="review" value="<?php echo esc_attr( $relecture_review['id'] ); ?>">
			<?php wp_nonce_field( 'relecture_export_' . $relecture_review['id'] ); ?>
			<label for="relecture-format" class="screen-reader-text"><?php esc_html_e( 'Export format', 'relecture' ); ?></label>
			<select id="relecture-format" name="format">
				<?php foreach ( Exporter::format_labels() as $relecture_format => $relecture_label ) : ?>
					<option value="<?php echo esc_attr( $relecture_format ); ?>"><?php echo esc_html( $relecture_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="relecture-only-feedback"><input type="checkbox" name="only_feedback" value="1"> <?php esc_html_e( 'Only rows with feedback', 'relecture' ); ?></label>
			<button type="submit" class="button"><?php esc_html_e( 'Export', 'relecture' ); ?></button>
		</form>
	</div>

	<noscript><div class="notice notice-error inline"><p><?php esc_html_e( 'JavaScript is needed to save your corrections and notes.', 'relecture' ); ?></p></div></noscript>

	<table class="widefat relecture-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Original text, correction and note of each element of the content', 'relecture' ); ?></caption>
		<thead>
			<tr>
				<th scope="col" class="relecture-col-original"><?php esc_html_e( 'Original text', 'relecture' ); ?></th>
				<th scope="col" class="relecture-col-correction"><?php esc_html_e( 'Correction', 'relecture' ); ?></th>
				<th scope="col" class="relecture-col-note"><?php esc_html_e( 'Note', 'relecture' ); ?></th>
			</tr>
		</thead>
		<?php
		$relecture_groups = array(
			'current'  => array(),
			'orphaned' => array(),
		);
		foreach ( $relecture_items as $relecture_number => $relecture_item ) {
			$relecture_groups[ $relecture_item['orphaned'] ? 'orphaned' : 'current' ][ $relecture_number ] = $relecture_item;
		}
		foreach ( $relecture_groups as $relecture_group => $relecture_rows ) :
			if ( ! $relecture_rows ) {
				continue;
			}
			?>
			<tbody class="relecture-group-<?php echo esc_attr( $relecture_group ); ?>">
				<?php if ( 'orphaned' === $relecture_group ) : ?>
					<tr class="relecture-row-info relecture-row-heading">
						<th scope="rowgroup" colspan="3"><?php esc_html_e( 'Rows whose text is no longer in the content (kept for the record)', 'relecture' ); ?></th>
					</tr>
				<?php endif; ?>
				<?php
				foreach ( $relecture_rows as $relecture_number => $relecture_item ) :
					$relecture_id    = (int) $relecture_item['id'];
					$relecture_label = Labels::segment( $relecture_item );

					if ( 'reference' === $relecture_item['segment_type'] ) :
						$relecture_ref = isset( $relecture_item['meta']['ref'] ) ? (int) $relecture_item['meta']['ref'] : 0;
						?>
						<tr class="relecture-row-info">
							<td colspan="3">
								<span class="relecture-badge"><?php echo esc_html( $relecture_label ); ?></span>
								<?php
								/* translators: %s: title of a synced pattern. */
								echo esc_html( sprintf( __( 'The pattern “%s” is shared with other contents: it is reviewed separately.', 'relecture' ), $relecture_item['original'] ) );
								?>
								<?php if ( $relecture_ref && Permissions::can_review_post( $relecture_ref ) ) : ?>
									<a href="<?php echo esc_url( Plugin::start_url( $relecture_ref ) ); ?>"><?php esc_html_e( 'Review this pattern', 'relecture' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
						<?php
						continue;
					endif;

					$relecture_has_correction = Text::filled( $relecture_item['correction'] );
					$relecture_has_note       = Text::filled( $relecture_item['note'] );
					$relecture_classes        = array( 'relecture-row' );
					if ( $relecture_has_correction ) {
						$relecture_classes[] = 'has-correction';
					}
					if ( $relecture_has_note ) {
						$relecture_classes[] = 'has-note';
						if ( 'guideline' === $relecture_item['note_type'] ) {
							$relecture_classes[] = 'is-guideline';
						}
					}
					/* translators: 1: row number, 2: element label. */
					$relecture_row_name = sprintf( __( 'row %1$d (%2$s)', 'relecture' ), $relecture_number, $relecture_label );
					?>
					<tr id="relecture-row-<?php echo esc_attr( $relecture_id ); ?>" class="<?php echo esc_attr( implode( ' ', $relecture_classes ) ); ?>" data-id="<?php echo esc_attr( $relecture_id ); ?>" data-version="<?php echo esc_attr( $relecture_item['version'] ); ?>" data-feedback="<?php echo esc_attr( $relecture_has_correction || $relecture_has_note ? '1' : '0' ); ?>">
						<th scope="row" class="relecture-original">
							<span class="relecture-number"><?php echo esc_html( $relecture_number ); ?></span>
							<span class="relecture-badge"><?php echo esc_html( $relecture_label ); ?></span>
							<div class="relecture-original-text"><?php echo esc_html( $relecture_item['original'] ); ?></div>
							<p class="relecture-row-message" hidden></p>
						</th>
						<td class="relecture-correction" data-colname="<?php esc_attr_e( 'Correction', 'relecture' ); ?>">
							<label class="screen-reader-text" for="relecture-correction-<?php echo esc_attr( $relecture_id ); ?>">
								<?php
								/* translators: %s: row name, e.g. “row 3 (H2)”. */
								echo esc_html( sprintf( __( 'Correction of %s', 'relecture' ), $relecture_row_name ) );
								?>
							</label>
							<textarea id="relecture-correction-<?php echo esc_attr( $relecture_id ); ?>" class="relecture-field" data-field="correction" rows="2"<?php echo $relecture_read_only ? ' readonly' : ''; ?>><?php echo esc_textarea( $relecture_item['correction'] ); ?></textarea>
							<div class="relecture-correction-tools">
								<label class="relecture-status">
									<span><?php esc_html_e( 'Status', 'relecture' ); ?></span>
									<select data-field="correction_status"<?php disabled( $relecture_read_only ); ?>>
										<?php foreach ( $relecture_statuses as $relecture_value => $relecture_status_label ) : ?>
											<option value="<?php echo esc_attr( $relecture_value ); ?>"<?php selected( '' === $relecture_item['correction_status'] ? 'pending' : $relecture_item['correction_status'], $relecture_value ); ?>><?php echo esc_html( $relecture_status_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<?php if ( ! $relecture_read_only ) : ?>
									<button type="button" class="button-link relecture-copy" data-action="copy-original"><?php esc_html_e( 'Copy the original text', 'relecture' ); ?></button>
								<?php endif; ?>
							</div>
						</td>
						<td class="relecture-note" data-colname="<?php esc_attr_e( 'Note', 'relecture' ); ?>">
							<label class="screen-reader-text" for="relecture-note-<?php echo esc_attr( $relecture_id ); ?>">
								<?php
								/* translators: %s: row name, e.g. “row 3 (H2)”. */
								echo esc_html( sprintf( __( 'Note on %s', 'relecture' ), $relecture_row_name ) );
								?>
							</label>
							<textarea id="relecture-note-<?php echo esc_attr( $relecture_id ); ?>" class="relecture-field" data-field="note" rows="2"<?php echo $relecture_read_only ? ' readonly' : ''; ?>><?php echo esc_textarea( $relecture_item['note'] ); ?></textarea>
							<label class="relecture-guideline">
								<input type="checkbox" data-field="note_type" value="guideline"<?php checked( 'guideline', $relecture_item['note_type'] ); ?><?php disabled( $relecture_read_only ); ?>>
								<?php esc_html_e( 'Guideline for future content', 'relecture' ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		<?php endforeach; ?>
	</table>
</div>
