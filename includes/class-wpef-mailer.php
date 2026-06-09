<?php
/**
 * メール送信（管理者通知・自動返信）。
 *
 * 保存後フック wpef_after_save_submission に乗って送信する。フォーム単位で有効/無効を
 * 切り替えられ、本文中の {field_key} と {all_fields} を差し込む（design.md §5・FR-7）。
 * メール送信に失敗してもデータ保存は既に成立しているため、フローは壊れない（FR-7.4）。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * メーラー。
 */
class WPEF_Mailer {

	/**
	 * フック登録。
	 */
	public static function init() {
		add_action( 'wpef_after_save_submission', array( __CLASS__, 'on_after_save' ), 10, 3 );
	}

	/**
	 * 保存後に通知・自動返信を送る。
	 *
	 * @param int   $submission_id 送信 ID。
	 * @param array $values        送信値。
	 * @param array $form          フォーム。
	 */
	public static function on_after_save( $submission_id, $values, $form ) {
		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();
		self::send_admin_notification( $settings, $values, $form );
		self::send_autoresponder( $settings, $values, $form );
	}

	/**
	 * 管理者通知メールを送る。
	 *
	 * @param array $settings フォーム設定。
	 * @param array $values   送信値。
	 * @param array $form     フォーム。
	 */
	private static function send_admin_notification( $settings, $values, $form ) {
		$cfg = isset( $settings['admin_notification'] ) && is_array( $settings['admin_notification'] ) ? $settings['admin_notification'] : array();
		if ( empty( $cfg['enabled'] ) ) {
			return;
		}

		$to = ! empty( $cfg['to'] ) && is_array( $cfg['to'] ) ? array_filter( array_map( 'sanitize_email', $cfg['to'] ) ) : array();
		if ( empty( $to ) ) {
			$to = array( get_option( 'admin_email' ) );
		}

		$subject = self::replace( ! empty( $cfg['subject'] ) ? $cfg['subject'] : __( '新しい応募がありました', 'wp-entry-form' ), $values, $form );
		$body    = self::replace( ! empty( $cfg['body'] ) ? $cfg['body'] : '{all_fields}', $values, $form );

		$headers   = array();
		$applicant = self::applicant_email( $settings, $values, $form );
		if ( $applicant ) {
			$headers[] = 'Reply-To: ' . $applicant;
		}

		/**
		 * 管理者通知メールの内容を上書きする。
		 *
		 * @param array $mail   to/subject/body/headers。
		 * @param array $values 送信値。
		 * @param array $form   フォーム。
		 */
		$mail = apply_filters(
			'wpef_admin_notification_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$values,
			$form
		);

		self::send( $mail );
	}

	/**
	 * 応募者への自動返信メールを送る。
	 *
	 * @param array $settings フォーム設定。
	 * @param array $values   送信値。
	 * @param array $form     フォーム。
	 */
	private static function send_autoresponder( $settings, $values, $form ) {
		$cfg = isset( $settings['autoresponder'] ) && is_array( $settings['autoresponder'] ) ? $settings['autoresponder'] : array();
		if ( empty( $cfg['enabled'] ) ) {
			return;
		}

		$to_field = isset( $cfg['to_field'] ) ? $cfg['to_field'] : '';
		$to       = ( '' !== $to_field && isset( $values[ $to_field ] ) ) ? sanitize_email( (string) $values[ $to_field ] ) : '';
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$subject = self::replace( ! empty( $cfg['subject'] ) ? $cfg['subject'] : __( '応募を受け付けました', 'wp-entry-form' ), $values, $form );
		$body    = self::replace( isset( $cfg['body'] ) ? $cfg['body'] : '', $values, $form );

		$headers = array();
		if ( ! empty( $cfg['from_email'] ) && is_email( $cfg['from_email'] ) ) {
			$from_name = ! empty( $cfg['from_name'] ) ? $cfg['from_name'] : get_bloginfo( 'name' );
			$headers[] = 'From: ' . $from_name . ' <' . $cfg['from_email'] . '>';
		}

		/**
		 * 自動返信メールの内容を上書きする。
		 *
		 * @param array $mail   to/subject/body/headers。
		 * @param array $values 送信値。
		 * @param array $form   フォーム。
		 */
		$mail = apply_filters(
			'wpef_autoresponder_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$values,
			$form
		);

		self::send( $mail );
	}

