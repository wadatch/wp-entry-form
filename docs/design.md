# WP Entry Form 設計仕様書

| 項目 | 内容 |
| --- | --- |
| 対象バージョン | 0.1.0 |
| 作成日 | 2026-06-09 |
| 関連文書 | [requirements.md](./requirements.md) |
| ステータス | ドラフト（レビュー待ち） |

本書は [要件定義書](./requirements.md) を満たすための技術設計を定める。

---

## 1. 全体アーキテクチャ

レイヤ構成（疎結合・テスト容易性を意識）:

```
┌─────────────────────────────────────────────────────────┐
│ プレゼンテーション                                          │
│  ・フロント: ショートコード / Gutenberg ブロック → Renderer │
│  ・管理: フォームビルダー / 送信一覧（WP_List_Table）        │
├─────────────────────────────────────────────────────────┤
│ アプリケーション                                           │
│  ・Submit_Handler（入力→確認→送信のフロー制御）            │
│  ・Validator / Mailer / Spam ガード                         │
├─────────────────────────────────────────────────────────┤
│ ドメイン / データアクセス                                  │
│  ・WPEF_DB（$wpdb ラッパ: forms / submissions / files）     │
│  ・Fields（フィールド型レジストリ）                         │
└─────────────────────────────────────────────────────────┘
```

設計原則:
- WordPress のコア API（`$wpdb`, `wp_mail`, `dbDelta`, Settings API, `WP_List_Table`, Block API）を最大限利用し、車輪の再発明を避ける。
- プレフィックスは関数・定数・クラスを `WPEF_` / `wpef_`、DB テーブルとオプションを `wpef_` で統一。
- 文字列リテラルは全て `__()` / `esc_html_e()` 等で翻訳対応。

## 2. ディレクトリ構成（予定）

```
wp-entry-form/
├── wp-entry-form.php              # エントリポイント（ヘッダ・定数・ブートストラップ）
├── uninstall.php                  # アンインストール時のクリーンアップ
├── readme.txt                     # WordPress.org 形式の説明
├── docs/
│   ├── requirements.md
│   └── design.md
├── includes/                      # コアロジック（管理/フロント共通）
│   ├── class-wpef-install.php     # 有効化・テーブル作成・DBバージョン管理
│   ├── class-wpef-db.php          # データアクセス層
│   ├── class-wpef-fields.php      # フィールド型レジストリ
│   ├── class-wpef-validator.php   # 入力検証
│   ├── class-wpef-renderer.php    # フォーム/確認画面のHTML生成
│   ├── class-wpef-submit-handler.php # 送信フロー制御（admin-post / ajax）
│   ├── class-wpef-mailer.php      # メール送信
│   ├── class-wpef-spam.php        # ハニーポット・レート制限
│   ├── class-wpef-files.php       # 添付ファイルの保存・取得・削除
│   ├── class-wpef-shortcode.php   # ショートコード
│   ├── class-wpef-block.php       # ブロック登録（サーバ描画）
│   └── class-wpef-rest.php        # ブロックエディタ用 REST（フォーム一覧 等）
├── admin/
│   ├── class-wpef-admin.php           # メニュー登録・画面ルーティング
│   ├── class-wpef-form-builder.php    # フォーム編集（ビルダー）
│   ├── class-wpef-submissions-table.php # 送信一覧（WP_List_Table）
│   ├── css/  js/                      # 管理画面アセット
│   └── views/                         # 画面テンプレート
├── public/
│   ├── css/wpef-form.css
│   └── js/wpef-form.js                # 進行的拡張（確認・AJAX 補助）
├── blocks/entry-form/
│   ├── block.json
│   ├── index.js                       # ビルド不要の素の JS（wp.element 利用）
│   └── render.php                     # サーバ描画
└── languages/                         # 翻訳ファイル
```

## 3. データモデル

`$wpdb->prefix`（既定 `wp_`）を前置。テーブルは `dbDelta()` で作成し、`wpef_db_version` オプションでスキーマ版を管理。

### 3.1 `{prefix}wpef_forms` — フォーム定義

| カラム | 型 | 説明 |
| --- | --- | --- |
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | フォーム ID |
| title | VARCHAR(255) NOT NULL | フォーム名 |
| status | VARCHAR(20) NOT NULL DEFAULT 'active' | active / inactive |
| fields | LONGTEXT | フィールド定義の JSON 配列（§4） |
| settings | LONGTEXT | フォーム設定の JSON（§5） |
| created_at | DATETIME NOT NULL | 作成日時（UTC） |
| updated_at | DATETIME NOT NULL | 更新日時（UTC） |

