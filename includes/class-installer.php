<?php
/**
 * Database schema.
 *
 * @package Relecture
 */

namespace Relecture;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 */
final class Installer {

	/**
	 * Option holding the installed schema version.
	 */
	const OPTION = 'relecture_db_version';

	/**
	 * Creates or upgrades the tables of the current site.
	 *
	 * Also runs through maybe_upgrade() on every load, so that plugin updates (which do
	 * not fire the activation hook) and sites of a network get their tables too.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$reviews         = Repository::reviews_table();
		$items           = Repository::items_table();

		// dbDelta() is picky: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$reviews} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  content_hash char(64) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  synced_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_status (post_id,status)
) {$charset_collate};
CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  review_id bigint(20) unsigned NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  segment_type varchar(30) NOT NULL DEFAULT 'block',
  block_name varchar(191) NOT NULL DEFAULT '',
  block_path varchar(191) NOT NULL DEFAULT '',
  meta text NULL,
  fingerprint char(40) NOT NULL DEFAULT '',
  original longtext NOT NULL,
  original_html longtext NULL,
  correction longtext NULL,
  correction_status varchar(20) NOT NULL DEFAULT '',
  note longtext NULL,
  note_type varchar(20) NOT NULL DEFAULT 'post',
  guideline_status varchar(20) NOT NULL DEFAULT 'new',
  orphaned tinyint(1) unsigned NOT NULL DEFAULT 0,
  version int(10) unsigned NOT NULL DEFAULT 0,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY review_position (review_id,position),
  KEY guidelines (note_type,guideline_status)
) {$charset_collate};"
		);

		update_option( self::OPTION, RELECTURE_DB_VERSION );
	}

	/**
	 * Installs or upgrades the schema when its version changed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== RELECTURE_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Drops the plugin's tables together with a deleted site of a network.
	 *
	 * @param string[] $tables Tables WordPress is about to drop.
	 * @return string[]
	 */
	public static function drop_tables_with_site( $tables ) {
		$tables[] = Repository::reviews_table();
		$tables[] = Repository::items_table();
		return $tables;
	}
}
