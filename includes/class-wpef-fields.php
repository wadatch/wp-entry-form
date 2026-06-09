<?php
/**
 * フィールド型レジストリ。
 *
 * v0.1 で対応する14種のフィールド型（design.md §4.1）を定義し、
 * 型ごとのメタ情報（入力を伴うか・複数値か・人間向けラベル）と
 * サニタイズを提供する。型は `wpef_field_types` フィルタで拡張できる。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フィールド型の定義とユーティリティ。
 */
class WPEF_Fields {

	/**
	 * 型定義のキャッシュ。
	 *
	 * @var array|null
	 */
	private static $types = null;

	/**
	 * 対応フィールド型の定義を返す。
	 *
	 * 各定義: label(人間向け名称) / input(値を持つか) / multiple(配列値か) /
	 * has_options(選択肢を持つか) / display(表示専用か)。
	 *
	 * @return array type => 定義 の連想配列。
	 */
	public static function get_types() {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = array(
			'text'      => array( 'label' => __( '1行テキスト', 'wp-entry-form' ), 'input' => true ),
			'textarea'  => array( 'label' => __( '複数行テキスト', 'wp-entry-form' ), 'input' => true ),
			'email'     => array( 'label' => __( 'メール', 'wp-entry-form' ), 'input' => true ),
			'tel'       => array( 'label' => __( '電話番号', 'wp-entry-form' ), 'input' => true ),
			'url'       => array( 'label' => __( 'URL', 'wp-entry-form' ), 'input' => true ),
			'number'    => array( 'label' => __( '数値', 'wp-entry-form' ), 'input' => true ),
			'date'      => array( 'label' => __( '日付', 'wp-entry-form' ), 'input' => true ),
			'select'    => array( 'label' => __( 'ドロップダウン', 'wp-entry-form' ), 'input' => true, 'has_options' => true ),
			'radio'     => array( 'label' => __( 'ラジオボタン', 'wp-entry-form' ), 'input' => true, 'has_options' => true ),
			'checkbox'  => array( 'label' => __( 'チェックボックス（複数）', 'wp-entry-form' ), 'input' => true, 'has_options' => true, 'multiple' => true ),
			'consent'   => array( 'label' => __( '同意チェック', 'wp-entry-form' ), 'input' => true ),
			'file'      => array( 'label' => __( 'ファイル添付', 'wp-entry-form' ), 'input' => true ),
			'heading'   => array( 'label' => __( '見出し', 'wp-entry-form' ), 'input' => false, 'display' => true ),
			'paragraph' => array( 'label' => __( '説明文', 'wp-entry-form' ), 'input' => false, 'display' => true ),
		);

		// 既定値を補完。
		foreach ( $types as $key => $def ) {
			$types[ $key ] = wp_parse_args(
				$def,
				array(
					'label'       => $key,
					'input'       => true,
					'multiple'    => false,
					'has_options' => false,
					'display'     => false,
				)
			);
		}

		/**
		 * 対応フィールド型を拡張する。
		 *
		 * @param array $types type => 定義。
		 */
		self::$types = apply_filters( 'wpef_field_types', $types );

		return self::$types;
	}

	/**
	 * 型が存在するか。
	 *
	 * @param string $type 型キー。
	 * @return bool
	 */
	public static function type_exists( $type ) {
		$types = self::get_types();
		return isset( $types[ $type ] );
	}

	/**
	 * 型の定義を返す（無ければ null）。
	 *
	 * @param string $type 型キー。
	 * @return array|null
	 */
	public static function get_type( $type ) {
		$types = self::get_types();
		return isset( $types[ $type ] ) ? $types[ $type ] : null;
	}

	/**
	 * 値を入力として受け取る型か（heading/paragraph は false）。
	 *
	 * @param string $type 型キー。
	 * @return bool
	 */
	public static function is_input_type( $type ) {
		$def = self::get_type( $type );
		return $def ? ! empty( $def['input'] ) : false;
	}

	/**
	 * 複数値（配列）を持つ型か（checkbox）。
	 *
	 * @param string $type 型キー。
	 * @return bool
	 */
	public static function is_multiple( $type ) {
		$def = self::get_type( $type );
		return $def ? ! empty( $def['multiple'] ) : false;
	}

	/**
	 * 選択肢を持つ型か（select/radio/checkbox）。
	 *
	 * @param string $type 型キー。
	 * @return bool
	 */
	public static function has_options( $type ) {
		$def = self::get_type( $type );
		return $def ? ! empty( $def['has_options'] ) : false;
	}

	/**
	 * フィールド定義を正規化する（欠損キーを既定値で補完）。
	 *
	 * @param array $field 生のフィールド定義。
	 * @return array
	 */
	public static function normalize_field( $field ) {
		$field = wp_parse_args(
			is_array( $field ) ? $field : array(),
			array(
				'key'         => '',
				'type'        => 'text',
				'label'       => '',
				'required'    => false,
				'placeholder' => '',
				'help'        => '',
				'default'     => '',
				'options'     => array(),
				'validation'  => array(),
				'file'        => array(),
			)
		);
		$field['required'] = (bool) $field['required'];
		if ( ! is_array( $field['options'] ) ) {
			$field['options'] = array();
		}
		return $field;
	}

	/**
	 * 送信された生の値を型に応じてサニタイズする。
	 *
	 * file 型はここでは扱わない（アップロードは PR6 のファイル処理で別途）。
	 *
	 * @param array $field 正規化済みフィールド定義。
	 * @param mixed $raw   $_POST 由来の生の値。
	 * @return mixed サニタイズ済みの値（型に応じて string / string[] / bool）。
	 */
	public static function sanitize_value( $field, $raw ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';

		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( (string) self::scalar( $raw ) );

			case 'email':
				return sanitize_email( (string) self::scalar( $raw ) );

			case 'url':
				return esc_url_raw( trim( (string) self::scalar( $raw ) ) );

			case 'number':
				$val = trim( (string) self::scalar( $raw ) );
				return ( '' === $val ) ? '' : $val; // 数値妥当性はバリデータで判定。

			case 'checkbox':
				$values = is_array( $raw ) ? $raw : ( '' === $raw || null === $raw ? array() : array( $raw ) );
				return array_values( array_map( 'sanitize_text_field', array_map( 'strval', $values ) ) );

			case 'consent':
				return ! empty( $raw );

			case 'text':
			case 'tel':
			case 'date':
			case 'select':
			case 'radio':
			default:
				return sanitize_text_field( (string) self::scalar( $raw ) );
		}
	}

	/**
	 * 配列が来たら先頭要素を、それ以外はそのまま返す（スカラ化）。
	 *
	 * @param mixed $raw 値。
	 * @return mixed
	 */
	private static function scalar( $raw ) {
		if ( is_array( $raw ) ) {
			return reset( $raw );
		}
		return $raw;
	}

	/**
	 * 選択肢の value 一覧を返す。
	 *
	 * @param array $field フィールド定義。
	 * @return string[] value の配列。
	 */
	public static function option_values( $field ) {
		$values = array();
		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return $values;
		}
		foreach ( $field['options'] as $opt ) {
			if ( is_array( $opt ) && isset( $opt['value'] ) ) {
				$values[] = (string) $opt['value'];
			}
		}
		return $values;
	}
}
