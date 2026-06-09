# バージョニング規約

WP Entry Form は **main へのマージごとに自動採番**してリリースします。パッチ番号は手で編集しません（ファイルに書き戻さないため、保護ブランチ運用と両立します）。

## 採番形式

```
MAJOR.MINOR.PATCH      （例: 0.1.0）
```

| 構成要素 | 意味 | 決まり方 |
| --- | --- | --- |
| `MAJOR`（先頭） | 大きな世代（破壊的変更など） | `wp-entry-form.php` の定数 `WPEF_MAJOR_VERSION`。**上げたいときだけ手動で変更**。初期値 `0` |
| `MINOR`（中央） | 機能追加の節目 | `wp-entry-form.php` の定数 `WPEF_MINOR_VERSION`。**上げたいときだけ手動で変更**。初期値 `1` |
| `PATCH`（一番下） | リリースのたびに増える番号 | 同じ `MAJOR.MINOR` の既存タグ数から**自動インクリメント**（初回は `0`） |

- タグ名は接頭辞 `v` 付き（例 `v0.1.0`）。GitHub Release も同名で作成。
- 例: 現行 `MAJOR=0` / `MINOR=1` の場合、最初のリリースは `0.1.0`、次のマージで `0.1.1`、その次は `0.1.2` …と**一番下の数字だけが増えていきます**。
- `MINOR` を 2 に上げると、次のリリースは `0.2.0` から始まり、以降 `0.2.1` …。`MAJOR` を 1 に上げると `1.x.0` から。

## メジャー / マイナーの更新

世代を上げたいときは、その変更を含む PR で `wp-entry-form.php` の定数を手で変更します（PATCH は触りません）。

```php
define( 'WPEF_MAJOR_VERSION', 0 ); // 先頭の数字。破壊的変更などで上げる
define( 'WPEF_MINOR_VERSION', 1 ); // 中央の数字。機能追加の節目で上げる
```

- `MINOR` を `1 → 2` に変更してマージ → 最初のリリースは `0.2.0`。
- `MAJOR` を `0 → 1` に変更してマージ → 最初のリリースは `1.{MINOR}.0`。

これらが唯一の手動操作で、PATCH（一番下）は常に自動です。

## リリースの流れ

1. 機能ブランチで開発（PATCH は触らない）。
2. 世代を上げる場合のみ `WPEF_MAJOR_VERSION` / `WPEF_MINOR_VERSION` を変更。
3. PR を作成し、CI を通して main へマージ。
4. `.github/workflows/release.yml` が起動し、
   - `WPEF_MAJOR_VERSION` / `WPEF_MINOR_VERSION` を読み取り、
   - 同 `MAJOR.MINOR` の既存タグ数から `MAJOR.MINOR.PATCH` を算出、
   - その番号を **ビルド時にプラグインヘッダ `Version:` へ注入**して zip を作成（`bin/build.sh`）、
   - タグを打ち、GitHub Release を作成。

> main マージのたびに必ず一意な番号が振られるため、リリースをスキップする条件はありません。

## ソース上のバージョン表記について

- `wp-entry-form.php` のヘッダ `Version:` は**ローカル開発用の表示値**（現行 `MAJOR.MINOR.0`）です。配布物には自動採番した番号が注入されます。
- `package.json` / `readme.txt` の番号は表示用で、リリース採番には**使いません**（採番は定数 + タグから算出）。

## ローカルでの番号付きビルド（確認用）

```bash
WPEF_BUILD_VERSION=0.1.0 bash bin/build.sh
# dist/wp-entry-form.zip 内の wp-entry-form.php に Version: 0.1.0 が注入される
```
