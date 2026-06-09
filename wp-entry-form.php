<?php
/**
 * Plugin Name:       WP Entry Form
 * Plugin URI:        https://github.com/wadatch/wp-entry-form
 * Description:        ボランティア応募などに使える汎用入力フォーム。管理画面でフォームを組み立て、送信データを専用テーブルに保存し、管理者通知と応募者への自動返信を送ります。
 * Version:           0.1.0
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
 * NOTE: 上の Version ヘッダはローカル開発用の表示値（現行 MAJOR.MINOR.0）です。
 *       リリース版の番号は main マージ時に自動採番（MAJOR.MINOR.PATCH）され、
 *       ビルド時にこのヘッダへ注入されます（bin/build.sh）。採番規則は docs/versioning.md を参照。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接アクセス禁止。
}

// バージョン番号「MAJOR.MINOR.PATCH」のうち MAJOR と MINOR を定義する。
// - PATCH（一番下の数字）は main マージのたびにリリース側で自動インクリメントする。
// - MAJOR（先頭）/ MINOR（中央）は、上げたいときだけここを手で変更する（PR 経由）。
define( 'WPEF_MAJOR_VERSION', 0 );
define( 'WPEF_MINOR_VERSION', 1 );

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
	'includes/class-wpef-fields.php',
	'includes/class-wpef-form-state.php',
	'includes/class-wpef-validator.php',
	'includes/class-wpef-renderer.php',
	'includes/class-wpef-shortcode.php',
	'includes/class-wpef-submit-handler.php',
	'includes/class-wpef-mailer.php',
	'includes/class-wpef-files.php',
	'includes/class-wpef-spam.php',
	'includes/class-wpef-block.php',
	'includes/class-wpef-rest.php',
);

// 管理画面でのみ読み込むファイル（WP_List_Table 依存などを front に持ち込まない）。
if ( is_admin() ) {
	$wpef_includes[] = 'admin/class-wpef-admin.php';
	$wpef_includes[] = 'admin/class-wpef-form-builder.php';
	$wpef_includes[] = 'admin/class-wpef-submissions-table.php';
	$wpef_includes[] = 'admin/class-wpef-submissions.php';
}

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

	// フロント埋め込み（ショートコード）。
	if ( class_exists( 'WPEF_Shortcode' ) ) {
		WPEF_Shortcode::init();
	}

	// 送信フロー（admin-post）。
	if ( class_exists( 'WPEF_Submit_Handler' ) ) {
		WPEF_Submit_Handler::init();
	}

	// メール（管理者通知・自動返信）。
	if ( class_exists( 'WPEF_Mailer' ) ) {
		WPEF_Mailer::init();
	}

	// 添付ファイル（ダウンロード・削除連携）。
	if ( class_exists( 'WPEF_Files' ) ) {
		WPEF_Files::init();
	}

	// REST（ブロックエディタ用フォーム一覧）。
	if ( class_exists( 'WPEF_REST' ) ) {
		WPEF_REST::init();
	}

	// Gutenberg ブロック。
	if ( class_exists( 'WPEF_Block' ) ) {
		WPEF_Block::init();
	}

	// 管理画面。
	if ( is_admin() ) {
		if ( class_exists( 'WPEF_Admin' ) ) {
			WPEF_Admin::init();
		}
		if ( class_exists( 'WPEF_Submissions' ) ) {
			WPEF_Submissions::init();
		}
	}
}
add_action( 'plugins_loaded', 'wpef_init' );
