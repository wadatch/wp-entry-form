<?php
/**
 * 送信一覧（WP_List_Table）。
 *
 * フォーム別フィルタ・ステータスビュー・キーワード検索・一括操作・行操作・ページングを提供する。
 * データ取得は WPEF_DB に委譲し、本クラスは表示に専念する。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * 送信一覧テーブル。
 */
class WPEF_Submissions_Table extends WP_List_Table {

	/**
	 * 対象フォーム ID（0 で全フォーム）。
	 *
	 * @var int
	 */
	private $form_id = 0;

	/**
	 * 現在のステータスビュー。
	 *
	 * @var string
	 */
	private $status = '';

	/**
	 * 検索語。
	 *
	 * @var string
	 */
	private $search = '';

	/**
	 * 単一フォーム選択時の入力項目定義（key => 正規化済みフィールド）。
	 *
	 * @var array
	 */
	private $field_defs = array();

	/**
	 * 入力項目の表示順（key の配列）。
	 *
	 * @var string[]
	 */
	private $field_order = array();

	/**
	 * コンストラクタ。
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wpef_submission',
				'plural'   => 'wpef_submissions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * ステータスのラベル。
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'unread' => __( '未読', 'wp-entry-form' ),
			'read'   => __( '既読', 'wp-entry-form' ),
			'spam'   => __( 'スパム', 'wp-entry-form' ),
			'trash'  => __( 'ゴミ箱', 'wp-entry-form' ),
		);
	}

	/**
	 * カラム定義。
	 *
	 * 単一フォーム選択時は各入力項目を列に展開する（表形式）。それ以外は要約列。
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array( 'cb' => '<input type="checkbox" />' );

		if ( ! empty( $this->field_defs ) ) {
			foreach ( $this->field_defs as $key => $field ) {
				$columns[ 'f_' . $key ] = '' !== $field['label'] ? $field['label'] : $key;
			}
		} else {
			$columns['summary'] = __( '内容', 'wp-entry-form' );
		}

		$columns['status']    = __( 'ステータス', 'wp-entry-form' );
		$columns['submitted'] = __( '送信日時', 'wp-entry-form' );
		return $columns;
	}

	/**
	 * ソート可能カラム。
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'submitted' => array( 'created_at', true ),
		);
	}

	/**
	 * 一括操作。
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'mark_read'   => __( '既読にする', 'wp-entry-form' ),
			'mark_unread' => __( '未読にする', 'wp-entry-form' ),
			'mark_spam'   => __( 'スパムにする', 'wp-entry-form' ),
			'trash'       => __( 'ゴミ箱へ', 'wp-entry-form' ),
			'delete'      => __( '完全に削除', 'wp-entry-form' ),
		);
	}

	/**
	 * ステータスビュー（件数つき）。
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts = WPEF_DB::count_by_status( $this->form_id );
		$labels = array( 'all' => __( 'すべて', 'wp-entry-form' ) ) + self::status_labels();
		$views  = array();
		foreach ( $labels as $key => $label ) {
			$url     = WPEF_Submissions::page_url(
				array_filter(
					array(
						'form_id'     => $this->form_id ? $this->form_id : null,
						'wpef_status' => 'all' === $key ? null : $key,
					)
				)
			);
			$current = ( ( '' === $this->status && 'all' === $key ) || $this->status === $key ) ? ' class="current"' : '';
			$count   = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
			$views[ $key ] = '<a href="' . esc_url( $url ) . '"' . $current . '>' . esc_html( $label ) . ' <span class="count">(' . $count . ')</span></a>';
		}
		return $views;
	}

	/**
	 * フォーム選択ドロップダウンを上部に出す。
	 *
	 * @param string $which top / bottom。
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$forms = WPEF_DB::get_forms();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="wpef-form-filter">' . esc_html__( 'フォームで絞り込み', 'wp-entry-form' ) . '</label>';
		echo '<select name="form_id" id="wpef-form-filter">';
		echo '<option value="0">' . esc_html__( 'すべてのフォーム', 'wp-entry-form' ) . '</option>';
		foreach ( $forms as $form ) {
			echo '<option value="' . (int) $form['id'] . '"' . selected( $this->form_id, (int) $form['id'], false ) . '>' . esc_html( $form['title'] ) . '</option>';
		}
		echo '</select>';
		submit_button( __( '絞り込み', 'wp-entry-form' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * チェックボックス列。
	 *
	 * @param array $item 行。
	 * @return string
	 */
	public function column_cb( $item ) {
		return '<input type="checkbox" name="submission[]" value="' . (int) $item['id'] . '" />';
	}

	/**
	 * 内容列（要約＋行操作）。
	 *
	 * @param array $item 行。
	 * @return string
	 */
	public function column_summary( $item ) {
		$view_url = WPEF_Submissions::page_url(
			array(
				'action'        => 'view',
				'submission_id' => (int) $item['id'],
			)
		);

		$summary = $this->summarize( $item );
		$out     = '<strong><a href="' . esc_url( $view_url ) . '">' . esc_html( $summary ) . '</a></strong>';

		return $out . $this->row_actions( $this->build_row_actions( $item ) );
	}

