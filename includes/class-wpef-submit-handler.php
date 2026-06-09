<?php
/**
 * 送信フロー制御（admin-post）。
 *
 * 標準 POST を正路とし（JS 無効でも完結。design.md §6）、入力→（確認）→送信を制御する。
 * admin-post 経由のため画面はテーマを持たない。そこで PRG パターンを採り、処理結果
 * （確認待ち／エラー＋入力値／完了）を一時データ（transient）に保存してトークン付きで
 * 元のフォームページへ 303 リダイレクトし、ショートコード側が状態に応じて描画する。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 送信ハンドラ。
 */
class WPEF_Submit_Handler {

	/**
	 * フロー状態を保存する transient の接頭辞。
	 */
	const FLOW_PREFIX = 'wpef_flow_';

	/**
	 * フック登録。
	 */
	public static function init() {
		add_action( 'admin_post_wpef_submit', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_wpef_submit', array( __CLASS__, 'handle' ) );
	}

	/**
	 * 送信を処理する。
	 */
	public static function handle() {
		$form_id = isset( $_POST['wpef_form_id'] ) ? absint( $_POST['wpef_form_id'] ) : 0;
		check_admin_referer( 'wpef_submit_' . $form_id, 'wpef_nonce' );

		$form = $form_id ? WPEF_DB::get_form( $form_id ) : null;
		$return = isset( $_POST['wpef_return'] ) ? esc_url_raw( wp_unslash( $_POST['wpef_return'] ) ) : home_url( '/' );

		// 公開・受付中のフォームのみ受け付ける（締めきり/下書き/期間外は弾く）。
		if ( ! $form || ! WPEF_Form_State::is_open( $form ) ) {
			wp_safe_redirect( $return );
			exit;
		}

		$settings        = is_array( $form['settings'] ) ? $form['settings'] : array();
		$confirm_enabled = ! empty( $settings['confirmation_screen'] );
		$step            = isset( $_POST['wpef_step'] ) ? sanitize_key( wp_unslash( $_POST['wpef_step'] ) ) : 'input';

		$values = self::collect_values( $form );

		// 確認画面から「戻る」: 入力画面へ値を保持して戻す。
		if ( $confirm_enabled && isset( $_POST['wpef_back'] ) ) {
			self::redirect_with_state( $return, $form_id, array( 'state' => 'input', 'values' => $values ) );
		}

		// 検証（ファイル項目は PR6 で対応するため検証対象から除外）。
		$errors = WPEF_Validator::validate( self::validatable_form( $form ), $values );
		if ( ! empty( $errors ) ) {
			self::redirect_with_state( $return, $form_id, array( 'state' => 'input', 'values' => $values, 'errors' => $errors ) );
		}

		// 確認画面あり・入力ステップ → 確認画面へ。
		if ( $confirm_enabled && 'input' === $step ) {
			self::redirect_with_state( $return, $form_id, array( 'state' => 'confirm', 'values' => $values ) );
		}

		// ここまで来たら確定保存（確認なしの入力 or 確認画面からの送信）。
		self::save( $form, $values, $return );
	}

	/**
	 * 送信を保存し、完了状態へ遷移する。
	 *
	 * @param array  $form   フォーム。
	 * @param array  $values サニタイズ済みの値。
	 * @param string $return 戻り先 URL。
	 */
	private static function save( $form, $values, $return ) {
		$form_id  = (int) $form['id'];
		$settings = is_array( $form['settings'] ) ? $form['settings'] : array();

		/**
		 * 保存前フック。
		 *
		 * @param array $values 送信値。
		 * @param array $form   フォーム。
		 */
		do_action( 'wpef_before_save_submission', $values, $form );

		$submission_id = WPEF_DB::insert_submission(
			array(
				'form_id'    => $form_id,
				'data'       => $values,
				'status'     => 'received',
				'ip_address' => self::client_ip(),
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'referer'    => $return,
				'user_id'    => get_current_user_id() ? get_current_user_id() : null,
			)
		);

		/**
		 * 保存後フック（メール送信・外部連携など）。
		 *
		 * @param int   $submission_id 送信 ID。
		 * @param array $values        送信値。
		 * @param array $form          フォーム。
		 */
		do_action( 'wpef_after_save_submission', $submission_id, $values, $form );

		// 完了後リダイレクト先が設定されていればそちらへ。
		$redirect_url = isset( $settings['redirect_url'] ) ? $settings['redirect_url'] : '';
		if ( '' !== $redirect_url ) {
			wp_redirect( $redirect_url );
			exit;
		}

		self::redirect_with_state( $return, $form_id, array( 'state' => 'success' ) );
	}

	/**
	 * $_POST['wpef'] から各フィールドの値を型に応じて収集・サニタイズする。
	 *
	 * 表示専用要素とファイル項目（PR6 対応）は対象外。
	 *
	 * @param array $form フォーム。
	 * @return array field_key => 値。
	 */
	private static function collect_values( $form ) {
		$raw    = isset( $_POST['wpef'] ) && is_array( $_POST['wpef'] ) ? wp_unslash( $_POST['wpef'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$values = array();

		foreach ( (array) $form['fields'] as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			$type  = $field['type'];
			if ( '' === $field['key'] || ! WPEF_Fields::is_input_type( $type ) || 'file' === $type ) {
				continue;
			}
			$input             = isset( $raw[ $field['key'] ] ) ? $raw[ $field['key'] ] : ( WPEF_Fields::is_multiple( $type ) ? array() : '' );
			$values[ $field['key'] ] = WPEF_Fields::sanitize_value( $field, $input );
		}

		return $values;
	}

	/**
	 * 検証対象のフォーム（ファイル項目を除外したもの）を返す。
	 *
	 * @param array $form フォーム。
	 * @return array
	 */
	private static function validatable_form( $form ) {
		$form['fields'] = array_values(
			array_filter(
				(array) $form['fields'],
				function ( $f ) {
					return isset( $f['type'] ) && 'file' !== $f['type'];
				}
			)
		);
		return $form;
	}

	/**
	 * フロー状態を transient に保存し、トークン付きで元ページへ 303 リダイレクトする。
	 *
	 * @param string $return  戻り先 URL。
	 * @param int    $form_id フォーム ID。
	 * @param array  $state   保存する状態（state/values/errors）。
	 */
	private static function redirect_with_state( $return, $form_id, $state ) {
		$token = wp_generate_password( 24, false, false );
		$state['form_id'] = (int) $form_id;
		set_transient( self::FLOW_PREFIX . $token, $state, 15 * MINUTE_IN_SECONDS );

		$url = add_query_arg( 'wpef_token', $token, $return ) . '#wpef-form-' . (int) $form_id;
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * 送信元 IP を返す。
	 *
	 * @return string
	 */
	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * トークンに対応するフロー状態を取り出して破棄する（ショートコードから呼ぶ）。
	 *
	 * @param string $token   トークン。
	 * @param int    $form_id 対象フォーム ID。
	 * @return array|null 状態（無効なら null）。
	 */
	public static function consume_flow( $token, $form_id ) {
		if ( '' === $token ) {
			return null;
		}
		$state = get_transient( self::FLOW_PREFIX . $token );
		if ( ! is_array( $state ) || (int) ( isset( $state['form_id'] ) ? $state['form_id'] : 0 ) !== (int) $form_id ) {
			return null;
		}
		delete_transient( self::FLOW_PREFIX . $token );
		return $state;
	}
}
