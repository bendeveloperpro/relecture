<?php
/**
 * Sends an export to the browser.
 *
 * @package Relecture
 */

namespace Relecture\Export;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streaming a download: WP_Filesystem has no notion of php://output.

/**
 * File download responses: attachment, no caching, no MIME sniffing.
 *
 * Nothing is written to the uploads folder: exports exist only in the response (and, for
 * Office formats, in a temporary file deleted right after).
 */
final class Download {

	/**
	 * Sends contents held in memory, then ends the request.
	 *
	 * @param string $filename File name offered to the user.
	 * @param string $mime     Content type.
	 * @param string $contents File contents.
	 * @return void
	 */
	public static function send_contents( $filename, $mime, $contents ) {
		self::headers( $filename, $mime, strlen( $contents ) );
		$out = fopen( 'php://output', 'wb' );
		fwrite( $out, $contents );
		fclose( $out );
		exit;
	}

	/**
	 * Sends a temporary file, deletes it, then ends the request.
	 *
	 * @param string $filename File name offered to the user.
	 * @param string $mime     Content type.
	 * @param string $path     Temporary file.
	 * @return void
	 */
	public static function send_file( $filename, $mime, $path ) {
		$size = filesize( $path );
		self::headers( $filename, $mime, false === $size ? null : (int) $size );
		readfile( $path );
		wp_delete_file( $path );
		exit;
	}

	/**
	 * Response headers.
	 *
	 * @param string   $filename File name (UTF-8).
	 * @param string   $mime     Content type.
	 * @param int|null $length   Content length in bytes.
	 * @return void
	 */
	private static function headers( $filename, $mime, $length ) {
		// Whatever another plugin may have buffered would corrupt the file.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$ascii = sanitize_file_name( remove_accents( $filename ) );
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'X-Content-Type-Options: nosniff' );
		if ( null !== $length ) {
			header( 'Content-Length: ' . $length );
		}
	}
}
