#!/usr/bin/env bash
#
# WP Entry Form を配布用 zip にパッケージする。
# 成果物: dist/wp-entry-form.zip （トップレベルに wp-entry-form/ ディレクトリを持つ）
#
# フロントエンド（React / @wordpress/scripts）のアセットを build/assets/ にビルドしてから
# 配布物に同梱する。ステージング先は dist/stage/ に置き、wp-scripts の出力 build/ と衝突させない。
#
set -euo pipefail

SLUG="wp-entry-form"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT}/dist"
STAGE_PARENT="${DIST_DIR}/stage"
STAGE="${STAGE_PARENT}/${SLUG}"

# 1) フロントエンドアセットをビルド（build/assets/<name>.js と <name>.asset.php を生成）。
#    クリーンな環境（CI 等）では依存が無いので npm ci で再現性高くインストールする。
if [ ! -d "${ROOT}/node_modules" ]; then
	( cd "${ROOT}" && npm ci )
fi
( cd "${ROOT}" && npm run build:assets )

# 2) ステージングを作り直す（build/assets は消さない）。
rm -rf "${DIST_DIR}"
mkdir -p "${STAGE}"

# 3) 配布物に含めない開発用ファイルを除外してコピー。
#    build/assets はビルド成果物として含める（'build/' は除外しない）。src/ と webpack 設定は除外。
rsync -a \
	--exclude '.git/' \
	--exclude '.github/' \
	--exclude 'docs/' \
	--exclude 'bin/' \
	--exclude 'dist/' \
	--exclude 'node_modules/' \
	--exclude 'src/' \
	--exclude 'tests/' \
	--exclude 'vendor/bin/' \
	--exclude 'webpack.config.js' \
	--exclude '.gitignore' \
	--exclude '.editorconfig' \
	--exclude '.nvmrc' \
	--exclude '.wp-env.json' \
	--exclude '.wp-env.override.json' \
	--exclude 'package.json' \
	--exclude 'package-lock.json' \
	--exclude '*.zip' \
	--exclude '.DS_Store' \
	"${ROOT}/" "${STAGE}/"

# 4) リリースビルド時はバージョンをプラグインヘッダ／readme.txt へ注入する（採番規則: docs/versioning.md）。
#    WPEF_BUILD_VERSION が未設定（ローカルでの手動ビルド等）なら 0.0.0 プレースホルダのまま。
VERSION="${WPEF_BUILD_VERSION:-}"
if [ -n "${VERSION}" ]; then
	MAIN="${STAGE}/wp-entry-form.php"
	# ヘッダの "Version:" 行を置換（BSD/GNU sed 双方で動くよう [[:space:]] を使用）。
	sed -i.bak -E "s|^([[:space:]]*\*[[:space:]]*Version:)[[:space:]]*.*|\1           ${VERSION}|" "${MAIN}"
	rm -f "${MAIN}.bak"

	README="${STAGE}/readme.txt"
	if [ -f "${README}" ]; then
		sed -i.bak -E "s|^(Stable tag:)[[:space:]]*.*|\1 ${VERSION}|" "${README}"
		rm -f "${README}.bak"
	fi
	echo "Injected Version: ${VERSION}"
fi

# 5) zip 化。
( cd "${STAGE_PARENT}" && zip -rq "${DIST_DIR}/${SLUG}.zip" "${SLUG}" )

# ステージングの中間ファイルは片付け、zip だけ残す。
rm -rf "${STAGE_PARENT}"

echo "Built ${DIST_DIR}/${SLUG}.zip"
