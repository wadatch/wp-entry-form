<?php
/**
 * REST API。
 *
 * ブロックエディタ（PR7）がフォームを選択できるよう、フォーム一覧を返す
 * 読み取り専用エンドポイント wpef/v1/forms を提供する。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST ルート登録。
 */
class WPEF_REST {

	/**
	 * 名前空間。
	 */
	const NS = 'wpef/v1';

	/**
	 * フック登録。
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * ルートを登録する。
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/forms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_forms' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
	}

	/**
	 * ブロックエディタを使える権限があるか。
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * フォーム一覧（id/title/status）を返す。
	 *
	 * @return WP_REST_Response
	 */
	public static function get_forms() {
		$forms = WPEF_DB::get_forms();
		$out   = array();
		foreach ( $forms as $form ) {
			$out[] = array(
				'id'     => (int) $form['id'],
				'title'  => $form['title'],
				'status' => $form['status'],
			);
		}
		return rest_ensure_response( $out );
	}
}
