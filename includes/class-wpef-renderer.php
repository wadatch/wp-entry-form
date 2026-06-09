<?php
/**
 * フォーム HTML の生成。
 *
 * 14種のフィールド型を描画し、ラベルと入力の関連付け（for/id）、必須表示、
 * エラー表示（role="alert" + aria-describedby）、入力値の保持、ハニーポット、nonce を備える。
 * 送信フロー（確認・送信）は PR4 で submit-handler が利用する。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フロントのフォーム描画。
 */
class WPEF_Renderer {

	/**
	 * フォーム全体を描画する。
	 *
	 * @param array $form    フォーム（WPEF_DB::get_form の返り値: id/title/fields/settings 等）。
	 * @param array $context 再描画用の任意コンテキスト。
	 *                       - values: field_key => 値（入力保持）
	 *                       - errors: field_key => エラーメッセージ
	 *                       - step:   input / confirm（PR4 で使用、既定 input）
	 * @return string HTML。
	 */
	public static function render_form( $form, $context = array() ) {
		$form_id  = isset( $form['id'] ) ? (int) $form['id'] : 0;
		$fields   = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();

		$values = isset( $context['values'] ) && is_array( $context['values'] ) ? $context['values'] : array();
		$errors = isset( $context['errors'] ) && is_array( $context['errors'] ) ? $context['errors'] : array();
		$return = isset( $context['return'] ) ? $context['return'] : '';

		$messages       = isset( $settings['messages'] ) && is_array( $settings['messages'] ) ? $settings['messages'] : array();
		$submit_label   = isset( $messages['submit_button'] ) && '' !== $messages['submit_button'] ? $messages['submit_button'] : __( '送信する', 'wp-entry-form' );
		$honeypot_on    = ! isset( $settings['spam']['honeypot'] ) || ! empty( $settings['spam']['honeypot'] );

		$out  = '';
		$out .= '<form class="wpef-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate>';

		// エラーサマリ（あれば）。
		if ( ! empty( $errors ) ) {
			$out .= '<div class="wpef-form-errors" role="alert">' . esc_html__( '入力内容に誤りがあります。各項目をご確認ください。', 'wp-entry-form' ) . '</div>';
		}

		// 各フィールド（横並び可能なグリッド内に配置）。
		$out .= '<div class="wpef-grid">';
		foreach ( $fields as $raw_field ) {
			$out .= self::render_field( $raw_field, $form_id, $values, $errors );
		}
		$out .= '</div>';

		// ハニーポット（人間には見えないダミー項目）。
		if ( $honeypot_on ) {
			$out .= self::render_honeypot( $form_id );
		}

		// 制御用 hidden と nonce。
		$out .= '<input type="hidden" name="action" value="wpef_submit" />';
		$out .= '<input type="hidden" name="wpef_form_id" value="' . esc_attr( $form_id ) . '" />';
		$out .= '<input type="hidden" name="wpef_step" value="input" />';
		$out .= '<input type="hidden" name="wpef_return" value="' . esc_url( $return ) . '" />';
		$out .= wp_nonce_field( 'wpef_submit_' . $form_id, 'wpef_nonce', true, false );

		$out .= '<div class="wpef-actions">';
		$out .= '<button type="submit" class="wpef-submit">' . esc_html( $submit_label ) . '</button>';
		$out .= '</div>';

		$out .= '</form>';

		return $out;
	}