	/**
	 * 行操作リンクの配列を組み立てる。
	 *
	 * @param array $item 行。
	 * @return array
	 */
	private function build_row_actions( $item ) {
		$view_url = WPEF_Submissions::page_url(
			array(
				'action'        => 'view',
				'submission_id' => (int) $item['id'],
			)
		);
		$actions = array(
			'view' => '<a href="' . esc_url( $view_url ) . '">' . esc_html__( '詳細', 'wp-entry-form' ) . '</a>',
		);
		if ( 'trash' === $item['status'] ) {
			$actions['restore'] = '<a href="' . esc_url( $this->row_action_url( 'restore', (int) $item['id'] ) ) . '">' . esc_html__( '復元', 'wp-entry-form' ) . '</a>';
			$actions['delete']  = '<a href="' . esc_url( $this->row_action_url( 'delete', (int) $item['id'] ) ) . '" style="color:#b32d2e;">' . esc_html__( '完全に削除', 'wp-entry-form' ) . '</a>';
		} else {
			$actions['trash'] = '<a href="' . esc_url( $this->row_action_url( 'trash', (int) $item['id'] ) ) . '">' . esc_html__( 'ゴミ箱へ', 'wp-entry-form' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * 入力項目を展開した列（f_{key}）の値。先頭列には詳細リンクと行操作を付ける。
	 *
	 * @param array  $item        行。
	 * @param string $column_name カラム名。
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		if ( 0 !== strpos( $column_name, 'f_' ) ) {
			return '';
		}
		$key   = substr( $column_name, 2 );
		$field = isset( $this->field_defs[ $key ] ) ? $this->field_defs[ $key ] : array( 'type' => 'text' );
		$data  = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : array();
		$text  = isset( $data[ $key ] ) ? WPEF_Fields::value_to_text( $field, $data[ $key ] ) : '';
		$text  = mb_strimwidth( (string) $text, 0, 60, '…' );

		// 先頭の項目列に詳細リンクと行操作を寄せる。
		if ( ! empty( $this->field_order ) && $key === $this->field_order[0] ) {
			$view_url = WPEF_Submissions::page_url(
				array(
					'action'        => 'view',
					'submission_id' => (int) $item['id'],
				)
			);
			/* translators: %d: 送信 ID */
			$display = '' !== $text ? $text : sprintf( __( '送信 #%d', 'wp-entry-form' ), (int) $item['id'] );
			return '<strong><a href="' . esc_url( $view_url ) . '">' . esc_html( $display ) . '</a></strong>' . $this->row_actions( $this->build_row_actions( $item ) );
		}

		return esc_html( $text );
	}

	/**
	 * ステータス列。
	 *
	 * @param array $item 行。
	 * @return string
	 */
	public function column_status( $item ) {
		$labels = self::status_labels();
		$status = isset( $item['status'] ) ? $item['status'] : '';
		return esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status );
	}

	/**
	 * 送信日時列（サイトのタイムゾーンで表示）。
	 *
	 * @param array $item 行。
	 * @return string
	 */
	public function column_submitted( $item ) {
		$ts = isset( $item['created_at'] ) ? strtotime( $item['created_at'] . ' UTC' ) : false;
		if ( ! $ts ) {
			return '&mdash;';
		}
		return esc_html( wp_date( 'Y-m-d H:i', $ts ) );
	}

	/**
	 * 行データの要約（先頭フィールド数件）。
	 *
	 * @param array $item 行。
	 * @return string
	 */
	private function summarize( $item ) {
		$data  = isset( $item['data'] ) && is_array( $item['data'] ) ? $item['data'] : array();
		$parts = array();
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$value = implode( '、', $value );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? '✓' : '';
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
			if ( count( $parts ) >= 3 ) {
				break;
			}
		}
		$summary = implode( ' / ', $parts );
		if ( '' === $summary ) {
			/* translators: %d: 送信 ID */
			return sprintf( __( '送信 #%d', 'wp-entry-form' ), (int) $item['id'] );
		}
		return mb_strimwidth( $summary, 0, 80, '…' );
	}

	/**
	 * 行操作 URL（nonce 付き）。
	 *
	 * @param string $action restore / trash / delete。
	 * @param int    $id     送信 ID。
	 * @return string
	 */
	private function row_action_url( $action, $id ) {
		$url = WPEF_Submissions::page_url(
			array(
				'wpef_action' => $action,
				'submission'  => $id,
				'form_id'     => $this->form_id ? $this->form_id : null,
				'wpef_status' => '' !== $this->status ? $this->status : null,
			)
		);
		return wp_nonce_url( $url, 'wpef_sub_' . $action . '_' . $id );
	}

	/**
	 * データ取得とページング設定。
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$this->form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$this->status  = isset( $_GET['wpef_status'] ) ? sanitize_key( wp_unslash( $_GET['wpef_status'] ) ) : '';
		$this->search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby       = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order         = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		$paged         = $this->get_pagenum();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// 単一フォーム選択時は、その入力項目を列に展開する。
		$this->field_defs  = array();
		$this->field_order = array();
		if ( $this->form_id ) {
			$form = WPEF_DB::get_form( $this->form_id );
			if ( $form && is_array( $form['fields'] ) ) {
				foreach ( $form['fields'] as $raw_field ) {
					$field = WPEF_Fields::normalize_field( $raw_field );
					if ( '' !== $field['key'] && WPEF_Fields::is_input_type( $field['type'] ) && 'file' !== $field['type'] ) {
						$this->field_defs[ $field['key'] ] = $field;
						$this->field_order[]               = $field['key'];
					}
				}
			}
		}

		$per_page = 20;
		$args     = array(
			'form_id' => $this->form_id,
			'status'  => $this->status,
			'search'  => $this->search,
			'orderby' => $orderby,
			'order'   => $order,
			'number'  => $per_page,
			'offset'  => ( $paged - 1 ) * $per_page,
		);

		$total = WPEF_DB::count_submissions( $args );
		$this->items = WPEF_DB::get_submissions( $args );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * 該当なしの表示。
	 */
	public function no_items() {
		esc_html_e( '送信データがありません。', 'wp-entry-form' );
	}
}
