<?php
/**
 * Review exports: the layout of each format.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Export_Data into files.
 *
 * Columns and wording live here, the writers only know how to write their format.
 */
final class Exporter {

	/**
	 * Supported formats.
	 */
	const FORMATS = array( 'xlsx', 'docx', 'csv', 'md', 'json' );

	/**
	 * Formats with their labels, in menu order.
	 *
	 * @return array<string, string>
	 */
	public static function format_labels() {
		return array(
			'xlsx' => __( 'Excel (.xlsx)', 'relecture' ),
			'docx' => __( 'Word (.docx)', 'relecture' ),
			'csv'  => __( 'CSV', 'relecture' ),
			'md'   => __( 'Markdown (for an AI)', 'relecture' ),
			'json' => __( 'JSON (for an AI or a script)', 'relecture' ),
		);
	}

	/**
	 * Content type of a format.
	 *
	 * @param string $format Format.
	 * @return string
	 */
	public static function mime( $format ) {
		$types = array(
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'csv'  => 'text/csv; charset=utf-8',
			'md'   => 'text/markdown; charset=utf-8',
			'json' => 'application/json; charset=utf-8',
		);
		return isset( $types[ $format ] ) ? $types[ $format ] : 'application/octet-stream';
	}

	/**
	 * File name of an export: relecture-<title>-<date>.<ext>.
	 *
	 * @param string $name   Name part (a post title).
	 * @param string $format Format.
	 * @return string
	 */
	public static function filename( $name, $format ) {
		$name = sanitize_title( remove_accents( (string) $name ) );
		$name = preg_replace( '/%[0-9a-f]{2}/i', '', $name );
		$name = trim( substr( $name, 0, 60 ), '-' );
		return sanitize_file_name( 'relecture-' . ( '' === $name ? 'export' : $name ) . '-' . wp_date( 'Ymd-Hi' ) . '.' . $format );
	}

	/**
	 * Renders a review export.
	 *
	 * @param array  $data   Export_Data::review().
	 * @param string $format Format.
	 * @return array|\WP_Error Either array( 'contents' => string ) or array( 'path' => temporary file ).
	 */
	public static function render( array $data, $format ) {
		switch ( $format ) {
			case 'json':
				return array( 'contents' => self::json( $data ) );
			case 'md':
				return array( 'contents' => self::markdown( $data ) );
			case 'csv':
				return array( 'contents' => Csv_Writer::build( self::table_rows( $data ) ) );
			case 'xlsx':
				$path = self::xlsx( $data );
				break;
			case 'docx':
				$path = self::docx( $data );
				break;
			default:
				return new \WP_Error( 'relecture_format', __( 'Unknown export format.', 'relecture' ), array( 'status' => 400 ) );
		}
		return is_wp_error( $path ) ? $path : array( 'path' => $path );
	}

	/**
	 * Sends a review export to the browser and ends the request.
	 *
	 * @param array  $data   Export_Data::review().
	 * @param string $format Format.
	 * @return void
	 */
	public static function send( array $data, $format ) {
		self::deliver( self::render( $data, $format ), self::filename( $data['post']['title'], $format ), self::mime( $format ) );
	}

