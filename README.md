# WP Entry Form

ボランティア応募などに使える、汎用入力フォームの WordPress プラグイン。既存の Contact Form 系より柔軟にフォームを組み立てられ、送信内容を WordPress の専用テーブルに蓄積します。

- 管理画面のフォームビルダーで項目を自由に定義
- ショートコード `[entry_form id="N"]` と Gutenberg ブロックで埋め込み
- 送信データを専用テーブルに保存（一覧 / 検索 / CSV エクスポート / 手動削除）
- 確認画面（フォームごとに切替）、ファイル添付、同意チェック
- 管理者通知メール + 応募者への自動返信メール
- スパム対策（ハニーポット + 送信レート制限）

## ドキュメント

- [要件定義書](docs/requirements.md)
- [設計仕様書](docs/design.md)
- [開発環境ガイド](docs/development.md)
- [バージョニング規約](docs/versioning.md)

## ローカル開発環境（クイックスタート）

WordPress 公式の wp-env（Docker）を使います。詳細は [開発環境ガイド](docs/development.md) を参照。

```bash
npm install        # @wordpress/env をインストール（要 Node.js 18+）
npm run env:start  # http://localhost:8888 （管理画面 admin / password）
```

## 開発・リリース運用

ソース管理は GitHub リポジトリ <https://github.com/wadatch/wp-entry-form> で行います。

- **`main` への直接コミットは禁止**です。作業ブランチを切って Pull Request 経由でマージしてください。
- PR が `main` にマージされるたびに、GitHub Actions（`.github/workflows/release.yml`）が**自動採番**して配布 zip をビルドし、タグを打ち、GitHub Release を作成します。
  - 採番形式は `MAJOR.YYYYMMDD.連番`（例 `0.20260609.1`）。バージョン番号を手で編集する必要はありません。
  - **破壊的変更のときだけ** `wp-entry-form.php` の `WPEF_MAJOR_VERSION` を +1 してください。詳細は [バージョニング規約](docs/versioning.md)。
- PR には CI（`.github/workflows/ci.yml`）で PHP の構文チェックが走ります。

### ローカルでのビルド

```bash
bash bin/build.sh
# => dist/wp-entry-form.zip （トップレベルに wp-entry-form/ を含む配布物）
```

## 動作環境

- WordPress 6.0 以上
- PHP 7.4 以上

## ライセンス

GPL-2.0-or-later
