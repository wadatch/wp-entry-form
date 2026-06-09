#!/usr/bin/env bash
#
# WP Entry Form を配布用 zip にパッケージする。
# 成果物: dist/wp-entry-form.zip （トップレベルに wp-entry-form/ ディレクトリを持つ）
#
set -euo pipefail

SLUG="wp-entry-form"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT}/build"
DIST_DIR="${ROOT}/dist"
STAGE="${BUILD_DIR}/${SLUG}"

rm -rf "${BUILD_DIR}" "${DIST_DIR}"
mkdir -p "${STAGE}" "${DIST_DIR}"

# 配布物に含めない開発用ファイルを除外してコピー。
rsync -a \
	--exclude '.git/' \
	--exclude '.github/' \
	--exclude 'docs/' \
	--exclude 'bin/' \
	--exclude 'build/' \
	--exclude 'dist/' \
	--exclude 'node_modules/' \
	--exclude 'tests/' \
	--exclude 'vendor/bin/' \
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

# リリースビルド時はバージョンをプラグインヘッダへ注入する（採番規則: docs/versioning.md）。
# WPEF_BUILD_VERSION が未設定（ローカルでの手動ビルド等）なら 0.0.0 プレースホルダのまま。
VERSION="${WPEF_BUILD_VERSION:-}"
if [ -n "${VERSION}" ]; then
	MAIN="${STAGE}/wp-entry-form.php"
	# ヘッダの "Version:" 行を置換（BSD/GNU sed 双方で動くよう [[:space:]] を使用）。
	sed -i.bak -E "s|^([[:space:]]*\*[[:space:]]*Version:)[[:space:]]*.*|\1           ${VERSION}|" "${MAIN}"
	rm -f "${MAIN}.bak"
	echo "Injected Version: ${VERSION}"
fi

# 将来フロントエンドのビルド工程を導入したらここで実行する（自己参照を避けるため build:assets を想定）。
# if [ -f "${STAGE}/package.json" ]; then ( cd "${STAGE}" && npm ci && npm run build:assets ); fi

( cd "${BUILD_DIR}" && zip -rq "${DIST_DIR}/${SLUG}.zip" "${SLUG}" )

echo "Built ${DIST_DIR}/${SLUG}.zip"