	/**
	 * 確認画面を描画する（入力値を読み取り表示 + hidden で保持）。
	 *
	 * @param array $form    フォーム。
	 * @param array $values  サニタイズ済みの入力値。
	 * @param array $context return を含む任意コンテキスト。
	 * @return string
	 */
	public static function render_confirmation( $form, $values, $context = array() ) {
		$form_id  = isset( $form['id'] ) ? (int) $form['id'] : 0;
		$fields   = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();
		$messages = isset( $settings['messages'] ) && is_array( $settings['messages'] ) ? $settings['messages'] : array();
		$return   = isset( $context['return'] ) ? $context['return'] : '';

		$submit_label = isset( $messages['submit_button'] ) && '' !== $messages['submit_button'] ? $messages['submit_button'] : __( '送信する', 'wp-entry-form' );
		$back_label   = isset( $messages['back_button'] ) && '' !== $messages['back_button'] ? $messages['back_button'] : __( '戻る', 'wp-entry-form' );

		$out  = '<form class="wpef-form wpef-confirm" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<dl class="wpef-confirm-list">';

		foreach ( $fields as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			$type  = $field['type'];
			if ( '' === $field['key'] || ! WPEF_Fields::is_input_type( $type ) || 'file' === $type ) {
				continue;
			}
			$value = isset( $values[ $field['key'] ] ) ? $values[ $field['key'] ] : '';
			$out  .= '<dt class="wpef-confirm-label">' . esc_html( '' !== $field['label'] ? $field['label'] : $field['key'] ) . '</dt>';
			$out  .= '<dd class="wpef-confirm-value">' . self::format_value( $field, $value ) . '</dd>';
			// 値を hidden で保持（送信ステップへ引き継ぐ）。
			$out  .= self::hidden_values( $field['key'], $value );
		}

		$out .= '</dl>';

		$out .= '<input type="hidden" name="action" value="wpef_submit" />';
		$out .= '<input type="hidden" name="wpef_form_id" value="' . esc_attr( $form_id ) . '" />';
		$out .= '<input type="hidden" name="wpef_step" value="submit" />';
		$out .= '<input type="hidden" name="wpef_return" value="' . esc_url( $return ) . '" />';
		$out .= wp_nonce_field( 'wpef_submit_' . $form_id, 'wpef_nonce', true, false );

		$out .= '<div class="wpef-actions">';
		$out .= '<button type="submit" name="wpef_back" value="1" class="wpef-back">' . esc_html( $back_label ) . '</button> ';
		$out .= '<button type="submit" class="wpef-submit">' . esc_html( $submit_label ) . '</button>';
		$out .= '</div>';

		$out .= '</form>';

		return $out;
	}

	/**
	 * 確認画面の値表示用に、型に応じて人が読める文字列へ整形する。
	 *
	 * @param array $field フィールド。
	 * @param mixed $value 値。
	 * @return string エスケープ済み HTML。
	 */
	private static function format_value( $field, $value ) {
		if ( 'consent' === $field['type'] ) {
			return esc_html( ! empty( $value ) ? __( '同意する', 'wp-entry-form' ) : __( '同意しない', 'wp-entry-form' ) );
		}
		if ( is_array( $value ) ) {
			$labels = array_map(
				function ( $v ) use ( $field ) {
					return self::option_label( $field, (string) $v );
				},
				$value
			);
			return esc_html( implode( '、', $labels ) );
		}
		if ( WPEF_Fields::has_options( $field['type'] ) ) {
			return esc_html( self::option_label( $field, (string) $value ) );
		}
		if ( 'textarea' === $field['type'] ) {
			return nl2br( esc_html( (string) $value ) );
		}
		return esc_html( (string) $value );
	}

	/**
	 * 選択肢の value に対応するラベルを返す（無ければ value 自身）。
	 *
	 * @param array  $field フィールド。
	 * @param string $value 値。
	 * @return string
	 */
	private static function option_label( $field, $value ) {
		foreach ( self::options( $field ) as $opt ) {
			if ( $opt['value'] === $value ) {
				return $opt['label'];
			}
		}
		return $value;
	}

