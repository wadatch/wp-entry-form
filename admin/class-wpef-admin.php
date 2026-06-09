<?php
/**
 * 管理画面のメニュー登録と画面ルーティング。
 *
 * トップメニュー「WP Entry Form」を登録し、フォーム一覧／編集の表示と
 * 保存・行アクション（複製・削除）のハンドラを WPEF_Form_Builder に委譲する。
 * 管理操作は manage_options 権限（NFR-4。将来カスタム権限へ差し替え可能）。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面のブートストラップ。
 */
class WPEF_Admin {

	/**
	 * メニューのページスラッグ。
	 */
	const PAGE = 'wp-entry-form';

	/**
	 * 操作に必要な権限。
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * 管理操作に必要な権限。
		 *
		 * @param string $cap 既定 manage_options。
		 */
		return apply_filters( 'wpef_admin_capability', 'manage_options' );
	}

	/**
	 * フック登録。
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_wpef_save_form', array( 'WPEF_Form_Builder', 'handle_save' ) );
		add_action( 'admin_post_wpef_form_action', array( 'WPEF_Form_Builder', 'handle_row_action' ) );
	}

	/**
	 * メニューを登録する。
	 */
	public static function register_menu() {
		$cap = self::capability();

		add_menu_page(
			__( 'WP Entry Form', 'wp-entry-form' ),
			__( 'WP Entry Form', 'wp-entry-form' ),
			$cap,
			self::PAGE,
			array( __CLASS__, 'route' ),
			'dashicons-feedback',
			26
		);

		add_submenu_page(
			self::PAGE,
			__( 'フォーム一覧', 'wp-entry-form' ),
			__( 'フォーム一覧', 'wp-entry-form' ),
			$cap,
			self::PAGE,
			array( __CLASS__, 'route' )
		);

		add_submenu_page(
			self::PAGE,
			__( '新規フォーム', 'wp-entry-form' ),
			__( '新規フォーム', 'wp-entry-form' ),
			$cap,
			self::PAGE . '&action=edit',
			'__return_null'
		);
	}

	/**
	 * 現在のリクエストに応じて一覧／編集を表示する。
	 */
	public static function route() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-entry-form' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action ) {
			WPEF_Form_Builder::render_edit();
			return;
		}

		WPEF_Form_Builder::render_list();
	}

	/**
	 * 管理画面アセットを読み込む（編集画面のみビルダー JS を enqueue）。
	 *
	 * @param string $hook 現在の管理ページのフック名。
	 */
	public static function enqueue_assets( $hook ) {
		// 自プラグインのトップレベルページのみ。
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' !== $action ) {
			return;
		}

		$asset_file = WPEF_PATH . 'build/assets/admin.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => WPEF_VERSION,
		);

		wp_enqueue_script(
			'wpef-admin',
			WPEF_URL . 'build/assets/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'wpef-admin', 'wp-entry-form', WPEF_PATH . 'languages' );

		// @wordpress/components のスタイル。
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wpef-admin', WPEF_URL . 'admin/css/wpef-admin.css', array(), $asset['version'] );

		// ビルダーへ渡す初期データ。
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id ? WPEF_DB::get_form( $form_id ) : null;

		wp_localize_script(
			'wpef-admin',
			'wpefBuilder',
			array(
				'fields'     => $form && ! empty( $form['fields'] ) ? $form['fields'] : array(),
				'settings'   => $form && ! empty( $form['settings'] ) ? $form['settings'] : new stdClass(),
				'status'     => $form ? WPEF_Form_State::normalize_status( $form['status'] ) : 'published',
				'fieldTypes' => self::field_types_for_js(),
			)
		);
	}

	/**
	 * JS 向けにフィールド型の一覧（key/label/メタ）を整形する。
	 *
	 * @return array
	 */
	private static function field_types_for_js() {
		$out = array();
		foreach ( WPEF_Fields::get_types() as $key => $def ) {
			$out[] = array(
				'type'       => $key,
				'label'      => $def['label'],
				'input'      => (bool) $def['input'],
				'hasOptions' => (bool) $def['has_options'],
				'multiple'   => (bool) $def['multiple'],
				'display'    => (bool) $def['display'],
			);
		}
		return $out;
	}

	/**
	 * 管理画面 URL を生成する。
	 *
	 * @param array $args クエリ引数。
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		$args = wp_parse_args( $args, array( 'page' => self::PAGE ) );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
