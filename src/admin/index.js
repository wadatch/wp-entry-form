/**
 * フォームビルダー（管理画面）のエントリポイント。
 *
 * PR1 ではビルド工程の確立のためのプレースホルダ。
 * 実体の React 製ビルダー UI は PR3 で実装する。
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

document.addEventListener( 'DOMContentLoaded', () => {
	const mount = document.getElementById( 'wpef-form-builder' );
	if ( ! mount ) {
		return;
	}
	const root = createRoot( mount );
	root.render( __( 'WP Entry Form builder loads here.', 'wp-entry-form' ) );
} );
