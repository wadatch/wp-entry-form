/**
 * WP Entry Form フロントの進行的拡張。
 *
 * ビルド不要の素の JS。フォームは JS 無効でも標準 POST で動作する（正路・サーバ側で検証）。
 * ここでは入力中・blur 時のリアルタイム検証と、送信時の二重送信防止を補助的に行う。
 * 検証ルールは各 .wpef-field の data-wpef-* 属性（renderer が出力）から読み、
 * メッセージは wpefForm.messages（wp_localize_script）から取得する。
 */
( function () {
	'use strict';

	var MSG = ( window.wpefForm && window.wpefForm.messages ) || {};

	function msg( key ) {
		return MSG[ key ] || '';
	}

	function format( tpl, arg ) {
		return ( tpl || '' ).replace( '%s', arg ).replace( '%d', arg );
	}

	// ラベルの先頭テキスト（[必須] バッジを除く）。
	function fieldLabel( wrap ) {
		var label = wrap.querySelector( '.wpef-label' );
		if ( ! label ) {
			return '';
		}
		var text = '';
		for ( var i = 0; i < label.childNodes.length; i++ ) {
			if ( label.childNodes[ i ].nodeType === 3 ) {
				text += label.childNodes[ i ].textContent;
			}
		}
		return text.trim();
	}

	function fieldValue( wrap, type ) {
		if ( type === 'radio' ) {
			var r = wrap.querySelector( 'input[type=radio]:checked' );
			return r ? r.value : '';
		}
		if ( type === 'checkbox' ) {
			return wrap.querySelector( 'input[type=checkbox]:checked' ) ? 'x' : '';
		}
		if ( type === 'consent' ) {
			var c = wrap.querySelector( 'input[type=checkbox]' );
			return c && c.checked ? '1' : '';
		}
		var el = wrap.querySelector( '.wpef-input' );
		return el ? String( el.value ).trim() : '';
	}

	function validateField( wrap ) {
		var type = wrap.getAttribute( 'data-wpef-type' );
		if ( ! type ) {
			return true;
		}
		var required = wrap.getAttribute( 'data-wpef-required' ) === '1';
		var value = fieldValue( wrap, type );
		var error = '';

		if ( required && value === '' ) {
			error = type === 'consent' ? msg( 'consent' ) : format( msg( 'required' ), fieldLabel( wrap ) );
		} else if ( value !== '' ) {
			if ( type === 'email' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
				error = msg( 'email' );
			} else if ( type === 'url' && ! /^https?:\/\/[^\s]+$/i.test( value ) ) {
				error = msg( 'url' );
			} else if ( type === 'number' ) {
				var num = Number( value );
				var min = wrap.getAttribute( 'data-wpef-min' );
				var max = wrap.getAttribute( 'data-wpef-max' );
				if ( isNaN( num ) ) {
					error = msg( 'number' );
				} else if ( min !== null && min !== '' && num < Number( min ) ) {
					error = format( msg( 'min' ), min );
				} else if ( max !== null && max !== '' && num > Number( max ) ) {
					error = format( msg( 'max' ), max );
				}
			}

			var maxlen = wrap.getAttribute( 'data-wpef-maxlength' );
			if ( ! error && maxlen && value.length > parseInt( maxlen, 10 ) ) {
				error = format( msg( 'maxlength' ), maxlen );
			}
		}

		showError( wrap, error );
		return error === '';
	}

	function showError( wrap, error ) {
		var span = wrap.querySelector( '.wpef-error' );
		var control = wrap.querySelector( '.wpef-input, input, select, textarea' );
		if ( error ) {
			wrap.classList.add( 'wpef-has-error' );
			if ( ! span ) {
				span = document.createElement( 'span' );
				span.className = 'wpef-error';
				span.setAttribute( 'role', 'alert' );
				wrap.appendChild( span );
			}
			span.textContent = error;
			if ( control ) {
				control.setAttribute( 'aria-invalid', 'true' );
			}
		} else {
			wrap.classList.remove( 'wpef-has-error' );
			if ( span ) {
				span.parentNode.removeChild( span );
			}
			if ( control ) {
				control.removeAttribute( 'aria-invalid' );
			}
		}
	}

	function initForm( form ) {
		var fields = form.querySelectorAll( '.wpef-field[data-wpef-type]' );

		Array.prototype.forEach.call( fields, function ( wrap ) {
			var touched = false;
			var controls = wrap.querySelectorAll( 'input, select, textarea' );
			Array.prototype.forEach.call( controls, function ( ctrl ) {
				ctrl.addEventListener( 'blur', function () {
					touched = true;
					validateField( wrap );
				} );
				ctrl.addEventListener( 'change', function () {
					touched = true;
					validateField( wrap );
				} );
				// 一度エラーが出た項目は入力のたびに再検証して即解除する。
				ctrl.addEventListener( 'input', function () {
					if ( touched || wrap.classList.contains( 'wpef-has-error' ) ) {
						validateField( wrap );
					}
				} );
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			var valid = true;
			var firstInvalid = null;
			Array.prototype.forEach.call( fields, function ( wrap ) {
				if ( ! validateField( wrap ) ) {
					valid = false;
					if ( ! firstInvalid ) {
						firstInvalid = wrap;
					}
				}
			} );

			if ( ! valid ) {
				event.preventDefault();
				if ( firstInvalid ) {
					var ctrl = firstInvalid.querySelector( 'input, select, textarea' );
					if ( ctrl ) {
						ctrl.focus();
					}
				}
				return;
			}

			// 二重送信防止（送信自体はブラウザ標準の POST に任せる）。
			var button = form.querySelector( '.wpef-submit' );
			if ( button ) {
				window.setTimeout( function () {
					button.setAttribute( 'disabled', 'disabled' );
				}, 0 );
			}
		} );
	}

	function init() {
		var forms = document.querySelectorAll( '.wpef-form' );
		Array.prototype.forEach.call( forms, initForm );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