> フィールド定義を別テーブルに正規化せず JSON 1カラムに持つ。理由: 項目構成が完全に可変で、フォーム単位での読み書きが大半のため。集計・検索は送信データ側で行う。

### 3.2 `{prefix}wpef_submissions` — 送信データ

| カラム | 型 | 説明 |
| --- | --- | --- |
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | 送信 ID |
| form_id | BIGINT UNSIGNED NOT NULL（INDEX） | 対象フォーム |
| data | LONGTEXT | `{field_key: value}` の JSON |
| status | VARCHAR(20) NOT NULL DEFAULT 'unread' | unread / read / spam / trash |
| ip_address | VARCHAR(100) | 送信元 IP |
| user_agent | TEXT | UA 文字列 |
| referer | TEXT | リファラ URL |
| user_id | BIGINT UNSIGNED NULL | ログインユーザー ID |
| created_at | DATETIME NOT NULL（INDEX） | 送信日時（UTC） |

### 3.3 `{prefix}wpef_files` — 添付ファイル

| カラム | 型 | 説明 |
| --- | --- | --- |
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | ファイル ID |
| submission_id | BIGINT UNSIGNED NOT NULL（INDEX） | 紐づく送信 |
| field_key | VARCHAR(191) NOT NULL | どのフィールドの添付か |
| original_name | VARCHAR(255) | 元のファイル名 |
| stored_path | VARCHAR(255) | 保存パス（uploads 配下の相対パス） |
| mime_type | VARCHAR(100) | MIME タイプ |
| file_size | BIGINT UNSIGNED | バイト数 |
| created_at | DATETIME NOT NULL | 保存日時 |

## 4. フィールド定義スキーマ（JSON）

`wpef_forms.fields` は以下要素の配列。

```jsonc
{
  "key":   "your_name",     // データキー（フォーム内で一意・英数とアンダースコア）
  "type":  "text",          // §4.1 の型
  "label": "お名前",
  "required": true,
  "placeholder": "",
  "help":  "",              // 補足説明
  "default": "",
  "options": [              // select / radio / checkbox のみ
    { "label": "平日", "value": "weekday" }
  ],
  "validation": {           // 任意
    "max_length": 255,
    "min": null, "max": null   // number 用
  },
  "file": {                 // type=file のみ
    "max_size_mb": 5,
    "accept": ["pdf", "jpg", "jpeg", "png"]
  }
}
```

### 4.1 対応フィールド型（v0.1）

| type | 入力 | 値の形 | 備考 |
| --- | --- | --- | --- |
| text | 1行テキスト | string | |
| textarea | 複数行テキスト | string | |
| email | メール | string | メール形式検証 |
| tel | 電話番号 | string | |
| url | URL | string | URL 形式検証 |
| number | 数値 | number | min/max |
| date | 日付 | string(YYYY-MM-DD) | |
| select | ドロップダウン | string | options 必須 |
| radio | ラジオ | string | options 必須 |
| checkbox | 複数選択 | string[] | options 必須 |
| consent | 同意チェック | bool | 必須化で同意強制 |
| file | ファイル添付 | files テーブル参照 | 拡張子/MIME/サイズ検証 |
| heading | 見出し（表示のみ） | — | 入力なし |
| paragraph | 説明文（表示のみ） | — | 入力なし |

## 5. フォーム設定スキーマ（JSON）

`wpef_forms.settings`:

```jsonc
{
  "confirmation_screen": true,        // 確認画面の有無（フォームごと）
  "messages": {
    "success": "ご応募ありがとうございました。",
    "submit_button": "送信する",
    "confirm_button": "入力内容を確認",
    "back_button": "戻る"
  },
  "redirect_url": "",                  // 完了後リダイレクト（空なら成功メッセージ表示）
  "admin_notification": {
    "enabled": true,
    "to": ["admin@example.com"],       // 複数可
    "subject": "新しい応募がありました",
    "body": "{your_name} さんから応募がありました。\n\n{all_fields}"
  },
  "autoresponder": {
    "enabled": true,
    "to_field": "email",               // メールアドレスを持つフィールドキー
    "from_name": "",
    "from_email": "",
    "subject": "応募を受け付けました",
    "body": "{your_name} 様\n\nご応募ありがとうございました。"
  },
  "spam": {
    "honeypot": true,
    "rate_limit": { "enabled": true, "max": 3, "window": 600 }  // 600秒で最大3回
  }
}
```

メール本文の差し込み: `{field_key}` を各値に、`{all_fields}` を「ラベル: 値」一覧に置換。

## 6. 送信フロー

