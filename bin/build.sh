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
	--exclude '*.zip' \
	--exclude '.DS_Store' \
	"${ROOT}/" "${STAGE}/"

# 将来フロントエンドのビルド工程を導入したらここで実行する。
# if [ -f "${STAGE}/package.json" ]; then ( cd "${STAGE}" && npm ci && npm run build ); fi

( cd "${BUILD_DIR}" && zip -rq "${DIST_DIR}/${SLUG}.zip" "${SLUG}" )

echo "Built ${DIST_DIR}/${SLUG}.zip"
