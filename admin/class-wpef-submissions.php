<?php
/**
 * 送信データ管理（管理画面）。
 *
 * 一覧（WP_List_Table）／詳細の表示、ステータス変更・削除（個別/一括）、
 * CSV エクスポート（UTF-8 BOM）を担う。ファイル添付は PR6b で追加する。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 送信管理コントローラ。
 */
class WPEF_Submissions {

	/**
	 * 管理ページスラッグ。
	 */
	const PAGE = 'wpef-submissions';

	/**
	 * 入力状況（フォーム別ダッシュボード）のページスラッグ。
	 */
	const OVERVIEW_PAGE = 'wpef-overview';

	/**
	 * フック登録。
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_wpef_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * 送信一覧のサブメニューを登録する。
	 */
	public static function register_menu() {
		$cap = WPEF_Admin::capability();

		add_submenu_page(
			WPEF_Admin::PAGE,
			__( '入力状況', 'wp-entry-form' ),
			__( '入力状況', 'wp-entry-form' ),
			$cap,
			self::OVERVIEW_PAGE,
			array( __CLASS__, 'render_overview' )
		);

		add_submenu_page(
			WPEF_Admin::PAGE,
			__( '送信データ', 'wp-entry-form' ),
			__( '送信データ', 'wp-entry-form' ),
			$cap,
			self::PAGE,
			array( __CLASS__, 'route' )
		);
	}

