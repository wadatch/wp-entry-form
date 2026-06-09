/**
 * Webpack 設定。
 *
 * @wordpress/scripts の既定設定を継承しつつ、
 * - 複数エントリ（admin = フォームビルダー / block = ブロックエディタ）
 * - 出力先を build/assets/ に変更（bin/build.sh のステージング build/<slug> と衝突させないため）
 * を適用する。各エントリは <name>.js と <name>.asset.php（依存・バージョン）を出力し、
 * PHP 側はその asset.php を使って wp_enqueue_script する。
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
		block: path.resolve( __dirname, 'src/block/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build/assets' ),
	},
};
