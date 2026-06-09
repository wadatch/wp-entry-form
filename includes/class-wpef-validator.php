<?php
/**
 * 入力検証。
 *
 * フォーム定義とサニタイズ済みの値から、フィールドキー => エラーメッセージ の配列を返す。
 * 必須・メール形式・数値(min/max)・URL・最大文字数・選択肢の妥当性・同意を検証し、
 * 最後に `wpef_validate_submission` フィルタで追加検証を許す（design.md §9）。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * バリデータ。
 */
class WPEF_Validator {

	/**
	 * 送信値を検証する。
	 *
	 * @param array $form   フォーム（'fields' を含む。WPEF_DB::get_form の返り値）。
	 * @param array $values field_key => サニタイズ済みの値。
	 * @return array field_key => エラーメッセージ（空配列ならエラーなし）。
	 */
	public static function validate( $form, $values ) {
		$errors = array();
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		foreach ( $fields as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			$type  = $field['type'];
			$key   = $field['key'];

			// 表示専用・キー無しはスキップ。
			if ( '' === $key || ! WPEF_Fields::is_input_type( $type ) ) {
				continue;
			}

			$value      = isset( $values[ $key ] ) ? $values[ $key ] : ( WPEF_Fields::is_multiple( $type ) ? array() : '' );
			$is_empty   = self::is_empty( $value );
			$label      = '' !== $field['label'] ? $field['label'] : $key;

			// 同意チェックは必須なら true 必須。
			if ( 'consent' === $type ) {
				if ( $field['required'] && empty( $value ) ) {
					$errors[ $key ] = __( '同意が必要です。', 'wp-entry-form' );
				}
				continue;
			}

			// 必須チェック。
			if ( $field['required'] && $is_empty ) {
				/* translators: %s: フィールドのラベル */
				$errors[ $key ] = sprintf( __( '「%s」は必須です。', 'wp-entry-form' ), $label );
				continue;
			}

			// 空の任意項目はこれ以上検証しない。
			if ( $is_empty ) {
				continue;
			}

			$error = self::validate_value( $field, $value );
			if ( null !== $error ) {
				$errors[ $key ] = $error;
			}
		}

		/**
		 * 追加バリデーション。
		 *
		 * @param array $errors field_key => メッセージ。
		 * @param array $form   フォーム定義。
		 * @param array $values 送信値。
		 */
		$errors = apply_filters( 'wpef_validate_submission', $errors, $form, $values );

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * 単一フィールドの値を型に応じて検証する。
	 *
	 * @param array $field 正規化済みフィールド定義。
	 * @param mixed $value サニタイズ済みの値（非空）。
	 * @return string|null エラーメッセージ、問題なければ null。
	 */
	private static function validate_value( $field, $value ) {
		$type       = $field['type'];
		$validation = is_array( $field['validation'] ) ? $field['validation'] : array();

		switch ( $type ) {
			case 'email':
				if ( ! is_email( $value ) ) {
					return __( 'メールアドレスの形式が正しくありません。', 'wp-entry-form' );
				}
				break;

			case 'url':
				if ( ! wp_http_validate_url( $value ) ) {
					return __( 'URL の形式が正しくありません。', 'wp-entry-form' );
				}
				break;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return __( '数値を入力してください。', 'wp-entry-form' );
				}
				$num = (float) $value;
				if ( isset( $validation['min'] ) && '' !== $validation['min'] && null !== $validation['min'] && $num < (float) $validation['min'] ) {
					/* translators: %s: 最小値 */
					return sprintf( __( '%s 以上の値を入力してください。', 'wp-entry-form' ), $validation['min'] );
				}
				if ( isset( $validation['max'] ) && '' !== $validation['max'] && null !== $validation['max'] && $num > (float) $validation['max'] ) {
					/* translators: %s: 最大値 */
					return sprintf( __( '%s 以下の値を入力してください。', 'wp-entry-form' ), $validation['max'] );
				}
				break;

			case 'select':
			case 'radio':
				if ( ! in_array( (string) $value, WPEF_Fields::option_values( $field ), true ) ) {
					return __( '選択肢から選んでください。', 'wp-entry-form' );
				}
				break;

			case 'checkbox':
				$allowed = WPEF_Fields::option_values( $field );
				foreach ( (array) $value as $v ) {
					if ( ! in_array( (string) $v, $allowed, true ) ) {
						return __( '選択肢から選んでください。', 'wp-entry-form' );
					}
				}
				break;
		}

		// 最大文字数（テキスト系共通）。
		if ( isset( $validation['max_length'] ) && '' !== $validation['max_length'] && null !== $validation['max_length'] ) {
			$max = (int) $validation['max_length'];
			if ( $max > 0 && is_string( $value ) && mb_strlen( $value ) > $max ) {
				/* translators: %d: 最大文字数 */
				return sprintf( __( '%d 文字以内で入力してください。', 'wp-entry-form' ), $max );
			}
		}

		return null;
	}

	/**
	 * 値が空か判定する（配列は要素ゼロ、文字列は trim 後ゼロ長）。
	 *
	 * @param mixed $value 値。
	 * @return bool
	 */
	private static function is_empty( $value ) {
		if ( is_array( $value ) ) {
			return 0 === count( $value );
		}
		return '' === trim( (string) $value );
	}
}
