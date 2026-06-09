# 開発環境ガイド

ローカル開発には WordPress 公式の [`@wordpress/env`（wp-env）](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) を使います。Docker 上に WordPress 一式を立ち上げ、このリポジトリをプラグインとしてマウント・有効化します。

## 前提ツール

| ツール | 用途 | 確認 |
| --- | --- | --- |
| Docker（Desktop など） | コンテナ実行 | `docker --version` |
| Node.js 18 以上（推奨 20、`.nvmrc` 参照） | wp-env の実行 | `node --version` |

Docker が起動していることを確認してから操作してください。

## セットアップ

```bash
# 依存（@wordpress/env）をインストール
npm install

# WordPress 環境を起動（初回は Docker イメージのダウンロードで数分かかります）
npm run env:start
```

起動後のアクセス先:

| 用途 | URL | 認証 |
| --- | --- | --- |
| サイト | http://localhost:8888 | — |
| 管理画面 | http://localhost:8888/wp-admin | admin / password |
| テスト用サイト | http://localhost:8889 | admin / password |

このリポジトリは `wp-entry-form` プラグインとして自動でマウント・有効化されます（`.wp-env.json` の `plugins: ["."]`）。ソースを編集すると即座に反映されます。

## よく使うコマンド

| コマンド | 内容 |
| --- | --- |
| `npm run env:start` | 起動 |
| `npm run env:stop` | 停止 |
| `npm run env:restart` | 再起動 |
| `npm run env:logs` | ログ表示 |
| `npm run env:clean` | DB を初期化（環境は残す） |
| `npm run env:destroy` | 環境を破棄（ボリューム含む） |
| `npm run env:cli -- <args>` | WP-CLI 実行。例: `npm run env:cli -- plugin list` |

WP-CLI の例:

```bash
# プラグイン一覧
npm run env:cli -- plugin list

# データベースを直接確認（送信テーブルなど）
npm run env:cli -- db query "SHOW TABLES LIKE '%wpef%'"
```

## 設定

- `.wp-env.json` … 共有の環境設定（WordPress/PHP バージョン、ポート、`WP_DEBUG` など）。
- `.wp-env.override.json` … 個人ごとの上書き設定（Git 管理外）。ポート競合時などに利用。

例（ポートを変える場合）`.wp-env.override.json`:

```json
{ "port": 9888, "testsPort": 9889 }
```

- PHP バージョンは既定 `8.2`。最低動作要件は PHP 7.4 のため、互換性確認時は `.wp-env.override.json` で `{"phpVersion": "7.4"}` に切り替えてテストしてください。
- `WP_DEBUG` / `WP_DEBUG_LOG` 有効。デバッグログは wp-env の WordPress 内 `wp-content/debug.log` に出力されます。

## デバッグログの確認

```bash
npm run env:cli -- eval 'echo WP_CONTENT_DIR;'        # ログのパス確認
npm run env:logs                                       # コンテナ標準出力
```

## トラブルシュート

- **ポートが使用中**: `.wp-env.override.json` で `port` / `testsPort` を変更。
- **状態がおかしい**: `npm run env:destroy` → `npm run env:start` でクリーンに作り直す。
- **Docker 未起動**: Docker Desktop 等を起動してから再実行。

## 配布物のビルド（参考）

```bash
npm run build   # = bash bin/build.sh → dist/wp-entry-form.zip
```
