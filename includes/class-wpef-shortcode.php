<?php
/**
 * ショートコード [entry_form id="N"]。
 *
 * フォームを描画する（PR2 では表示のみ。送信処理は PR4）。
 * 未公開（inactive）/ 存在しないフォーム ID の場合は、管理者にのみ警告を表示し、
 * 一般訪問者には何も表示しない（FR-3.3）。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フロント埋め込み用ショートコード。
 */
class WPEF_Shortcode {

	/**
	 * フック登録。
	 */
	public static function init() {
		add_shortcode( 'entry_form', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * フロントアセットを登録する（実際の enqueue はショートコード描画時）。
	 */
	public static function register_assets() {
		wp_register_style( 'wpef-form', WPEF_URL . 'public/css/wpef-form.css', array(), WPEF_VERSION );
		wp_register_script( 'wpef-form', WPEF_URL . 'public/js/wpef-form.js', array(), WPEF_VERSION, true );

		// リアルタイム検証のメッセージ（翻訳対応のままサーバ側から渡す）。%s/%d は JS で置換。
		wp_localize_script(
			'wpef-form',
			'wpefForm',
			array(
				'messages' => array(
					/* translators: %s: フィールドのラベル */
					'required'  => __( '「%s」は必須です。', 'wp-entry-form' ),
					'consent'   => __( '同意が必要です。', 'wp-entry-form' ),
					'email'     => __( 'メールアドレスの形式が正しくありません。', 'wp-entry-form' ),
					'url'       => __( 'URL の形式が正しくありません。', 'wp-entry-form' ),
					'number'    => __( '数値を入力してください。', 'wp-entry-form' ),
					/* translators: %s: 最小値 */
					'min'       => __( '%s 以上の値を入力してください。', 'wp-entry-form' ),
					/* translators: %s: 最大値 */
					'max'       => __( '%s 以下の値を入力してください。', 'wp-entry-form' ),
					/* translators: %d: 最大文字数 */
					'maxlength' => __( '%d 文字以内で入力してください。', 'wp-entry-form' ),
				),
			)
		);
	}

	/**
	 * ショートコードのコールバック。
	 *
	 * @param array $atts 属性（id）。
	 * @return string HTML。
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'entry_form' );
		$id   = absint( $atts['id'] );

		$form = $id ? WPEF_DB::get_form( $id ) : null;

		if ( ! $form ) {
			return self::notice_for_admin( $id, null );
		}

		// 公開状況。下書きは管理者にのみ通知し、一般訪問者には何も出さない。
		$pub = WPEF_Form_State::state( $form );
		if ( 'draft' === $pub ) {
			return self::notice_for_admin( $id, $form );
		}

		// アセットを読み込む。
		wp_enqueue_style( 'wpef-form' );
		wp_enqueue_script( 'wpef-form' );

		// 戻り先 URL（このページ。トークンは除去）。
		$return = get_permalink();
		$return = $return ? remove_query_arg( 'wpef_token', $return ) : home_url( '/' );

		// 送信フローの状態（PRG のトークン経由）を取り出す。
		$token      = isset( $_GET['wpef_token'] ) ? sanitize_text_field( wp_unslash( $_GET['wpef_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flow       = class_exists( 'WPEF_Submit_Handler' ) ? WPEF_Submit_Handler::consume_flow( $token, $id ) : null;
		$flow_state = is_array( $flow ) && isset( $flow['state'] ) ? $flow['state'] : 'input';

		$inner = '<span id="wpef-form-' . (int) $id . '"></span>';

		if ( 'success' === $flow_state ) {
			$settings = is_array( $form['settings'] ) ? $form['settings'] : array();
			$message  = isset( $settings['messages']['success'] ) && '' !== $settings['messages']['success']
				? $settings['messages']['success']
				: __( 'ご応募ありがとうございました。', 'wp-entry-form' );
			$inner .= '<div class="wpef-success" role="status">' . wp_kses_post( wpautop( $message ) ) . '</div>';
		} elseif ( 'scheduled' === $pub || 'closed' === $pub ) {
			// 受付期間外は誰にもメッセージのみ表示（フォームは出さない）。
			$inner .= '<div class="wpef-closed" role="status">' . wp_kses_post( wpautop( WPEF_Form_State::closed_message( $form, $pub ) ) ) . '</div>';
		} elseif ( 'confirm' === $flow_state ) {
			$inner .= WPEF_Renderer::render_confirmation( $form, isset( $flow['values'] ) ? $flow['values'] : array(), array( 'return' => $return ) );
		} else {
			$inner .= WPEF_Renderer::render_form(
				$form,
				array(
					'return' => $return,
					'values' => is_array( $flow ) && isset( $flow['values'] ) ? $flow['values'] : array(),
					'errors' => is_array( $flow ) && isset( $flow['errors'] ) ? $flow['errors'] : array(),
				)
			);
		}

		return '<div class="wpef-form-wrap">' . $inner . '</div>';
	}

	/**
	 * 管理者にのみ見せる警告（訪問者には空文字）。
	 *
	 * @param int        $id   要求されたフォーム ID。
	 * @param array|null $form 取得したフォーム（非アクティブ時は配列）。
	 * @return string
	 */
	private static function notice_for_admin( $id, $form ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		if ( ! $form ) {
			/* translators: %d: フォーム ID */
			$msg = sprintf( __( 'WP Entry Form: ID %d のフォームが見つかりません。', 'wp-entry-form' ), $id );
		} else {
			/* translators: %d: フォーム ID */
			$msg = sprintf( __( 'WP Entry Form: ID %d のフォームは非公開です（管理者にのみ表示）。', 'wp-entry-form' ), $id );
		}
		return '<div class="wpef-admin-notice" style="padding:8px;border:1px solid #c00;color:#c00;">' . esc_html( $msg ) . '</div>';
	}
}
