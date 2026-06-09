<?php
/**
 * フォームの公開状況の判定。
 *
 * status 列（draft / published / closed。旧 active=published・inactive=draft）と
 * settings.schedule（open/close の日時：サイトのタイムゾーンの datetime-local 文字列）から、
 * 実効状態（draft / scheduled / open / closed）を求める。フロント表示と送信受付の両方で使う。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 公開状況ヘルパー。
 */
class WPEF_Form_State {

	/**
	 * 公開状態の選択肢（status 列に保存する値）。
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'draft', 'published', 'closed' );
	}

	/**
	 * status 列の値を正規化する（旧 active/inactive を移行）。
	 *
	 * @param string $status 生の値。
	 * @return string draft / published / closed。
	 */
	public static function normalize_status( $status ) {
		if ( 'active' === $status ) {
			return 'published';
		}
		if ( 'inactive' === $status ) {
			return 'draft';
		}
		return in_array( $status, self::statuses(), true ) ? $status : 'published';
	}

	/**
	 * 公開状態の表示ラベル（下書き/公開/締めきり）。
	 *
	 * @param string $status status 列の値。
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( self::normalize_status( $status ) ) {
			case 'draft':
				return __( '下書き', 'wp-entry-form' );
			case 'closed':
				return __( '締めきり', 'wp-entry-form' );
			default:
				return __( '公開', 'wp-entry-form' );
		}
	}

	/**
	 * 実効状態を返す。
	 *
	 * @param array $form フォーム。
	 * @return string draft / scheduled / open / closed。
	 */
	public static function state( $form ) {
		$status = self::normalize_status( isset( $form['status'] ) ? $form['status'] : 'published' );
		if ( 'draft' === $status ) {
			return 'draft';
		}
		if ( 'closed' === $status ) {
			return 'closed';
		}

		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();
		$schedule = isset( $settings['schedule'] ) && is_array( $settings['schedule'] ) ? $settings['schedule'] : array();
		$now      = time();
		$open_ts  = ! empty( $schedule['open'] ) ? self::to_timestamp( $schedule['open'] ) : 0;
		$close_ts = ! empty( $schedule['close'] ) ? self::to_timestamp( $schedule['close'] ) : 0;

		if ( $open_ts && $now < $open_ts ) {
			return 'scheduled';
		}
		if ( $close_ts && $now >= $close_ts ) {
			return 'closed';
		}
		return 'open';
	}

	/**
	 * フォームが送信受付中か。
	 *
	 * @param array $form フォーム。
	 * @return bool
	 */
	public static function is_open( $form ) {
		return 'open' === self::state( $form );
	}

	/**
	 * 受付できない状態のときに表示するメッセージ（open/draft は空）。
	 *
	 * @param array  $form  フォーム。
	 * @param string $state 実効状態（省略時は算出）。
	 * @return string
	 */
	public static function closed_message( $form, $state = '' ) {
		if ( '' === $state ) {
			$state = self::state( $form );
		}
		$settings = isset( $form['settings'] ) && is_array( $form['settings'] ) ? $form['settings'] : array();
		$messages = isset( $settings['messages'] ) && is_array( $settings['messages'] ) ? $settings['messages'] : array();

		if ( 'scheduled' === $state ) {
			return ! empty( $messages['before_open'] ) ? $messages['before_open'] : __( 'このフォームの受付はまだ開始していません。', 'wp-entry-form' );
		}
		if ( 'closed' === $state ) {
			return ! empty( $messages['closed'] ) ? $messages['closed'] : __( 'このフォームの受付は終了しました。', 'wp-entry-form' );
		}
		return '';
	}

	/**
	 * サイトのタイムゾーンの datetime-local 文字列を UTC タイムスタンプへ変換する。
	 *
	 * @param string $local 'Y-m-dTH:i' 形式（または 'Y-m-d H:i'）。
	 * @return int 失敗時 0。
	 */
	private static function to_timestamp( $local ) {
		$local = str_replace( 'T', ' ', (string) $local );
		$gmt   = get_gmt_from_date( $local );
		if ( ! $gmt ) {
			return 0;
		}
		$ts = strtotime( $gmt . ' UTC' );
		return $ts ? $ts : 0;
	}
}
