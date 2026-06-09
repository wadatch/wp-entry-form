<?php
/**
 * フォームビルダー（管理画面）。
 *
 * フォーム一覧の表示、編集画面（タイトル＋React マウント）の表示、
 * 保存・複製・削除のハンドラと、保存時のサニタイズを担う。
 * React 側はフィールド定義と設定を hidden input に JSON でシリアライズして
 * 標準 POST で送る（design.md §8）。本クラスはそれを検証・正規化して保存する。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ビルダー画面と保存処理。
 */
class WPEF_Form_Builder {

	/* ---------------------------------------------------------------------
	 * 表示
	 * ------------------------------------------------------------------- */

	/**
	 * フォーム一覧を表示する。
	 */
	public static function render_list() {
		$forms     = WPEF_DB::get_forms();
		$new_url    = WPEF_Admin::page_url( array( 'action' => 'edit' ) );
		$notice_key = isset( $_GET['wpef_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpef_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'フォーム一覧', 'wp-entry-form' ) . '</h1>';
		echo ' <a href="' . esc_url( $new_url ) . '" class="page-title-action">' . esc_html__( '新規追加', 'wp-entry-form' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		self::render_notice( $notice_key );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( 'タイトル', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( 'ステータス', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( 'ショートコード', 'wp-entry-form' ) . '</th>';
		echo '<th>' . esc_html__( '操作', 'wp-entry-form' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $forms ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'まだフォームがありません。「新規追加」から作成してください。', 'wp-entry-form' ) . '</td></tr>';
		} else {
			foreach ( $forms as $form ) {
				$edit_url = WPEF_Admin::page_url(
					array(
						'action'  => 'edit',
						'form_id' => (int) $form['id'],
					)
				);
				echo '<tr>';
				echo '<td>' . (int) $form['id'] . '</td>';
				echo '<td><a href="' . esc_url( $edit_url ) . '"><strong>' . esc_html( $form['title'] ) . '</strong></a></td>';
				echo '<td>' . esc_html( $form['status'] ) . '</td>';
				echo '<td><code>[entry_form id="' . (int) $form['id'] . '"]</code></td>';
				echo '<td>';
				echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( '編集', 'wp-entry-form' ) . '</a> | ';
				echo '<a href="' . esc_url( self::row_action_url( 'duplicate', (int) $form['id'] ) ) . '">' . esc_html__( '複製', 'wp-entry-form' ) . '</a> | ';
				echo '<a href="' . esc_url( self::row_action_url( 'delete', (int) $form['id'] ) ) . '" onclick="return confirm(\'' . esc_js( __( 'このフォームを削除しますか？', 'wp-entry-form' ) ) . '\');" style="color:#b32d2e;">' . esc_html__( '削除', 'wp-entry-form' ) . '</a>';
				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * 編集画面を表示する。
	 */
	public static function render_edit() {
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id ? WPEF_DB::get_form( $form_id ) : null;
		$title   = $form ? $form['title'] : '';
		$is_new  = ! $form;
		$notice_key = isset( $_GET['wpef_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpef_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1>' . ( $is_new ? esc_html__( '新規フォーム', 'wp-entry-form' ) : esc_html__( 'フォームを編集', 'wp-entry-form' ) ) . '</h1>';

		self::render_notice( $notice_key );

		if ( ! $is_new ) {
			echo '<p>' . esc_html__( 'ショートコード:', 'wp-entry-form' ) . ' <code>[entry_form id="' . (int) $form_id . '"]</code></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wpef_save_form" />';
		echo '<input type="hidden" name="form_id" value="' . esc_attr( $form_id ) . '" />';
		wp_nonce_field( 'wpef_save_form', 'wpef_nonce' );

		echo '<table class="form-table"><tr>';
		echo '<th scope="row"><label for="wpef-title">' . esc_html__( 'フォーム名', 'wp-entry-form' ) . '</label></th>';
		echo '<td><input type="text" id="wpef-title" name="wpef_title" class="regular-text" value="' . esc_attr( $title ) . '" required /></td>';
		echo '</tr></table>';

		// React ビルダーのマウント先。フィールド・設定は React が hidden input に書き出す。
		echo '<div id="wpef-form-builder" class="wpef-form-builder">';
		echo '<p>' . esc_html__( 'ビルダーを読み込んでいます…', 'wp-entry-form' ) . '</p>';
		echo '</div>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( '保存', 'wp-entry-form' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * 通知メッセージを表示する。
	 *
	 * @param string $key 通知キー。
	 */
	private static function render_notice( $key ) {
		$messages = array(
			'saved'      => __( 'フォームを保存しました。', 'wp-entry-form' ),
			'duplicated' => __( 'フォームを複製しました。', 'wp-entry-form' ),
			'deleted'    => __( 'フォームを削除しました。', 'wp-entry-form' ),
		);
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * 保存・行アクション
	 * ------------------------------------------------------------------- */

	/**
	 * 保存ハンドラ（admin-post）。
	 */
	public static function handle_save() {
		if ( ! current_user_can( WPEF_Admin::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-entry-form' ) );
		}
		check_admin_referer( 'wpef_save_form', 'wpef_nonce' );

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$title   = isset( $_POST['wpef_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wpef_title'] ) ) : '';

		// JSON はスラッシュを除去してからデコードする。
		$fields_raw   = isset( $_POST['wpef_fields'] ) ? json_decode( wp_unslash( $_POST['wpef_fields'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$settings_raw = isset( $_POST['wpef_settings'] ) ? json_decode( wp_unslash( $_POST['wpef_settings'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$data = array(
			'title'    => '' !== $title ? $title : __( '無題のフォーム', 'wp-entry-form' ),
			'status'   => 'active',
			'fields'   => self::sanitize_fields( is_array( $fields_raw ) ? $fields_raw : array() ),
			'settings' => self::sanitize_settings( is_array( $settings_raw ) ? $settings_raw : array() ),
		);

		if ( $form_id ) {
			WPEF_DB::update_form( $form_id, $data );
		} else {
			$form_id = WPEF_DB::insert_form( $data );
		}

		wp_safe_redirect(
			WPEF_Admin::page_url(
				array(
					'action'      => 'edit',
					'form_id'     => (int) $form_id,
					'wpef_notice' => 'saved',
				)
			)
		);
		exit;
	}

	/**
	 * 複製・削除ハンドラ（admin-post）。
	 */
	public static function handle_row_action() {
		if ( ! current_user_can( WPEF_Admin::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-entry-form' ) );
		}
		$do      = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		check_admin_referer( 'wpef_row_' . $do . '_' . $form_id );

		$notice = '';
		if ( 'delete' === $do && $form_id ) {
			WPEF_DB::delete_form( $form_id );
			$notice = 'deleted';
		} elseif ( 'duplicate' === $do && $form_id ) {
			$src = WPEF_DB::get_form( $form_id );
			if ( $src ) {
				WPEF_DB::insert_form(
					array(
						/* translators: %s: 元フォーム名 */
						'title'    => sprintf( __( '%s のコピー', 'wp-entry-form' ), $src['title'] ),
						'status'   => 'active',
						'fields'   => $src['fields'],
						'settings' => $src['settings'],
					)
				);
				$notice = 'duplicated';
			}
		}

		wp_safe_redirect( WPEF_Admin::page_url( array( 'wpef_notice' => $notice ) ) );
		exit;
	}

	/**
	 * 行アクション URL（nonce 付き）を生成する。
	 *
	 * @param string $do      duplicate|delete。
	 * @param int    $form_id フォーム ID。
	 * @return string
	 */
	private static function row_action_url( $do, $form_id ) {
		$url = add_query_arg(
			array(
				'action'  => 'wpef_form_action',
				'do'      => $do,
				'form_id' => $form_id,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'wpef_row_' . $do . '_' . $form_id );
	}

	/* ---------------------------------------------------------------------
	 * サニタイズ
	 * ------------------------------------------------------------------- */

	/**
	 * フィールド定義配列をサニタイズ・正規化する。
	 *
	 * @param array $raw_fields React から来た生の配列。
	 * @return array
	 */
	public static function sanitize_fields( $raw_fields ) {
		$clean = array();
		$used_keys = array();
		$index     = 0;

		foreach ( $raw_fields as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$type = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : '';
			if ( ! WPEF_Fields::type_exists( $type ) ) {
				continue;
			}

			$field = array(
				'type'  => $type,
				'label' => isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : '',
				'width' => self::sanitize_width( isset( $raw['width'] ) ? $raw['width'] : 'full' ),
			);

			// 表示専用要素はキー不要。
			if ( WPEF_Fields::is_input_type( $type ) ) {
				$key = isset( $raw['key'] ) ? sanitize_key( $raw['key'] ) : '';
				if ( '' === $key ) {
					$key = sanitize_key( $field['label'] );
				}
				if ( '' === $key ) {
					$key = 'field_' . $index;
				}
				// 一意化。
				$base = $key;
				$n    = 2;
				while ( in_array( $key, $used_keys, true ) ) {
					$key = $base . '_' . $n;
					$n++;
				}
				$used_keys[] = $key;

				$field['key']         = $key;
				$field['required']    = ! empty( $raw['required'] );
				$field['placeholder'] = isset( $raw['placeholder'] ) ? sanitize_text_field( $raw['placeholder'] ) : '';
				$field['help']        = isset( $raw['help'] ) ? sanitize_text_field( $raw['help'] ) : '';
				$field['default']     = isset( $raw['default'] ) ? sanitize_text_field( $raw['default'] ) : '';

				if ( WPEF_Fields::has_options( $type ) ) {
					$field['options'] = self::sanitize_options( isset( $raw['options'] ) ? $raw['options'] : array() );
				}

				$field['validation'] = self::sanitize_validation( isset( $raw['validation'] ) ? $raw['validation'] : array() );

				if ( 'file' === $type ) {
					$field['file'] = self::sanitize_file_rules( isset( $raw['file'] ) ? $raw['file'] : array() );
				}
			}

			$clean[] = $field;
			$index++;
		}

		return $clean;
	}

	/**
	 * 横幅（グリッドの列幅）をサニタイズする。
	 *
	 * @param string $width 生の値。
	 * @return string full / two_thirds / half / third / quarter。
	 */
	private static function sanitize_width( $width ) {
		$allowed = array( 'full', 'two_thirds', 'half', 'third', 'quarter' );
		$width   = is_string( $width ) ? $width : 'full';
		return in_array( $width, $allowed, true ) ? $width : 'full';
	}

	/**
	 * 選択肢をサニタイズする。
	 *
	 * @param mixed $options 生の選択肢配列。
	 * @return array
	 */
	private static function sanitize_options( $options ) {
		$out = array();
		if ( ! is_array( $options ) ) {
			return $out;
		}
		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$label = isset( $opt['label'] ) ? sanitize_text_field( $opt['label'] ) : '';
			$value = isset( $opt['value'] ) && '' !== $opt['value'] ? sanitize_text_field( $opt['value'] ) : $label;
			if ( '' === $label && '' === $value ) {
				continue;
			}
			$out[] = array(
				'label' => '' !== $label ? $label : $value,
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * validation オブジェクトをサニタイズする。
	 *
	 * @param mixed $validation 生の値。
	 * @return array
	 */
	private static function sanitize_validation( $validation ) {
		$out = array();
		if ( ! is_array( $validation ) ) {
			return $out;
		}
		if ( isset( $validation['max_length'] ) && '' !== $validation['max_length'] && null !== $validation['max_length'] ) {
			$out['max_length'] = absint( $validation['max_length'] );
		}
		if ( isset( $validation['min'] ) && '' !== $validation['min'] && null !== $validation['min'] && is_numeric( $validation['min'] ) ) {
			$out['min'] = $validation['min'] + 0;
		}
		if ( isset( $validation['max'] ) && '' !== $validation['max'] && null !== $validation['max'] && is_numeric( $validation['max'] ) ) {
			$out['max'] = $validation['max'] + 0;
		}
		return $out;
	}

	/**
	 * ファイルルールをサニタイズする。
	 *
	 * @param mixed $file 生の値。
	 * @return array
	 */
	private static function sanitize_file_rules( $file ) {
		$out = array(
			'max_size_mb' => 5,
			'accept'      => array(),
		);
		if ( ! is_array( $file ) ) {
			return $out;
		}
		if ( isset( $file['max_size_mb'] ) && is_numeric( $file['max_size_mb'] ) ) {
			$out['max_size_mb'] = max( 1, absint( $file['max_size_mb'] ) );
		}
		if ( isset( $file['accept'] ) && is_array( $file['accept'] ) ) {
			$out['accept'] = array_values( array_filter( array_map( 'sanitize_key', $file['accept'] ) ) );
		}
		return $out;
	}

	/**
	 * フォーム設定をサニタイズする。
	 *
	 * @param array $raw 生の設定。
	 * @return array
	 */
	public static function sanitize_settings( $raw ) {
		$messages = isset( $raw['messages'] ) && is_array( $raw['messages'] ) ? $raw['messages'] : array();
		$admin    = isset( $raw['admin_notification'] ) && is_array( $raw['admin_notification'] ) ? $raw['admin_notification'] : array();
		$auto     = isset( $raw['autoresponder'] ) && is_array( $raw['autoresponder'] ) ? $raw['autoresponder'] : array();
		$spam     = isset( $raw['spam'] ) && is_array( $raw['spam'] ) ? $raw['spam'] : array();
		$rate     = isset( $spam['rate_limit'] ) && is_array( $spam['rate_limit'] ) ? $spam['rate_limit'] : array();

		// 管理者通知の宛先（カンマ/配列の両対応）。
		$to_raw = isset( $admin['to'] ) ? $admin['to'] : array();
		if ( is_string( $to_raw ) ) {
			$to_raw = preg_split( '/[,\s]+/', $to_raw );
		}
		$to = array();
		foreach ( (array) $to_raw as $addr ) {
			$addr = sanitize_email( $addr );
			if ( $addr ) {
				$to[] = $addr;
			}
		}

		return array(
			'confirmation_screen' => ! empty( $raw['confirmation_screen'] ),
			'messages'            => array(
				'success'        => isset( $messages['success'] ) ? sanitize_text_field( $messages['success'] ) : __( 'ご応募ありがとうございました。', 'wp-entry-form' ),
				'submit_button'  => isset( $messages['submit_button'] ) ? sanitize_text_field( $messages['submit_button'] ) : __( '送信する', 'wp-entry-form' ),
				'confirm_button' => isset( $messages['confirm_button'] ) ? sanitize_text_field( $messages['confirm_button'] ) : __( '入力内容を確認', 'wp-entry-form' ),
				'back_button'    => isset( $messages['back_button'] ) ? sanitize_text_field( $messages['back_button'] ) : __( '戻る', 'wp-entry-form' ),
			),
			'redirect_url'        => isset( $raw['redirect_url'] ) ? esc_url_raw( $raw['redirect_url'] ) : '',
			'admin_notification'  => array(
				'enabled' => ! empty( $admin['enabled'] ),
				'to'      => $to,
				'subject' => isset( $admin['subject'] ) ? sanitize_text_field( $admin['subject'] ) : '',
				'body'    => isset( $admin['body'] ) ? sanitize_textarea_field( $admin['body'] ) : '',
			),
			'autoresponder'       => array(
				'enabled'    => ! empty( $auto['enabled'] ),
				'to_field'   => isset( $auto['to_field'] ) ? sanitize_key( $auto['to_field'] ) : '',
				'from_name'  => isset( $auto['from_name'] ) ? sanitize_text_field( $auto['from_name'] ) : '',
				'from_email' => isset( $auto['from_email'] ) ? sanitize_email( $auto['from_email'] ) : '',
				'subject'    => isset( $auto['subject'] ) ? sanitize_text_field( $auto['subject'] ) : '',
				'body'       => isset( $auto['body'] ) ? sanitize_textarea_field( $auto['body'] ) : '',
			),
			'spam'                => array(
				'honeypot'   => ! isset( $spam['honeypot'] ) || ! empty( $spam['honeypot'] ),
				'rate_limit' => array(
					'enabled' => ! empty( $rate['enabled'] ),
					'max'     => isset( $rate['max'] ) ? max( 1, absint( $rate['max'] ) ) : 3,
					'window'  => isset( $rate['window'] ) ? max( 1, absint( $rate['window'] ) ) : 600,
				),
			),
		);
	}
}
