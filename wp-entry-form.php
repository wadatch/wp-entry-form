<?php
/**
 * Plugin Name:       WP Entry Form
 * Plugin URI:        https://github.com/wadatch/wp-entry-form
 * Description:        ボランティア応募などに使える汎用入力フォーム。管理画面でフォームを組み立て、送信データを専用テーブルに保存し、管理者通知と応募者への自動返信を送ります。
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            wadatch
 * Text Domain:       wp-entry-form
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package WP_Entry_Form
 *
 * NOTE: 本ファイルはローカル開発環境（wp-env）で有効化できるようにするための最小ブートストラップです。
 *       機能の実装は docs/requirements.md / docs/design.md の承認後に行います。
 *       リリース自動化を有効にする際は、この下に「Version: x.y.z」ヘッダを追加してください
 *       （Version が無い間は .github/workflows/release.yml はリリースを作成しません）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接アクセス禁止。
}