	/**
	 * Sends a rendered export.
	 *
	 * @param array|\WP_Error $result   Rendered export.
	 * @param string          $filename File name.
	 * @param string          $mime     Content type.
	 * @return void
	 */
	public static function deliver( $result, $filename, $mime ) {
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
		}
		if ( isset( $result['path'] ) ) {
			Download::send_file( $filename, $mime, $result['path'] );
		}
		Download::send_contents( $filename, $mime, $result['contents'] );
	}

	/**
	 * Header and rows of the table (CSV and Excel).
	 *
	 * @param array $data Export_Data::review().
	 * @return array[]
	 */
	public static function table_rows( array $data ) {
		$rows = array(
			array(
				__( 'No.', 'relecture' ),
				__( 'Element', 'relecture' ),
				__( 'Original text', 'relecture' ),
				__( 'Correction', 'relecture' ),
				__( 'Correction status', 'relecture' ),
				__( 'Note', 'relecture' ),
				__( 'Note type', 'relecture' ),
				__( 'Block', 'relecture' ),
				__( 'Path', 'relecture' ),
				__( 'ID', 'relecture' ),
			),
		);
		foreach ( $data['items'] as $item ) {
			$rows[] = array(
				(int) $item['number'],
				self::element_label( $item ),
				$item['original'],
				$item['correction'],
				$item['correction_status_label'],
				$item['note'],
				$item['note_type_label'],
				$item['block_name'],
				$item['block_path'],
				(int) $item['id'],
			);
		}
		return $rows;
	}

	/**
	 * Label of a row, flagged when its text is no longer in the content.
	 *
	 * @param array $item Exported item.
	 * @return string
	 */
	private static function element_label( array $item ) {
		if ( ! empty( $item['orphaned'] ) ) {
			/* translators: %s: element label, e.g. “Paragraph”. */
			return sprintf( __( '%s (removed from the content)', 'relecture' ), $item['label'] );
		}
		return $item['label'];
	}

	/**
	 * Information about the content and the review, as label/value pairs.
	 *
	 * @param array $data Export_Data::review().
	 * @return array[]
	 */
	private static function info_rows( array $data ) {
		$review = $data['review'];
		$stats  = $data['stats'];
		$rows   = array(
			array( __( 'Content', 'relecture' ), $data['post']['title'] ),
			array( __( 'Type', 'relecture' ), $data['post']['type_label'] . ' #' . $data['post']['id'] ),
			array( __( 'Publication status', 'relecture' ), $data['post']['status_label'] ),
			array( __( 'Address', 'relecture' ), $data['post']['link'] ),
			array(
				_x( 'Review', 'noun', 'relecture' ),
				/* translators: 1: round number, 2: review status. */
				sprintf( __( 'Round %1$d · %2$s', 'relecture' ), $review['round'], $review['status_label'] ),
			),
			array( __( 'Started', 'relecture' ), self::when_by( $review['created_at'], $review['created_by'] ) ),
			array( __( 'Last change', 'relecture' ), self::when_by( $review['updated_at'], $review['updated_by'] ) ),
			array( __( 'Rows', 'relecture' ), self::counts( $stats ) ),
			array(
				__( 'Exported', 'relecture' ),
				Export_Data::human_date( $data['generated_at'] ) . ( $data['filters']['only_with_feedback'] ? ' · ' . __( 'only the rows with a correction or a note', 'relecture' ) : '' ),
			),
		);
		if ( $review['outdated'] ) {
			$rows[] = array( __( 'Warning', 'relecture' ), __( 'The content changed after this review started: some original texts may be out of date.', 'relecture' ) );
		}
		if ( $data['references'] ) {
			$rows[] = array(
				__( 'Synced patterns', 'relecture' ),
				implode( ', ', wp_list_pluck( $data['references'], 'title' ) ) . ' — ' . __( 'reviewed separately', 'relecture' ),
			);
		}
		return $rows;
	}

	/**
	 * “31 rows · 2 corrections · 2 notes · 1 guideline”.
	 *
	 * @param array<string, int> $stats Counters.
	 * @return string
	 */
	private static function counts( array $stats ) {
		return implode(
			' · ',
			array(
				/* translators: %d: number of rows. */
				sprintf( _n( '%d row', '%d rows', $stats['segments'], 'relecture' ), $stats['segments'] ),
				/* translators: %d: number of corrections. */
				sprintf( _n( '%d correction', '%d corrections', $stats['corrections'], 'relecture' ), $stats['corrections'] ),
				/* translators: %d: number of notes. */
				sprintf( _n( '%d note', '%d notes', $stats['notes'], 'relecture' ), $stats['notes'] ),
				/* translators: %d: number of guidelines. */
				sprintf( _n( '%d guideline', '%d guidelines', $stats['guidelines'], 'relecture' ), $stats['guidelines'] ),
			)
		);
	}

	/**
	 * “date by name”.
	 *
	 * @param string|null $iso  ISO date.
	 * @param string      $name User name.
	 * @return string
	 */
	private static function when_by( $iso, $name ) {
		$date = Export_Data::human_date( $iso );
		/* translators: 1: date or time, 2: user name. */
		return '' !== $name ? sprintf( __( '%1$s by %2$s', 'relecture' ), $date, $name ) : $date;
	}

	/**
	 * Title of the documents.
	 *
	 * @param array $data Export_Data::review().
	 * @return string
	 */
	private static function title( array $data ) {
		/* translators: %s: title of the reviewed content. */
		return sprintf( __( 'Review: %s', 'relecture' ), $data['post']['title'] );
	}

	/**
	 * JSON document (pretty-printed, UTF-8 kept readable: this is a downloaded file, never
	 * printed inside HTML).
	 *
	 * @param array $data Data.
	 * @return string
	 */
	public static function json( array $data ) {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Excel workbook: the table, then a sheet about the content and the review.
	 *
	 * @param array $data Export_Data::review().
	 * @return string|\WP_Error
	 */
	private static function xlsx( array $data ) {
		return Xlsx_Writer::build(
			array(
				array(
					'name'   => _x( 'Review', 'noun', 'relecture' ),
					'rows'   => self::table_rows( $data ),
					'widths' => array( 6, 18, 60, 60, 16, 45, 26, 18, 10, 8 ),
					'header' => true,
				),
				array(
					'name'   => __( 'About', 'relecture' ),
					'rows'   => self::info_rows( $data ),
					'widths' => array( 22, 100 ),
					'labels' => true,
				),
			),
			array(
				'title'   => self::title( $data ),
				'creator' => $data['site']['name'],
			)
		);
	}

	/**
	 * Word document: title, information, the table, then the guidelines.
	 *
	 * @param array $data Export_Data::review().
	 * @return string|\WP_Error
	 */
	private static function docx( array $data ) {
		$blocks = array(
			array(
				'type'  => 'heading',
				'level' => 1,
				'text'  => self::title( $data ),
			),
		);
		foreach ( self::info_rows( $data ) as $info ) {
			$blocks[] = array(
				'type' => 'paragraph',
				'runs' => array(
					array(
						/* translators: %s: a label such as “Content” or “Corrections”; French typography adds a non-breaking space before the colon. */
						'text' => sprintf( __( '%s:', 'relecture' ), $info[0] ) . ' ',
						'bold' => true,
					),
					array( 'text' => $info[1] ),
				),
			);
		}

		if ( ! $data['items'] ) {
			$blocks[] = array(
				'type' => 'paragraph',
				'runs' => array( array( 'text' => __( 'No row to export.', 'relecture' ) ) ),
			);
		} else {
			$rows = array();
			foreach ( $data['items'] as $item ) {
				$correction = array();
				if ( '' !== $item['correction'] ) {
					$correction[] = array( array( 'text' => $item['correction'] ) );
					if ( '' !== $item['correction_status_label'] ) {
						$correction[] = array(
							array(
								'text'   => $item['correction_status_label'],
								'italic' => true,
								'color'  => '646970',
							),
						);
					}
				}
				$note = array();
				if ( '' !== $item['note'] ) {
					$note[] = array( array( 'text' => $item['note'] ) );
					if ( 'guideline' === $item['note_type'] ) {
						$note[] = array(
							array(
								'text'  => $item['note_type_label'],
								'bold'  => true,
								'color' => '2271B1',
							),
						);
					}
				}
				$rows[] = array(
					array( array( array( 'text' => (string) $item['number'] ) ) ),
					array(
						array(
							array(
								'text'  => self::element_label( $item ),
								'color' => '50575E',
							),
						),
					),
					array( array( array( 'text' => $item['original'] ) ) ),
					$correction,
					$note,
				);
			}
			$blocks[] = array(
				'type'   => 'table',
				'widths' => array( 600, 1700, 4600, 4600, 3898 ),
				'header' => array(
					__( 'No.', 'relecture' ),
					__( 'Element', 'relecture' ),
					__( 'Original text', 'relecture' ),
					__( 'Correction', 'relecture' ),
					__( 'Note', 'relecture' ),
				),
				'rows'   => $rows,
			);
		}

		if ( $data['guidelines'] ) {
			$blocks[] = array(
				'type'  => 'heading',
				'level' => 2,
				'text'  => __( 'Guidelines for future content', 'relecture' ),
			);
			foreach ( $data['guidelines'] as $guideline ) {
				$blocks[] = array(
					'type' => 'paragraph',
					'runs' => array(
						array( 'text' => '• ' . $guideline['note'] . ' ' ),
						array(
							/* translators: %d: row number. */
							'text'   => sprintf( __( '(row %d)', 'relecture' ), $guideline['number'] ),
							'italic' => true,
							'color'  => '646970',
						),
					),
				);
			}
		}

		return Docx_Writer::build(
			$blocks,
			array(
				'title'   => self::title( $data ),
				'creator' => $data['site']['name'],
				'lang'    => str_replace( '_', '-', get_locale() ),
			)
		);
	}

	/**
	 * Markdown document, for people and AIs alike.
	 *
	 * @param array $data Export_Data::review().
	 * @return string
	 */
	private static function markdown( array $data ) {
		$md = '# ' . Markdown_Writer::inline( self::title( $data ) ) . "\n\n";
		foreach ( self::info_rows( $data ) as $info ) {
			/* translators: %s: a label such as “Content” or “Corrections”; French typography adds a non-breaking space before the colon. */
			$md .= '- **' . Markdown_Writer::inline( sprintf( __( '%s:', 'relecture' ), $info[0] ) ) . '** ' . Markdown_Writer::inline( $info[1] ) . "\n";
		}
		$md .= "\n> " . Markdown_Writer::inline( __( 'Each row gives the original text, then the correction and the note when there is one. The block name and its path locate the text in the content.', 'relecture' ) ) . "\n\n";

		$md .= '## ' . Markdown_Writer::inline( __( 'Rows', 'relecture' ) ) . "\n\n";
		if ( ! $data['items'] ) {
			$md .= Markdown_Writer::inline( __( 'No row to export.', 'relecture' ) ) . "\n\n";
		}
		foreach ( $data['items'] as $item ) {
			$md .= '### ' . (int) $item['number'] . '. ' . Markdown_Writer::inline( self::element_label( $item ) );
			if ( '' !== $item['block_name'] ) {
				$md .= ' — `' . str_replace( '`', '', $item['block_name'] ) . '`';
			}
			if ( '' !== $item['block_path'] ) {
				/* translators: %s: index path of a block, e.g. 3.0.1. */
				$md .= ' · ' . sprintf( __( 'path %s', 'relecture' ), '`' . str_replace( '`', '', $item['block_path'] ) . '`' );
			}
			$md .= "\n\n**" . Markdown_Writer::inline( __( 'Original', 'relecture' ) ) . "**\n\n" . Markdown_Writer::fence( $item['original'] ) . "\n\n";
			if ( '' !== $item['correction'] ) {
				$md .= '**' . Markdown_Writer::inline( __( 'Correction', 'relecture' ) ) . '**';
				if ( '' !== $item['correction_status_label'] ) {
					$md .= ' (' . Markdown_Writer::inline( $item['correction_status_label'] ) . ')';
				}
				$md .= "\n\n" . Markdown_Writer::fence( $item['correction'] ) . "\n\n";
			}
			if ( '' !== $item['note'] ) {
				$md .= '**' . Markdown_Writer::inline( __( 'Note', 'relecture' ) ) . '** (' . Markdown_Writer::inline( $item['note_type_label'] ) . ")\n\n"
					. Markdown_Writer::fence( $item['note'] ) . "\n\n";
			}
		}

		if ( $data['guidelines'] ) {
			$md .= '## ' . Markdown_Writer::inline( __( 'Guidelines for future content', 'relecture' ) ) . "\n\n";
			foreach ( $data['guidelines'] as $guideline ) {
				/* translators: %d: row number. */
				$md .= Markdown_Writer::list_item( $guideline['note'] . ' ' . sprintf( __( '(row %d)', 'relecture' ), $guideline['number'] ) ) . "\n";
			}
			$md .= "\n";
		}
		return $md;
	}
}
