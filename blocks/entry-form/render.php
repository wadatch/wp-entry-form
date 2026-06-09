<?php
/**
 * Gutenberg ブロック「WP Entry Form」のサーバ描画。
 *
 * 選択されたフォーム ID をショートコード経由で描画する（表示ロジックを一本化）。
 *
 * @package WP_Entry_Form
 *
 * @var array $attributes ブロック属性（formId）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wpef_form_id = isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0;
if ( ! $wpef_form_id ) {
	return;
}

echo do_shortcode( '[entry_form id="' . $wpef_form_id . '"]' );