	/**
	 * wp_mail を呼び、失敗時は（デバッグ時のみ）ログする。
	 *
	 * @param array $mail to/subject/body/headers。
	 */
	private static function send( $mail ) {
		if ( empty( $mail['to'] ) ) {
			return;
		}
		$ok = wp_mail(
			$mail['to'],
			isset( $mail['subject'] ) ? $mail['subject'] : '',
			isset( $mail['body'] ) ? $mail['body'] : '',
			isset( $mail['headers'] ) ? $mail['headers'] : array()
		);
		if ( ! $ok && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$to = is_array( $mail['to'] ) ? implode( ', ', $mail['to'] ) : $mail['to'];
			error_log( '[WPEF] メール送信に失敗しました: ' . $to ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * 本文・件名の差し込み（{field_key} と {all_fields}）を行う。
	 *
	 * @param string $text   テンプレート。
	 * @param array  $values 送信値。
	 * @param array  $form   フォーム。
	 * @return string
	 */
	private static function replace( $text, $values, $form ) {
		$text = str_replace( '{all_fields}', self::all_fields( $values, $form ), (string) $text );

		foreach ( (array) $form['fields'] as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			if ( '' === $field['key'] || ! WPEF_Fields::is_input_type( $field['type'] ) ) {
				continue;
			}
			$value = isset( $values[ $field['key'] ] ) ? $values[ $field['key'] ] : '';
			$text  = str_replace( '{' . $field['key'] . '}', self::value_text( $field, $value ), $text );
		}

		return $text;
	}

	/**
	 * 全フィールドを「ラベル: 値」の複数行テキストにする。
	 *
	 * @param array $values 送信値。
	 * @param array $form   フォーム。
	 * @return string
	 */
	private static function all_fields( $values, $form ) {
		$lines = array();
		foreach ( (array) $form['fields'] as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			$type  = $field['type'];
			if ( '' === $field['key'] || ! WPEF_Fields::is_input_type( $type ) || 'file' === $type ) {
				continue;
			}
			$label   = '' !== $field['label'] ? $field['label'] : $field['key'];
			$value   = isset( $values[ $field['key'] ] ) ? $values[ $field['key'] ] : '';
			$lines[] = $label . ': ' . self::value_text( $field, $value );
		}
		return implode( "\n", $lines );
	}

	/**
	 * 値を差し込み用のプレーンテキストに整形する（選択肢はラベル、配列は結合）。
	 *
	 * @param array $field フィールド。
	 * @param mixed $value 値。
	 * @return string
	 */
	private static function value_text( $field, $value ) {
		if ( 'consent' === $field['type'] ) {
			return ! empty( $value ) ? __( '同意する', 'wp-entry-form' ) : __( '同意しない', 'wp-entry-form' );
		}
		if ( is_array( $value ) ) {
			$labels = array();
			foreach ( $value as $v ) {
				$labels[] = self::option_label( $field, (string) $v );
			}
			return implode( '、', $labels );
		}
		if ( WPEF_Fields::has_options( $field['type'] ) ) {
			return self::option_label( $field, (string) $value );
		}
		return (string) $value;
	}

	/**
	 * 選択肢 value に対応するラベル（無ければ value）。
	 *
	 * @param array  $field フィールド。
	 * @param string $value 値。
	 * @return string
	 */
	private static function option_label( $field, $value ) {
		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			foreach ( $field['options'] as $opt ) {
				if ( is_array( $opt ) && isset( $opt['value'] ) && (string) $opt['value'] === $value ) {
					return isset( $opt['label'] ) && '' !== $opt['label'] ? (string) $opt['label'] : $value;
				}
			}
		}
		return $value;
	}

	/**
	 * 応募者のメールアドレスを推定する（自動返信の宛先 → email 型の最初の値）。
	 *
	 * @param array $settings フォーム設定。
	 * @param array $values   送信値。
	 * @param array $form     フォーム。
	 * @return string 空なら ''。
	 */
	private static function applicant_email( $settings, $values, $form ) {
		$to_field = isset( $settings['autoresponder']['to_field'] ) ? $settings['autoresponder']['to_field'] : '';
		if ( '' !== $to_field && ! empty( $values[ $to_field ] ) && is_email( $values[ $to_field ] ) ) {
			return sanitize_email( $values[ $to_field ] );
		}
		foreach ( (array) $form['fields'] as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			if ( 'email' === $field['type'] && '' !== $field['key'] && ! empty( $values[ $field['key'] ] ) && is_email( $values[ $field['key'] ] ) ) {
				return sanitize_email( $values[ $field['key'] ] );
			}
		}
		return '';
	}
}
