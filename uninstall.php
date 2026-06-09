<?php
/**
 * アンインストール時のクリーンアップ。
 *
 * 既定はデータ保持。グローバル設定 wpef_settings の delete_data_on_uninstall が
 * true のときだけ、テーブル・オプション・アップロードファイルを削除する（NFR-6）。
 *
 * @package WP_Entry_Form
 */

// WordPress からの正規のアンインストール経由でのみ実行する。
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wpef_settings = get_option( 'wpef_settings', array() );
$wpef_delete   = is_array( $wpef_settings ) && ! empty( $wpef_settings['delete_data_on_uninstall'] );

if ( ! $wpef_delete ) {
	// 既定: 何も削除しない（データ保全）。
	return;
}

global $wpdb;

// テーブル削除。テーブル名は内部生成（ユーザー入力ではない）。
$wpef_tables = array(
	$wpdb->prefix . 'wpef_files',
	$wpdb->prefix . 'wpef_submissions',
	$wpdb->prefix . 'wpef_forms',
);
foreach ( $wpef_tables as $wpef_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpef_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// オプション削除。
delete_option( 'wpef_db_version' );
delete_option( 'wpef_settings' );

// アップロードディレクトリ削除（wp-content/uploads/wp-entry-form/）。
$wpef_upload = wp_upload_dir();
if ( empty( $wpef_upload['error'] ) ) {
	$wpef_dir = trailingslashit( $wpef_upload['basedir'] ) . 'wp-entry-form';
	if ( is_dir( $wpef_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $wpef_dir, true );
		}
	}
}