	/**
	 * 入力状況ページ URL。
	 *
	 * @param array $args クエリ引数。
	 * @return string
	 */
	public static function overview_url( $args = array() ) {
		$args = wp_parse_args( $args, array( 'page' => self::OVERVIEW_PAGE ) );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * 管理ページ URL を生成する。
	 *
	 * @param array $args クエリ引数。
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		$args = wp_parse_args( $args, array( 'page' => self::PAGE ) );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * ルーティング（一覧 / 詳細）。
	 */
	public static function route() {
		if ( ! current_user_can( WPEF_Admin::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-entry-form' ) );
		}

		self::handle_actions();

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'view' === $action ) {
			self::render_detail();
			return;
		}
		self::render_list();
	}

	/**
	 * 個別・一括のステータス変更／削除を処理し、PRG で一覧へ戻る。
	 */
	private static function handle_actions() {
		$notice = '';

		// 個別行操作（GET + nonce）。
		$single = isset( $_GET['wpef_action'] ) ? sanitize_key( wp_unslash( $_GET['wpef_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $single && isset( $_GET['submission'] ) ) {
			$id = absint( $_GET['submission'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'wpef_sub_' . $single . '_' . $id );
			self::apply_action( $single, array( $id ) );
			$notice = $single;
		}

		// 一括操作（WP_List_Table のフォーム送信）。
		$bulk = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$bulk = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$bulk = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		$valid_bulk = array( 'mark_read', 'mark_unread', 'mark_spam', 'trash', 'delete', 'restore' );
		if ( $bulk && in_array( $bulk, $valid_bulk, true ) && ! empty( $_REQUEST['submission'] ) ) {
			check_admin_referer( 'bulk-wpef_submissions' );
			$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['submission'] ) );
			self::apply_action( $bulk, $ids );
			$notice = $bulk;
		}

		if ( '' !== $notice ) {
			$redirect = self::page_url(
				array_filter(
					array(
						'form_id'      => isset( $_REQUEST['form_id'] ) ? absint( $_REQUEST['form_id'] ) : null,
						'wpef_status'  => isset( $_REQUEST['wpef_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpef_status'] ) ) : null,
						'wpef_notice'  => $notice,
					)
				)
			);
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * アクションを送信群に適用する。
	 *
	 * @param string $action mark_read/mark_unread/mark_spam/trash/restore/delete。
	 * @param int[]  $ids    対象 ID。
	 */
	private static function apply_action( $action, $ids ) {
		$status_map = array(
			'mark_read'   => 'read',
			'mark_unread' => 'unread',
			'mark_spam'   => 'spam',
			'trash'       => 'trash',
			'restore'     => 'unread',
		);
		foreach ( $ids as $id ) {
			if ( ! $id ) {
				continue;
			}
			if ( 'delete' === $action ) {
				/**
				 * 送信削除時フック（添付削除などに利用。PR6b）。
				 *
				 * @param int $id 送信 ID。
				 */
				do_action( 'wpef_delete_submission', $id );
				WPEF_DB::delete_submission( $id );
			} elseif ( isset( $status_map[ $action ] ) ) {
				WPEF_DB::update_submission_status( $id, $status_map[ $action ] );
			}
		}
	}

	/**
	 * 通知メッセージ。
	 *
	 * @param string $key 通知キー。
	 */
	private static function render_notice( $key ) {
		$map = array(
			'mark_read'   => __( '既読にしました。', 'wp-entry-form' ),
			'mark_unread' => __( '未読にしました。', 'wp-entry-form' ),
			'mark_spam'   => __( 'スパムにしました。', 'wp-entry-form' ),
			'trash'       => __( 'ゴミ箱へ移動しました。', 'wp-entry-form' ),
			'restore'     => __( '復元しました。', 'wp-entry-form' ),
			'delete'      => __( '削除しました。', 'wp-entry-form' ),
		);
		if ( isset( $map[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $key ] ) . '</p></div>';
		}
	}

	/**
	 * 一覧画面。
	 */
	private static function render_list() {
		$table = new WPEF_Submissions_Table();
		$table->prepare_items();

		$notice  = isset( $_GET['wpef_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpef_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['wpef_status'] ) ? sanitize_key( wp_unslash( $_GET['wpef_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( '送信データ', 'wp-entry-form' ) . '</h1>';

		// CSV エクスポート（現在の絞り込みを引き継ぐ）。
		echo ' <a href="' . esc_url( self::csv_url( $form_id, $status ) ) . '" class="page-title-action">' . esc_html__( 'CSV エクスポート', 'wp-entry-form' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		self::render_notice( $notice );

		$table->views();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		if ( '' !== $status ) {
			echo '<input type="hidden" name="wpef_status" value="' . esc_attr( $status ) . '" />';
		}
		$table->search_box( __( '検索', 'wp-entry-form' ), 'wpef-submission' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * 詳細画面。
	 */
	private static function render_detail() {
		$id         = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$submission = $id ? WPEF_DB::get_submission( $id ) : null;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '送信の詳細', 'wp-entry-form' ) . '</h1>';
		echo '<p><a href="' . esc_url( self::page_url() ) . '">&larr; ' . esc_html__( '一覧へ戻る', 'wp-entry-form' ) . '</a></p>';

		if ( ! $submission ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( '送信が見つかりません。', 'wp-entry-form' ) . '</p></div></div>';
			return;
		}

		// 未読なら既読にする。
		if ( 'unread' === $submission['status'] ) {
			WPEF_DB::update_submission_status( $id, 'read' );
			$submission['status'] = 'read';
		}

		$form   = WPEF_DB::get_form( (int) $submission['form_id'] );
		$fields = $form && is_array( $form['fields'] ) ? $form['fields'] : array();
		$data   = is_array( $submission['data'] ) ? $submission['data'] : array();

		echo '<table class="widefat striped" style="max-width:820px;margin-bottom:1.5em;"><tbody>';
		foreach ( $fields as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			if ( '' === $field['key'] || ! WPEF_Fields::is_input_type( $field['type'] ) || 'file' === $field['type'] ) {
				continue;
			}
			$label = '' !== $field['label'] ? $field['label'] : $field['key'];
			$value = isset( $data[ $field['key'] ] ) ? WPEF_Fields::value_to_text( $field, $data[ $field['key'] ] ) : '';
			echo '<tr><th style="width:30%;">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// メタ情報。
		$labels = WPEF_Submissions_Table::status_labels();
		$ts     = strtotime( $submission['created_at'] . ' UTC' );
		echo '<h2>' . esc_html__( 'メタ情報', 'wp-entry-form' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:820px;"><tbody>';
		echo '<tr><th style="width:30%;">' . esc_html__( 'ステータス', 'wp-entry-form' ) . '</th><td>' . esc_html( isset( $labels[ $submission['status'] ] ) ? $labels[ $submission['status'] ] : $submission['status'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( '送信日時', 'wp-entry-form' ) . '</th><td>' . esc_html( $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : $submission['created_at'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'IP アドレス', 'wp-entry-form' ) . '</th><td>' . esc_html( $submission['ip_address'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'ユーザーエージェント', 'wp-entry-form' ) . '</th><td>' . esc_html( $submission['user_agent'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'リファラ', 'wp-entry-form' ) . '</th><td>' . esc_html( $submission['referer'] ) . '</td></tr>';
		echo '</tbody></table>';

		// 操作。
		echo '<p style="margin-top:1.5em;">';
		echo '<a href="' . esc_url( wp_nonce_url( self::page_url( array( 'wpef_action' => 'trash', 'submission' => $id ) ), 'wpef_sub_trash_' . $id ) ) . '" class="button">' . esc_html__( 'ゴミ箱へ', 'wp-entry-form' ) . '</a> ';
		echo '<a href="' . esc_url( wp_nonce_url( self::page_url( array( 'wpef_action' => 'delete', 'submission' => $id ) ), 'wpef_sub_delete_' . $id ) ) . '" class="button" style="color:#b32d2e;" onclick="return confirm(\'' . esc_js( __( '完全に削除しますか？', 'wp-entry-form' ) ) . '\');">' . esc_html__( '完全に削除', 'wp-entry-form' ) . '</a>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * CSV エクスポート URL（nonce 付き）。
	 *
	 * @param int    $form_id フォーム ID（0 で全フォーム）。
	 * @param string $status  ステータス絞り込み。
	 * @return string
	 */
	private static function csv_url( $form_id = 0, $status = '' ) {
		$url = add_query_arg(
			array_filter(
				array(
					'action'      => 'wpef_export_csv',
					'form_id'     => $form_id ? $form_id : null,
					'wpef_status' => '' !== $status ? $status : null,
				)
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'wpef_export_csv' );
	}

	/**
	 * 入力状況（フォーム別ダッシュボード）画面。
	 */
	public static function render_overview() {
		if ( ! current_user_can( WPEF_Admin::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-entry-form' ) );
		}

		$forms  = WPEF_DB::get_forms();
		$counts = WPEF_DB::status_counts_by_form();
		$last   = WPEF_DB::last_submitted_by_form();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( '入力状況', 'wp-entry-form' ) . '</h1>';
		echo ' <a href="' . esc_url( self::csv_url() ) . '" class="page-title-action">' . esc_html__( '全フォームを CSV エクスポート', 'wp-entry-form' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'フォーム', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( '状態', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( '総数', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( '未読', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( '既読', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( 'スパム', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( 'ゴミ箱', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( '最終送信', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( '操作', 'wp-entry-form' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $forms ) ) {
			echo '<tr><td colspan="9">' . esc_html__( 'フォームがありません。', 'wp-entry-form' ) . '</td></tr>';
		} else {
			foreach ( $forms as $form ) {
				$fid    = (int) $form['id'];
				$c      = isset( $counts[ $fid ] ) ? $counts[ $fid ] : array();
				$unread = isset( $c['unread'] ) ? (int) $c['unread'] : 0;
				$read   = isset( $c['read'] ) ? (int) $c['read'] : 0;
				$spam   = isset( $c['spam'] ) ? (int) $c['spam'] : 0;
				$trash  = isset( $c['trash'] ) ? (int) $c['trash'] : 0;
				$total  = $unread + $read + $spam;

				$list_url = self::page_url( array( 'form_id' => $fid ) );
				$edit_url = WPEF_Admin::page_url( array( 'action' => 'edit', 'form_id' => $fid ) );

				$last_ts = isset( $last[ $fid ] ) ? strtotime( $last[ $fid ] . ' UTC' ) : false;

				echo '<tr>';
				echo '<td><a href="' . esc_url( $list_url ) . '"><strong>' . esc_html( $form['title'] ) . '</strong></a><br /><span class="description"><a href="' . esc_url( $edit_url ) . '">' . esc_html__( '編集', 'wp-entry-form' ) . '</a></span></td>';
				echo '<td>' . esc_html( $form['status'] ) . '</td>';
				echo '<td><a href="' . esc_url( $list_url ) . '">' . $total . '</a></td>';
				echo '<td>' . ( $unread ? '<a href="' . esc_url( self::page_url( array( 'form_id' => $fid, 'wpef_status' => 'unread' ) ) ) . '"><strong>' . $unread . '</strong></a>' : '0' ) . '</td>';
				echo '<td>' . $read . '</td>';
				echo '<td>' . $spam . '</td>';
				echo '<td>' . $trash . '</td>';
				echo '<td>' . ( $last_ts ? esc_html( wp_date( 'Y-m-d H:i', $last_ts ) ) : '&mdash;' ) . '</td>';
				echo '<td>';
				echo '<a href="' . esc_url( $list_url ) . '">' . esc_html__( '送信を見る', 'wp-entry-form' ) . '</a> | ';
				echo '<a href="' . esc_url( self::csv_url( $fid ) ) . '">' . esc_html__( 'CSV', 'wp-entry-form' ) . '</a>';
				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * CSV エクスポート（UTF-8 BOM 付き）。
	 */
	public static function export_csv() {
		if ( ! current_user_can( WPEF_Admin::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-entry-form' ) );
		}
		check_admin_referer( 'wpef_export_csv' );

		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$status  = isset( $_GET['wpef_status'] ) ? sanitize_key( wp_unslash( $_GET['wpef_status'] ) ) : '';

		$rows = WPEF_DB::get_submissions(
			array(
				'form_id' => $form_id,
				'status'  => $status,
				'number'  => 100000,
				'offset'  => 0,
			)
		);

		$form         = $form_id ? WPEF_DB::get_form( $form_id ) : null;
		$field_defs   = array();
		if ( $form && is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $raw_field ) {
				$field = WPEF_Fields::normalize_field( $raw_field );
				if ( '' !== $field['key'] && WPEF_Fields::is_input_type( $field['type'] ) && 'file' !== $field['type'] ) {
					$field_defs[ $field['key'] ] = $field;
				}
			}
		}

		$filename = 'wpef-submissions-' . ( $form_id ? $form_id . '-' : '' ) . wp_date( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		// Excel 互換のため UTF-8 BOM を先頭に。
		fwrite( $out, "\xEF\xBB\xBF" );

		// ヘッダ行。
		$header = array( __( 'ID', 'wp-entry-form' ), __( '送信日時', 'wp-entry-form' ), __( 'ステータス', 'wp-entry-form' ), __( 'IP アドレス', 'wp-entry-form' ) );
		if ( $field_defs ) {
			foreach ( $field_defs as $field ) {
				$header[] = '' !== $field['label'] ? $field['label'] : $field['key'];
			}
		} else {
			$header[] = __( 'データ(JSON)', 'wp-entry-form' );
		}
		fputcsv( $out, $header );

		$labels = WPEF_Submissions_Table::status_labels();
		foreach ( $rows as $row ) {
			$ts   = strtotime( $row['created_at'] . ' UTC' );
			$line = array(
				$row['id'],
				$ts ? wp_date( 'Y-m-d H:i:s', $ts ) : $row['created_at'],
				isset( $labels[ $row['status'] ] ) ? $labels[ $row['status'] ] : $row['status'],
				$row['ip_address'],
			);
			$data = is_array( $row['data'] ) ? $row['data'] : array();
			if ( $field_defs ) {
				foreach ( $field_defs as $key => $field ) {
					$line[] = isset( $data[ $key ] ) ? WPEF_Fields::value_to_text( $field, $data[ $key ] ) : '';
				}
			} else {
				$line[] = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
			}
			fputcsv( $out, $line );
		}

		fclose( $out );
		exit;
	}
}
