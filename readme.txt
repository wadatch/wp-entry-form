=== WP Entry Form ===
Contributors: wadatch
Tags: form, contact form, entry form, submissions, volunteer
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

ボランティア応募などに使える汎用入力フォーム。管理画面でフォームを組み立て、送信データを専用テーブルに保存し、管理者通知と応募者への自動返信を送ります。

== Description ==

WP Entry Form は、ボランティア応募をはじめとする各種「申し込み・応募・問い合わせ」を受け付けるための汎用入力フォーム WordPress プラグインです。既存の Contact Form 系より柔軟にフォームを組み立てられ、送信内容を WordPress の専用テーブルに蓄積します。

* 管理画面のフォームビルダーで項目を自由に定義
* ショートコード `[entry_form id="N"]` と Gutenberg ブロックで埋め込み
* 送信データを専用テーブルに保存（一覧 / 検索 / CSV エクスポート / 手動削除）
* 確認画面（フォームごとに切替）、ファイル添付、同意チェック
* 管理者通知メール + 応募者への自動返信メール
* スパム対策（ハニーポット + 送信レート制限）

== Installation ==

1. プラグインをアップロードして有効化します。
2. 「WP Entry Form」管理メニューからフォームを作成します。
3. ショートコード `[entry_form id="N"]` または Gutenberg ブロックでページに埋め込みます。

== Frequently Asked Questions ==

= 送信データはどこに保存されますか？ =

WordPress のデータベースに作成される専用テーブル（`{prefix}wpef_submissions`）に保存されます。管理画面から一覧・検索・CSV エクスポートできます。

= アンインストールでデータは消えますか？ =

既定では保持します。グローバル設定で「アンインストール時にデータを削除する」を有効にした場合のみ、テーブル・オプション・添付ファイルを削除します。

== Changelog ==

= 0.1.0 =
* 初版（開発中）。