### 6.1 確認画面あり（3ステップ）

```
[GET] フォーム表示 (step=input)
  │  応募者が入力して送信
  ▼
[POST step=confirm]
  ├─ nonce 検証 / スパム判定 / バリデーション
  ├─ NG → 入力画面を値保持＋エラーで再表示
  └─ OK → 確認画面表示（値を hidden で保持・新しい nonce 発行）
            │  「戻る」→ step=input で値保持し再表示
            │  「送信」
            ▼
[POST step=submit]
  ├─ nonce 検証 / スパム判定 / バリデーション（再）
  ├─ ファイル保存 → DB 保存 → メール送信
  └─ 完了（redirect_url があれば 303 リダイレクト、なければ成功メッセージ）
```

### 6.2 確認画面なし（2ステップ）

`step=input` の送信を直接 `submit` 相当として処理する。

### 6.3 エンドポイント

| 経路 | アクション | 用途 |
| --- | --- | --- |
| `admin-post.php` | `wpef_submit` / `wpef_submit`（nopriv） | 標準 POST（JS 無効でも動作する正路） |
| `admin-ajax.php` | `wpef_submit` / nopriv | JS 有効時の非同期送信（進行的拡張） |
| REST `wpef/v1/forms` | GET | ブロックエディタ用のフォーム一覧 |

> 添付ファイルを伴う送信は multipart の標準 POST を正路とする。AJAX 化は段階的拡張。

## 7. セキュリティ設計

- **nonce**: フォーム描画時に `wp_nonce_field()`、各ステップで `check_admin_referer` 相当の検証。
- **権限**: 管理画面操作は `current_user_can('manage_options')`。将来 `wpef_manage_forms` / `wpef_view_submissions` のカスタム権限へ差し替え可能にする。
- **サニタイズ/エスケープ**: 入力は型ごとに `sanitize_text_field` / `sanitize_email` / `esc_url_raw` 等。出力は `esc_html` / `esc_attr`。
- **SQL**: 全クエリ `$wpdb->prepare`。
- **ファイル保存**: `wp_upload_dir()` 配下に `wp-entry-form/` を作成し、`index.html` と（Apache 環境向けに）`.htaccess` を設置して直接アクセスを禁止。ファイル名はランダム化。ダウンロードは管理画面経由の権限チェック付きエンドポイントから配信。
- **ファイル検証**: `wp_check_filetype_and_ext()` による拡張子/MIME 検証、許可リスト方式、サイズ上限。

## 8. 管理画面

| 画面 | 内容 |
| --- | --- |
| フォーム一覧 | フォームの一覧・新規作成・複製・削除、各フォームのショートコード表示 |
| フォーム編集（ビルダー） | タイトル、フィールドの追加/編集/並べ替え、設定タブ（基本 / メール / 確認画面 / スパム） |
| 送信一覧 | フォーム別フィルタ、ステータス絞り込み、検索、`WP_List_Table` 表示、CSV エクスポート、一括削除 |
| 送信詳細 | 1件分の全フィールド値・添付・メタ情報、ステータス変更、削除 |
| 設定 | グローバル設定（既定の差出人、アンインストール時のデータ削除可否 など） |

フォームビルダーの項目編集はビルド工程を持たない素の JavaScript（必要なら `wp.element`）で実装し、最終的に隠し入力へ JSON をシリアライズして保存する。

## 9. 拡張ポイント（フック）

| フック | 種別 | 用途 |
| --- | --- | --- |
| `wpef_field_types` | filter | 対応フィールド型の追加 |
| `wpef_validate_submission` | filter | 追加バリデーション（エラー配列を返す） |
| `wpef_before_save_submission` | action | 保存前の加工 |
| `wpef_after_save_submission` | action | 保存後フック（外部連携等） |
| `wpef_admin_notification_email` / `wpef_autoresponder_email` | filter | メール内容（宛先・件名・本文・ヘッダ）の上書き |
| `wpef_is_spam` | filter | スパム判定の上書き |

## 10. 国際化・アンインストール

- text domain `wp-entry-form`、`languages/` に翻訳。
- `uninstall.php`: 設定に応じてテーブル・オプション・アップロードファイルを削除（既定は保持寄り）。

## 11. 未確定・要相談事項

- フォームビルダーの UI を素の JS で作るか、`@wordpress/scripts` のビルド工程を導入するか（後者は React で作りやすいが導入コストあり）。
- CSV の文字コード（Excel 互換のため UTF-8 BOM 付き or Shift_JIS）。
- レート制限の保存先（transient か専用テーブルか）。
- 添付ファイルのウイルススキャン要否。
