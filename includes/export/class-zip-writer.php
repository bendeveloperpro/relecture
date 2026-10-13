<?php
/**
 * Zip packages for the Office formats.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a zip archive into a temporary file.
 *
 * Uses ZipArchive when PHP has it, otherwise the PclZip library bundled with WordPress,
 * so that Excel and Word exports work on every host. The caller deletes the file.
 */
final class Zip_Writer {

	/**
	 * Creates an archive.
	 *
	 * @param array<string, string> $files Path inside the archive => contents.
	 * @return string|\WP_Error Path of the temporary file.
	 */
	public static function create( array $files ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = wp_tempnam( 'relecture.zip' );
		if ( ! $path ) {
			return new \WP_Error( 'relecture_zip', __( 'Could not create a temporary file for the export.', 'relecture' ) );
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				wp_delete_file( $path );
				return new \WP_Error( 'relecture_zip', __( 'Could not write the export archive.', 'relecture' ) );
			}
			foreach ( $files as $name => $contents ) {
				$zip->addFromString( $name, $contents );
			}
			if ( ! $zip->close() ) {
				wp_delete_file( $path );
				return new \WP_Error( 'relecture_zip', __( 'Could not write the export archive.', 'relecture' ) );
			}
			return $path;
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		wp_delete_file( $path ); // PclZip creates the archive itself.
		$archive = new \PclZip( $path );
		$list    = array();
		foreach ( $files as $name => $contents ) {
			$list[] = array(
				PCLZIP_ATT_FILE_NAME    => $name,
				PCLZIP_ATT_FILE_CONTENT => $contents,
			);
		}
		if ( 0 === $archive->create( $list ) ) {
			wp_delete_file( $path );
			return new \WP_Error( 'relecture_zip', __( 'Could not write the export archive.', 'relecture' ) );
		}
		return $path;
	}
}
