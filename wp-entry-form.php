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
 * License:           GPL-2.0-or-later
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
