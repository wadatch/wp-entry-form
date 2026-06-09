<?php
/**
 * インストール／スキーマ管理。
 *
 * 有効化時およびスキーマ版の不一致時に dbDelta() でテーブルを作成・更新する。
 * テーブルは3つ: wpef_forms / wpef_submissions / wpef_files（design.md §3）。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * テーブル作成とスキーマ版管理を担うクラス。
 */
class WPEF_Install {

	/**
	 * スキーマ版。テーブル定義を変更したら +1 する。
	 */
	const DB_VERSION = 1;

	/**
	 * スキーマ版を保存するオプション名。
	 */
	const DB_VERSION_OPTION = 'wpef_db_version';

	/**
	 * 有効化フック。
	 */
	public static function activate() {
		self::install_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * スキーマ版が古ければテーブルを作り直す（自動更新時の保険）。
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $installed === self::DB_VERSION ) {
			return;
		}
		self::install_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * dbDelta でテーブルを作成・更新する。
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$forms           = self::forms_table();
		$submissions     = self::submissions_table();
		$files           = self::files_table();

		// dbDelta は CREATE TABLE 文の体裁に厳格（2スペース区切り、PRIMARY KEY の表記など）。
		$schema = array();

		$schema[] = "CREATE TABLE {$forms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			fields LONGTEXT NULL,
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$submissions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			data LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unread',
			ip_address VARCHAR(100) NULL,
			user_agent TEXT NULL,
			referer TEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$files} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field_key VARCHAR(191) NOT NULL DEFAULT '',
			original_name VARCHAR(255) NULL,
			stored_path VARCHAR(255) NULL,
			mime_type VARCHAR(100) NULL,
			file_size BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY submission_id (submission_id)
		) {$charset_collate};";

		foreach ( $schema as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * フォーム定義テーブル名（プレフィックス付き）。
	 *
	 * @return string
	 */
	public static function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpef_forms';
	}

	/**
	 * 送信データテーブル名（プレフィックス付き）。
	 *
	 * @return string
	 */
	public static function submissions_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpef_submissions';
	}

	/**
	 * 添付ファイルテーブル名（プレフィックス付き）。
	 *
	 * @return string
	 */
	public static function files_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpef_files';
	}
}
