<?php
/**
 * データアクセス層。
 *
 * forms / submissions / files テーブルへの読み書きを $wpdb で薄くラップする。
 * 全クエリは $wpdb->prepare を用い、fields/settings/data は JSON 文字列として入出力する。
 * 後続 PR（ビルダー・送信フロー・送信管理）から利用される CRUD の入り口。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * $wpdb ラッパ。
 */
class WPEF_DB {

	/* ---------------------------------------------------------------------
	 * Forms
	 * ------------------------------------------------------------------- */

	/**
	 * フォームを1件取得する。
	 *
	 * @param int $id フォーム ID。
	 * @return array|null fields/settings をデコードした連想配列、なければ null。
	 */
	public static function get_form( $id ) {
		global $wpdb;
		$table = WPEF_Install::forms_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);
		return $row ? self::decode_form( $row ) : null;
	}

	/**
	 * フォーム一覧を取得する。
	 *
	 * @param array $args status / orderby / order を指定可能。
	 * @return array フォーム配列（fields/settings デコード済み）。
	 */
	public static function get_forms( $args = array() ) {
		global $wpdb;
		$table = WPEF_Install::forms_table();

		$defaults = array(
			'status'  => '',
			'orderby' => 'id',
			'order'   => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$allowed_orderby = array( 'id', 'title', 'created_at', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		if ( '' !== $args['status'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY {$orderby} {$order}", $args['status'] ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY {$orderby} {$order}", ARRAY_A );
		}

		return array_map( array( __CLASS__, 'decode_form' ), $rows ? $rows : array() );
	}

	/**
	 * フォームを作成する。
	 *
	 * @param array $data title / status / fields / settings。
	 * @return int|false 作成された ID、失敗で false。
	 */
	public static function insert_form( $data ) {
		global $wpdb;
		$now = self::now();
		$ok  = $wpdb->insert(
			WPEF_Install::forms_table(),
			array(
				'title'      => isset( $data['title'] ) ? (string) $data['title'] : '',
				'status'     => isset( $data['status'] ) ? (string) $data['status'] : 'active',
				'fields'     => wp_json_encode( isset( $data['fields'] ) ? $data['fields'] : array() ),
				'settings'   => wp_json_encode( isset( $data['settings'] ) ? $data['settings'] : array() ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * フォームを更新する。
	 *
	 * @param int   $id   フォーム ID。
	 * @param array $data 更新するカラム（title/status/fields/settings）。
	 * @return bool
	 */
	public static function update_form( $id, $data ) {
		global $wpdb;

		$columns = array( 'updated_at' => self::now() );
		$formats = array( '%s' );

		if ( isset( $data['title'] ) ) {
			$columns['title'] = (string) $data['title'];
			$formats[]        = '%s';
		}
		if ( isset( $data['status'] ) ) {
			$columns['status'] = (string) $data['status'];
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'fields', $data ) ) {
			$columns['fields'] = wp_json_encode( $data['fields'] );
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$columns['settings'] = wp_json_encode( $data['settings'] );
			$formats[]           = '%s';
		}

		return false !== $wpdb->update(
			WPEF_Install::forms_table(),
			$columns,
			array( 'id' => absint( $id ) ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * フォームを削除する。
	 *
	 * @param int $id フォーム ID。
	 * @return bool
	 */
	public static function delete_form( $id ) {
		global $wpdb;
		return false !== $wpdb->delete(
			WPEF_Install::forms_table(),
			array( 'id' => absint( $id ) ),
			array( '%d' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Submissions
	 * ------------------------------------------------------------------- */

	/**
	 * 送信を1件取得する。
	 *
	 * @param int $id 送信 ID。
	 * @return array|null data をデコードした連想配列、なければ null。
	 */
	public static function get_submission( $id ) {
		global $wpdb;
		$table = WPEF_Install::submissions_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ),
			ARRAY_A
		);
		return $row ? self::decode_submission( $row ) : null;
	}

	/**
	 * 送信を作成する。
	 *
	 * @param array $data form_id / data / status / ip_address / user_agent / referer / user_id。
	 * @return int|false 作成された ID、失敗で false。
	 */
	public static function insert_submission( $data ) {
		global $wpdb;
		$ok = $wpdb->insert(
			WPEF_Install::submissions_table(),
			array(
				'form_id'    => isset( $data['form_id'] ) ? absint( $data['form_id'] ) : 0,
				'data'       => wp_json_encode( isset( $data['data'] ) ? $data['data'] : array() ),
				'status'     => isset( $data['status'] ) ? (string) $data['status'] : 'unread',
				'ip_address' => isset( $data['ip_address'] ) ? (string) $data['ip_address'] : '',
				'user_agent' => isset( $data['user_agent'] ) ? (string) $data['user_agent'] : '',
				'referer'    => isset( $data['referer'] ) ? (string) $data['referer'] : '',
				'user_id'    => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : null,
				'created_at' => isset( $data['created_at'] ) ? (string) $data['created_at'] : self::now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * 送信のステータスを更新する。
	 *
	 * @param int    $id     送信 ID。
	 * @param string $status unread / read / spam / trash。
	 * @return bool
	 */
	public static function update_submission_status( $id, $status ) {
		global $wpdb;
		return false !== $wpdb->update(
			WPEF_Install::submissions_table(),
			array( 'status' => (string) $status ),
			array( 'id' => absint( $id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 送信を削除する。
	 *
	 * @param int $id 送信 ID。
	 * @return bool
	 */
	public static function delete_submission( $id ) {
		global $wpdb;
		return false !== $wpdb->delete(
			WPEF_Install::submissions_table(),
			array( 'id' => absint( $id ) ),
			array( '%d' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Files
	 * ------------------------------------------------------------------- */

	/**
	 * 添付ファイルのメタ情報を保存する。
	 *
	 * @param array $data submission_id / field_key / original_name / stored_path / mime_type / file_size。
	 * @return int|false 作成された ID、失敗で false。
	 */
	public static function insert_file( $data ) {
		global $wpdb;
		$ok = $wpdb->insert(
			WPEF_Install::files_table(),
			array(
				'submission_id' => isset( $data['submission_id'] ) ? absint( $data['submission_id'] ) : 0,
				'field_key'     => isset( $data['field_key'] ) ? (string) $data['field_key'] : '',
				'original_name' => isset( $data['original_name'] ) ? (string) $data['original_name'] : '',
				'stored_path'   => isset( $data['stored_path'] ) ? (string) $data['stored_path'] : '',
				'mime_type'     => isset( $data['mime_type'] ) ? (string) $data['mime_type'] : '',
				'file_size'     => isset( $data['file_size'] ) ? absint( $data['file_size'] ) : 0,
				'created_at'    => self::now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * 送信に紐づく添付ファイルを取得する。
	 *
	 * @param int $submission_id 送信 ID。
	 * @return array
	 */
	public static function get_files_for_submission( $submission_id ) {
		global $wpdb;
		$table = WPEF_Install::files_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d ORDER BY id ASC", absint( $submission_id ) ),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * 現在時刻（UTC, MySQL DATETIME 形式）。
	 *
	 * @return string
	 */
	private static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * forms 行の fields/settings を配列にデコードする。
	 *
	 * @param array $row 生の行。
	 * @return array
	 */
	private static function decode_form( $row ) {
		$row['fields']   = self::decode_json( isset( $row['fields'] ) ? $row['fields'] : '' );
		$row['settings'] = self::decode_json( isset( $row['settings'] ) ? $row['settings'] : '' );
		return $row;
	}

	/**
	 * submissions 行の data を配列にデコードする。
	 *
	 * @param array $row 生の行。
	 * @return array
	 */
	private static function decode_submission( $row ) {
		$row['data'] = self::decode_json( isset( $row['data'] ) ? $row['data'] : '' );
		return $row;
	}

	/**
	 * JSON 文字列を配列にする（不正なら空配列）。
	 *
	 * @param string $json JSON 文字列。
	 * @return array
	 */
	private static function decode_json( $json ) {
		if ( '' === $json || null === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
