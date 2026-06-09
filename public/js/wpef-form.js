/**
 * WP Entry Form フロントの進行的拡張。
 *
 * ビルド不要の素の JS。フォームは JS 無効でも標準 POST で動作する（正路）。
 * ここでは送信時の二重送信防止など軽微な補助のみを行う。
 * 確認画面・AJAX 送信の本格対応は後続 PR で拡張する。
 */
( function () {
	'use strict';

	function onSubmit( event ) {
		var form = event.currentTarget;
		var button = form.querySelector( '.wpef-submit' );
		if ( button ) {
			// 二重送信防止。送信自体はブラウザ標準の POST に任せる。
			window.setTimeout( function () {
				button.setAttribute( 'disabled', 'disabled' );
			}, 0 );
		}
	}

	function init() {
		var forms = document.querySelectorAll( '.wpef-form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			form.addEventListener( 'submit', onSubmit );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
