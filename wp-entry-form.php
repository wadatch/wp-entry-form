<?php
/**
 * Plugin Name:       WP Entry Form
 * Plugin URI:        https://github.com/wadatch/wp-entry-form
 * Description:        ボランティア応募などに使える汎用入力フォーム。管理画面でフォームを組み立て、送信データを専用テーブルに保存し、管理者通知と応募者への自動返信を送ります。
 * Version:           0.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            wadatch
 * Text Domain:       wp-entry-form
 * Domain Path:       /languages
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 *
 * @package WP_Entry_Form
 *
 * NOTE: 上の Version ヘッダ（0.0.0）はローカル開発用のプレースホルダです。
 *       リリース版の番号は main マージ時に自動採番（MAJOR.YYYYMMDD.連番）され、
 *       ビルド時にこのヘッダへ注入されます（bin/build.sh）。採番規則は docs/versioning.md を参照。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接アクセス禁止。
}

// バージョンの MAJOR 番号。採番形式「MAJOR.YYYYMMDD.連番」の先頭に対応する。
// 破壊的変更（後方非互換）を行うときだけ +1 する。変更は通常どおり PR 経由で。
define( 'WPEF_MAJOR_VERSION', 0 );

/*
 * 基本定数。
 * WPEF_VERSION はプラグインヘッダの Version: を採用する（ビルド時に注入される値、
 * ローカルでは 0.0.0 プレースホルダ）。スキーマ版は wpef_db_version オプションで別管理する。
 */
if ( ! function_exists( 'get_file_data' ) ) {
	require_once ABSPATH . 'wp-includes/functions.php';
}
$wpef_plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' );
define( 'WPEF_VERSION', $wpef_plugin_data['Version'] ? $wpef_plugin_data['Version'] : '0.0.0' );
define( 'WPEF_FILE', __FILE__ );
define( 'WPEF_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPEF_URL', plugin_dir_url( __FILE__ ) );
define( 'WPEF_BASENAME', plugin_basename( __FILE__ ) );

/*
 * コアクラスの読み込み。
 * composer は使わず、存在するファイルのみを明示 require する（PR を積み増しても
 * 各時点でプラグインが安全に有効化できるようにするため）。
 */
$wpef_includes = array(
	'includes/class-wpef-install.php',
	'includes/class-wpef-db.php',
);
foreach ( $wpef_includes as $wpef_include ) {
	$wpef_include_path = WPEF_PATH . $wpef_include;
	if ( is_readable( $wpef_include_path ) ) {
		require_once $wpef_include_path;
	}
}
unset( $wpef_includes, $wpef_include, $wpef_include_path, $wpef_plugin_data );

// 有効化時にテーブルを作成・更新する。
register_activation_hook( __FILE__, array( 'WPEF_Install', 'activate' ) );

/**
 * プラグイン初期化。
 *
 * 読み込み済みのプラグインが古いスキーマのままになっていないか毎リクエストで確認し、
 * 必要なら dbDelta を流す（自動更新の入った WordPress では有効化フックが走らないため）。
 */
function wpef_init() {
	load_plugin_textdomain( 'wp-entry-form', false, dirname( WPEF_BASENAME ) . '/languages' );

	if ( class_exists( 'WPEF_Install' ) ) {
		WPEF_Install::maybe_upgrade();
	}
}
add_action( 'plugins_loaded', 'wpef_init' );
