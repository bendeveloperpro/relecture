<?php
/**
 * Plugin Name:       Relecture
 * Plugin URI:        https://github.com/bendeveloperpro/relecture
 * Description:       Proofread, correct and annotate posts and pages in a three-column table, then export the review to Excel, CSV, Word, Markdown or JSON.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            BenDev
 * Author URI:        https://bendev.fr/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       relecture
 * Domain Path:       /languages
 *
 * Relecture never changes the reviewed content: it stores a snapshot of the text, the
 * corrections and the notes in its own tables, and hands them over through exports, the
 * REST API and WP-CLI. Applying the corrections stays a human (or AI) decision.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

define( 'RELECTURE_VERSION', '0.1.0' );
define( 'RELECTURE_DB_VERSION', '1' );
define( 'RELECTURE_FILE', __FILE__ );
define( 'RELECTURE_DIR', plugin_dir_path( __FILE__ ) );
define( 'RELECTURE_URL', plugin_dir_url( __FILE__ ) );

require_once RELECTURE_DIR . 'includes/class-text.php';
require_once RELECTURE_DIR . 'includes/class-labels.php';
require_once RELECTURE_DIR . 'includes/class-permissions.php';
require_once RELECTURE_DIR . 'includes/class-repository.php';
require_once RELECTURE_DIR . 'includes/class-installer.php';
require_once RELECTURE_DIR . 'includes/class-html-fragment.php';
require_once RELECTURE_DIR . 'includes/class-extractor.php';
require_once RELECTURE_DIR . 'includes/class-review-service.php';
require_once RELECTURE_DIR . 'includes/export/class-export-data.php';
require_once RELECTURE_DIR . 'includes/export/class-download.php';
require_once RELECTURE_DIR . 'includes/export/class-zip-writer.php';
require_once RELECTURE_DIR . 'includes/export/class-ooxml.php';
require_once RELECTURE_DIR . 'includes/export/class-csv-writer.php';
require_once RELECTURE_DIR . 'includes/export/class-xlsx-writer.php';
require_once RELECTURE_DIR . 'includes/export/class-docx-writer.php';
require_once RELECTURE_DIR . 'includes/export/class-markdown-writer.php';
require_once RELECTURE_DIR . 'includes/export/class-exporter.php';
require_once RELECTURE_DIR . 'includes/export/class-guidelines-exporter.php';
require_once RELECTURE_DIR . 'includes/class-rest-controller.php';
require_once RELECTURE_DIR . 'includes/class-plugin.php';

if ( is_admin() ) {
	require_once RELECTURE_DIR . 'includes/admin/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once RELECTURE_DIR . 'includes/class-cli-command.php';
}

register_activation_hook( __FILE__, array( Installer::class, 'install' ) );

Plugin::boot();