	/**
	 * 値を hidden input として出力する（配列は複数 hidden）。
	 *
	 * @param string $key   フィールドキー。
	 * @param mixed  $value 値。
	 * @return string
	 */
	private static function hidden_values( $key, $value ) {
		if ( is_array( $value ) ) {
			$out = '';
			foreach ( $value as $v ) {
				$out .= '<input type="hidden" name="' . esc_attr( 'wpef[' . $key . '][]' ) . '" value="' . esc_attr( (string) $v ) . '" />';
			}
			return $out;
		}
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '';
		}
		return '<input type="hidden" name="' . esc_attr( 'wpef[' . $key . ']' ) . '" value="' . esc_attr( (string) $value ) . '" />';
	}

	/**
	 * 単一フィールドを描画する。
	 *
	 * @param array $raw_field フィールド定義。
	 * @param int   $form_id   フォーム ID。
	 * @param array $values    入力保持値。
	 * @param array $errors    エラー配列。
	 * @return string
	 */
	private static function render_field( $raw_field, $form_id, $values, $errors ) {
		$field = WPEF_Fields::normalize_field( $raw_field );
		$type  = $field['type'];

		if ( ! WPEF_Fields::type_exists( $type ) ) {
			return '';
		}

		$width = self::width_class( $field );

		// 表示専用要素。
		if ( 'heading' === $type ) {
			return '<h3 class="wpef-heading ' . esc_attr( $width ) . '">' . esc_html( $field['label'] ) . '</h3>';
		}
		if ( 'paragraph' === $type ) {
			return '<div class="wpef-paragraph ' . esc_attr( $width ) . '">' . wp_kses_post( wpautop( $field['label'] ) ) . '</div>';
		}

		$key = $field['key'];
		if ( '' === $key ) {
			return '';
		}

		$id        = self::field_id( $form_id, $key );
		$value     = array_key_exists( $key, $values ) ? $values[ $key ] : $field['default'];
		$error     = isset( $errors[ $key ] ) ? $errors[ $key ] : '';
		$has_error = '' !== $error;
		$describe  = array();
		if ( '' !== $field['help'] ) {
			$describe[] = $id . '-help';
		}
		if ( $has_error ) {
			$describe[] = $id . '-error';
		}

		$wrap_class = 'wpef-field wpef-field-' . sanitize_html_class( $type ) . ' ' . $width;
		if ( $has_error ) {
			$wrap_class .= ' wpef-has-error';
		}

		// クライアント側のリアルタイム検証用メタ（JS が読む。サーバ側検証は別途実施）。
		$data  = ' data-wpef-type="' . esc_attr( $type ) . '"';
		$data .= $field['required'] ? ' data-wpef-required="1"' : '';
		$validation = is_array( $field['validation'] ) ? $field['validation'] : array();
		if ( isset( $validation['max_length'] ) && '' !== $validation['max_length'] && null !== $validation['max_length'] ) {
			$data .= ' data-wpef-maxlength="' . esc_attr( (int) $validation['max_length'] ) . '"';
		}
		if ( isset( $validation['min'] ) && '' !== $validation['min'] && null !== $validation['min'] ) {
			$data .= ' data-wpef-min="' . esc_attr( $validation['min'] ) . '"';
		}
		if ( isset( $validation['max'] ) && '' !== $validation['max'] && null !== $validation['max'] ) {
			$data .= ' data-wpef-max="' . esc_attr( $validation['max'] ) . '"';
		}

		$out = '<div class="' . esc_attr( $wrap_class ) . '"' . $data . '>';

		// consent は単一チェックでラベルを後置するため別扱い。
		if ( 'consent' === $type ) {
			$out .= self::render_consent( $field, $id, $value, $describe );
		} else {
			$out .= self::render_label( $field, $id, $type );
			$out .= self::render_control( $field, $id, $value, $describe, $has_error );
		}

		if ( '' !== $field['help'] ) {
			$out .= '<span class="wpef-help" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $field['help'] ) . '</span>';
		}
		if ( $has_error ) {
			$out .= '<span class="wpef-error" id="' . esc_attr( $id . '-error' ) . '" role="alert">' . esc_html( $error ) . '</span>';
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 * ラベルを描画する。radio/checkbox はグループなので legend を別途使う。
	 *
	 * @param array  $field フィールド。
	 * @param string $id    入力 ID。
	 * @param string $type  型。
	 * @return string
	 */
	private static function render_label( $field, $id, $type ) {
		$required = $field['required'] ? ' ' . self::required_badge() : '';
		// グループ系は <label for> ではなく見出しとして扱う（実際の関連付けは fieldset/legend）。
		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) {
			return '<span class="wpef-label">' . esc_html( $field['label'] ) . $required . '</span>';
		}
		return '<label class="wpef-label" for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $required . '</label>';
	}

	/**
	 * 入力コントロールを描画する。
	 *
	 * @param array  $field     フィールド。
	 * @param string $id        ID。
	 * @param mixed  $value     値。
	 * @param array  $describe  aria-describedby に入れる ID 配列。
	 * @param bool   $has_error エラー有無。
	 * @return string
	 */
	private static function render_control( $field, $id, $value, $describe, $has_error ) {
		$type = $field['type'];
		$key  = $field['key'];
		$name = 'wpef[' . $key . ']';

		$attrs  = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
		$attrs .= $field['required'] ? ' aria-required="true"' : '';
		$attrs .= $has_error ? ' aria-invalid="true"' : '';
		if ( ! empty( $describe ) ) {
			$attrs .= ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"';
		}
		$placeholder = '' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';

		switch ( $type ) {
			case 'textarea':
				return '<textarea class="wpef-input"' . $attrs . $placeholder . ' rows="5">' . esc_textarea( (string) $value ) . '</textarea>';

			case 'select':
				$html  = '<select class="wpef-input"' . $attrs . '>';
				$html .= '<option value="">' . esc_html__( '選択してください', 'wp-entry-form' ) . '</option>';
				foreach ( self::options( $field ) as $opt ) {
					$html .= '<option value="' . esc_attr( $opt['value'] ) . '"' . selected( (string) $value, $opt['value'], false ) . '>' . esc_html( $opt['label'] ) . '</option>';
				}
				$html .= '</select>';
				return $html;

			case 'radio':
				$html = '<div class="wpef-options" role="radiogroup">';
				foreach ( self::options( $field ) as $i => $opt ) {
					$oid   = $id . '-' . $i;
					$html .= '<label class="wpef-option" for="' . esc_attr( $oid ) . '">';
					$html .= '<input type="radio" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt['value'] ) . '"' . checked( (string) $value, $opt['value'], false ) . ( $field['required'] ? ' aria-required="true"' : '' ) . ' /> ';
					$html .= esc_html( $opt['label'] ) . '</label>';
				}
				$html .= '</div>';
				return $html;

			case 'checkbox':
				$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();
				$html     = '<div class="wpef-options">';
				foreach ( self::options( $field ) as $i => $opt ) {
					$oid       = $id . '-' . $i;
					$is_checked = in_array( (string) $opt['value'], $selected, true );
					$html     .= '<label class="wpef-option" for="' . esc_attr( $oid ) . '">';
					$html     .= '<input type="checkbox" id="' . esc_attr( $oid ) . '" name="' . esc_attr( 'wpef[' . $key . '][]' ) . '" value="' . esc_attr( $opt['value'] ) . '"' . checked( $is_checked, true, false ) . ' /> ';
					$html     .= esc_html( $opt['label'] ) . '</label>';
				}
				$html .= '</div>';
				return $html;

			case 'file':
				$accept = '';
				if ( ! empty( $field['file']['accept'] ) && is_array( $field['file']['accept'] ) ) {
					$accept = ' accept="' . esc_attr( '.' . implode( ',.', array_map( 'sanitize_key', $field['file']['accept'] ) ) ) . '"';
				}
				return '<input type="file" class="wpef-input"' . $attrs . $accept . ' />';

			case 'number':
				$min = isset( $field['validation']['min'] ) && '' !== $field['validation']['min'] && null !== $field['validation']['min'] ? ' min="' . esc_attr( $field['validation']['min'] ) . '"' : '';
				$max = isset( $field['validation']['max'] ) && '' !== $field['validation']['max'] && null !== $field['validation']['max'] ? ' max="' . esc_attr( $field['validation']['max'] ) . '"' : '';
				return '<input type="number" class="wpef-input"' . $attrs . $placeholder . $min . $max . ' value="' . esc_attr( (string) $value ) . '" />';

			case 'email':
			case 'tel':
			case 'url':
			case 'date':
			case 'text':
			default:
				$input_type = in_array( $type, array( 'email', 'tel', 'url', 'date' ), true ) ? $type : 'text';
				return '<input type="' . esc_attr( $input_type ) . '" class="wpef-input"' . $attrs . $placeholder . ' value="' . esc_attr( (string) $value ) . '" />';
		}
	}

	/**
	 * 同意チェック（単一チェックボックス + 後置ラベル）。
	 *
	 * @param array  $field    フィールド。
	 * @param string $id       ID。
	 * @param mixed  $value    値。
	 * @param array  $describe describedby ID。
	 * @return string
	 */
	private static function render_consent( $field, $id, $value, $describe ) {
		$name     = 'wpef[' . $field['key'] . ']';
		$required = $field['required'] ? ' ' . self::required_badge() : '';
		$attrs    = $field['required'] ? ' aria-required="true"' : '';
		if ( ! empty( $describe ) ) {
			$attrs .= ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"';
		}
		$html  = '<label class="wpef-consent" for="' . esc_attr( $id ) . '">';
		$html .= '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $value ), true, false ) . $attrs . ' /> ';
		$html .= esc_html( $field['label'] ) . $required . '</label>';
		return $html;
	}

	/**
	 * ハニーポット項目。CSS で隠し、ボットの入力を検出する（判定は PR7）。
	 *
	 * @param int $form_id フォーム ID。
	 * @return string
	 */
	private static function render_honeypot( $form_id ) {
		$id = self::field_id( $form_id, 'hp' );
		return '<div class="wpef-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;" >'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'この項目は空のままにしてください', 'wp-entry-form' ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id ) . '" name="wpef_hp" value="" tabindex="-1" autocomplete="off" />'
			. '</div>';
	}

	/**
	 * 選択肢を正規化して返す（label/value を保証）。
	 *
	 * @param array $field フィールド。
	 * @return array 各要素 array('label'=>..,'value'=>..)。
	 */
	private static function options( $field ) {
		$out = array();
		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return $out;
		}
		foreach ( $field['options'] as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$value = isset( $opt['value'] ) ? (string) $opt['value'] : '';
			$label = isset( $opt['label'] ) && '' !== $opt['label'] ? (string) $opt['label'] : $value;
			$out[] = array(
				'label' => $label,
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * 必須バッジ（[必須] / 翻訳で [required] 等）の HTML を返す。
	 *
	 * 視覚表示用。スクリーンリーダーには入力要素の aria-required で伝えるため aria-hidden。
	 *
	 * @return string
	 */
	private static function required_badge() {
		return '<span class="wpef-required" aria-hidden="true">[' . esc_html__( '必須', 'wp-entry-form' ) . ']</span>';
	}

	/**
	 * フィールドの横幅クラスを返す（12カラム中の列数）。
	 *
	 * @param array $field 正規化済みフィールド。
	 * @return string 例: wpef-col-12 / wpef-col-6。
	 */
	private static function width_class( $field ) {
		$cols = isset( $field['cols'] ) ? (int) $field['cols'] : 12;
		$cols = max( 1, min( 12, $cols ) );
		return 'wpef-col-' . $cols;
	}

	/**
	 * フィールドの DOM id を生成する。
	 *
	 * @param int    $form_id フォーム ID。
	 * @param string $key     フィールドキー。
	 * @return string
	 */
	private static function field_id( $form_id, $key ) {
		return 'wpef-' . (int) $form_id . '-' . sanitize_html_class( $key );
	}
}
