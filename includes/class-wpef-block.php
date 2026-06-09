<?php
/**
 * Gutenberg ブロックの登録。
 *
 * エディタ用スクリプト（@wordpress/scripts でビルドした build/assets/block.js）を登録し、
 * block.json のメタデータからブロックを登録する。描画は block.json の render(render.php)で
 * サーバ側に委譲する（design.md §2）。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ブロック登録。
 */
class WPEF_Block {

	/**
	 * エディタスクリプトのハンドル（block.json の editorScript と一致させる）。
	 */
	const EDITOR_HANDLE = 'wpef-entry-form-editor';

	/**
	 * フック登録。
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * エディタスクリプトとブロックを登録する。
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$asset_file = WPEF_PATH . 'build/assets/block.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => WPEF_VERSION,
		);

		wp_register_script(
			self::EDITOR_HANDLE,
			WPEF_URL . 'build/assets/block.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'wp-entry-form', WPEF_PATH . 'languages' );

		register_block_type( WPEF_PATH . 'blocks/entry-form' );
	}
}
