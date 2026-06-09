<?php
/**
 * スパム対策（ハニーポット・送信レート制限）。
 *
 * ハニーポット（隠し項目）に入力があればボットとみなす。送信レート制限は IP 単位で
 * transient によりカウントする（保存先は transient。design.md §11 の決定）。
 * 判定は wpef_is_spam フィルタで上書きできる（design.md §9）。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * スパムガード。
 */
class WPEF_Spam {

	/**
	 * レート制限カウンタの transient 接頭辞。
	 */
	const RL_PREFIX = 'wpef_rl_';

	/**
	 * 送信がスパムか判定する（ハニーポット + フィルタ）。
	 *
	 * @param array $form   フォーム。
	 * @param array $values 送信値。
	 * @return bool
	 */
	public static function is_spam( $form, $values ) {
		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();
		$is_spam  = self::honeypot_filled( $settings );

		/**
		 * スパム判定を上書きする。
		 *
		 * @param bool  $is_spam 判定結果。
		 * @param array $form    フォーム。
		 * @param array $values  送信値。
		 */
		return (bool) apply_filters( 'wpef_is_spam', $is_spam, $form, $values );
	}

	/**
	 * ハニーポットに入力があるか（有効時のみ）。
	 *
	 * @param array $settings フォーム設定。
	 * @return bool
	 */
	private static function honeypot_filled( $settings ) {
		$enabled = ! isset( $settings['spam']['honeypot'] ) || ! empty( $settings['spam']['honeypot'] );
		if ( ! $enabled ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST['wpef_hp'] ) && '' !== trim( (string) wp_unslash( $_POST['wpef_hp'] ) );
	}

	/**
	 * レート制限に達していないか（true なら送信可）。
	 *
	 * @param array $form フォーム。
	 * @return bool
	 */
	public static function rate_ok( $form ) {
		$rate = self::rate_settings( $form );
		if ( empty( $rate['enabled'] ) ) {
			return true;
		}
		$count = (int) get_transient( self::rate_key( $form ) );
		return $count < (int) $rate['max'];
	}

	/**
	 * 送信成功を1件カウントする（レート制限が有効な場合）。
	 *
	 * @param array $form フォーム。
	 */
	public static function record( $form ) {
		$rate = self::rate_settings( $form );
		if ( empty( $rate['enabled'] ) ) {
			return;
		}
		$key   = self::rate_key( $form );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, (int) $rate['window'] );
	}

	/**
	 * レート制限の設定（既定込み）を返す。
	 *
	 * @param array $form フォーム。
	 * @return array enabled / max / window。
	 */
	private static function rate_settings( $form ) {
		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();
		$rate     = isset( $settings['spam']['rate_limit'] ) && is_array( $settings['spam']['rate_limit'] ) ? $settings['spam']['rate_limit'] : array();
		return array(
			'enabled' => ! empty( $rate['enabled'] ),
			'max'     => isset( $rate['max'] ) ? max( 1, (int) $rate['max'] ) : 3,
			'window'  => isset( $rate['window'] ) ? max( 1, (int) $rate['window'] ) : 600,
		);
	}

	/**
	 * IP 単位のレート制限キー。
	 *
	 * @param array $form フォーム。
	 * @return string
	 */
	private static function rate_key( $form ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return self::RL_PREFIX . (int) ( isset( $form['id'] ) ? $form['id'] : 0 ) . '_' . md5( $ip );
	}
}
