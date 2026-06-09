<?php
/**
 * 添付ファイルの保存・検証・配信・削除。
 *
 * アップロードは wp-content/uploads/wp-entry-form/ 配下にランダム名で保存し、
 * index.html と .htaccess を置いて直接アクセスを抑止する。ダウンロードは管理画面の
 * 権限付きエンドポイント経由でのみ配信する（design.md §7）。
 * 確認画面ありフォームでは、入力時に保存したファイルのメタをトークンで持ち越し、
 * 送信確定時に submission へ紐づける。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 添付ファイル処理。
 */
class WPEF_Files {

	/**
	 * uploads 配下のサブディレクトリ名。
	 */
	const SUBDIR = 'wp-entry-form';

	/**
	 * 持ち越し用 transient の接頭辞。
	 */
	const STASH_PREFIX = 'wpef_files_';

	/**
	 * フック登録。
	 */
	public static function init() {
		add_action( 'admin_post_wpef_download_file', array( __CLASS__, 'handle_download' ) );
		// 送信削除時に添付も消す（PR6a の apply_action が発火）。
		add_action( 'wpef_delete_submission', array( __CLASS__, 'delete_for_submission' ) );
	}

	/**
	 * 保存先ディレクトリ情報を返し、無ければ作成して保護ファイルを置く。
	 *
	 * @return array|null array( 'dir' => 絶対パス, 'subpath' => 'wp-entry-form/YYYY/MM' ) または null。
	 */
	private static function ensure_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		$base = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			// 直接アクセス抑止。
			@file_put_contents( $base . '/index.html', '' ); // phpcs:ignore
			@file_put_contents( $base . '/.htaccess', "Order allow,deny\nDeny from all\n" ); // phpcs:ignore
		}

		$sub  = self::SUBDIR . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
		$dir  = trailingslashit( $uploads['basedir'] ) . $sub;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return array(
			'dir'     => $dir,
			'subpath' => $sub,
		);
	}

	/**
	 * フォームのファイル項目について、検証して（合格分のみ）保存する。
	 *
	 * @param array $form フォーム。
	 * @return array array( 'errors' => array(key=>msg), 'files' => array(meta...) )。
	 */
	public static function process( $form ) {
		$errors = array();
		$files  = array();

		foreach ( (array) $form['fields'] as $raw_field ) {
			$field = WPEF_Fields::normalize_field( $raw_field );
			if ( 'file' !== $field['type'] || '' === $field['key'] ) {
				continue;
			}
			$key    = $field['key'];
			$upload = self::extract_file( $key );

			// 未アップロード。
			if ( null === $upload ) {
				if ( $field['required'] ) {
					$errors[ $key ] = __( 'ファイルを選択してください。', 'wp-entry-form' );
				}
				continue;
			}

			$error = self::validate( $field, $upload );
			if ( null !== $error ) {
				$errors[ $key ] = $error;
				continue;
			}

			$meta = self::store( $field, $upload );
			if ( null === $meta ) {
				$errors[ $key ] = __( 'ファイルの保存に失敗しました。', 'wp-entry-form' );
				continue;
			}
			$files[] = $meta;
		}

		return array(
			'errors' => $errors,
			'files'  => $files,
		);
	}

	/**
	 * $_FILES から wpef[key] のファイルを取り出す（未選択なら null）。
	 *
	 * @param string $key フィールドキー。
	 * @return array|null name/type/tmp_name/error/size。
	 */
	private static function extract_file( $key ) {
		if ( empty( $_FILES['wpef'] ) || ! isset( $_FILES['wpef']['name'][ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}
		$error = isset( $_FILES['wpef']['error'][ $key ] ) ? (int) $_FILES['wpef']['error'][ $key ] : UPLOAD_ERR_NO_FILE; // phpcs:ignore
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return null;
		}
		return array(
			'name'     => isset( $_FILES['wpef']['name'][ $key ] ) ? sanitize_file_name( wp_unslash( $_FILES['wpef']['name'][ $key ] ) ) : '', // phpcs:ignore
			'type'     => isset( $_FILES['wpef']['type'][ $key ] ) ? sanitize_text_field( wp_unslash( $_FILES['wpef']['type'][ $key ] ) ) : '', // phpcs:ignore
			'tmp_name' => isset( $_FILES['wpef']['tmp_name'][ $key ] ) ? $_FILES['wpef']['tmp_name'][ $key ] : '', // phpcs:ignore
			'error'    => $error,
			'size'     => isset( $_FILES['wpef']['size'][ $key ] ) ? (int) $_FILES['wpef']['size'][ $key ] : 0,
		);
	}

	/**
	 * アップロードを検証する（エラー文言を返す。問題なければ null）。
	 *
	 * @param array $field  正規化済みフィールド。
	 * @param array $upload ファイル配列。
	 * @return string|null
	 */
	private static function validate( $field, $upload ) {
		if ( UPLOAD_ERR_OK !== $upload['error'] ) {
			return __( 'ファイルのアップロードに失敗しました。', 'wp-entry-form' );
		}

		$rules    = isset( $field['file'] ) && is_array( $field['file'] ) ? $field['file'] : array();
		$max_mb   = isset( $rules['max_size_mb'] ) ? max( 1, (int) $rules['max_size_mb'] ) : 5;
		$accept   = isset( $rules['accept'] ) && is_array( $rules['accept'] ) ? array_map( 'strtolower', $rules['accept'] ) : array();

		if ( $upload['size'] > $max_mb * MB_IN_BYTES ) {
			/* translators: %d: 最大MB */
			return sprintf( __( 'ファイルサイズは %d MB 以内にしてください。', 'wp-entry-form' ), $max_mb );
		}

		// 拡張子と実体の整合を確認（拡張子偽装を防ぐ）。
		$check = wp_check_filetype_and_ext( $upload['tmp_name'], $upload['name'] );
		$ext   = $check['ext'] ? strtolower( $check['ext'] ) : strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) );

		if ( ! $ext || ( $accept && ! in_array( $ext, $accept, true ) ) ) {
			/* translators: %s: 許可拡張子 */
			return sprintf( __( '許可されていないファイル形式です（%s）。', 'wp-entry-form' ), implode( ', ', $accept ) );
		}
		return null;
	}

	/**
	 * 検証済みファイルをランダム名で保存し、メタを返す（失敗で null）。
	 *
	 * @param array $field  フィールド。
	 * @param array $upload ファイル配列。
	 * @return array|null field_key/original_name/stored_path/mime_type/file_size。
	 */
	private static function store( $field, $upload ) {
		$target = self::ensure_dir();
		if ( null === $target ) {
			return null;
		}

		$ext      = strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) );
		$filename = wp_generate_password( 24, false, false ) . ( $ext ? '.' . sanitize_key( $ext ) : '' );
		$dest     = trailingslashit( $target['dir'] ) . $filename;

		if ( ! @move_uploaded_file( $upload['tmp_name'], $dest ) ) { // phpcs:ignore
			return null;
		}
		@chmod( $dest, 0644 ); // phpcs:ignore

		return array(
			'field_key'     => $field['key'],
			'original_name' => $upload['name'],
			'stored_path'   => $target['subpath'] . '/' . $filename,
			'mime_type'     => $upload['type'],
			'file_size'     => (int) $upload['size'],
		);
	}

	/**
	 * メタ配列を submission に紐づけて wpef_files に保存する。
	 *
	 * @param int   $submission_id 送信 ID。
	 * @param array $metas         メタ配列。
	 */
	public static function attach_to_submission( $submission_id, $metas ) {
		foreach ( (array) $metas as $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['stored_path'] ) ) {
				continue;
			}
			WPEF_DB::insert_file(
				array(
					'submission_id' => $submission_id,
					'field_key'     => isset( $meta['field_key'] ) ? $meta['field_key'] : '',
					'original_name' => isset( $meta['original_name'] ) ? $meta['original_name'] : '',
					'stored_path'   => $meta['stored_path'],
					'mime_type'     => isset( $meta['mime_type'] ) ? $meta['mime_type'] : '',
					'file_size'     => isset( $meta['file_size'] ) ? (int) $meta['file_size'] : 0,
				)
			);
		}
	}

	/**
	 * メタを transient に保存し、持ち越しトークンを返す（確認画面用）。
	 *
	 * @param array $metas メタ配列。
	 * @return string トークン（空なら '' ）。
	 */
	public static function stash( $metas ) {
		if ( empty( $metas ) ) {
			return '';
		}
		$token = wp_generate_password( 24, false, false );
		set_transient( self::STASH_PREFIX . $token, $metas, 15 * MINUTE_IN_SECONDS );
		return $token;
	}

	/**
	 * 持ち越したメタを取り出して破棄する。
	 *
	 * @param string $token トークン。
	 * @return array メタ配列。
	 */
	public static function take( $token ) {
		if ( '' === $token ) {
			return array();
		}
		$metas = get_transient( self::STASH_PREFIX . $token );
		delete_transient( self::STASH_PREFIX . $token );
		return is_array( $metas ) ? $metas : array();
	}

	/**
	 * 持ち越し中のファイルを破棄する（確認画面で「戻る」した場合など）。
	 *
	 * @param string $token トークン。
	 */
	public static function discard( $token ) {
		self::cleanup( self::take( $token ) );
	}

	/**
	 * 手元のメタ配列が指すファイルをディスクから削除する（保存後に検証エラーで破棄する等）。
	 *
	 * @param array $metas メタ配列。
	 */
	public static function cleanup( $metas ) {
		foreach ( (array) $metas as $meta ) {
			if ( ! empty( $meta['stored_path'] ) ) {
				self::unlink_stored( $meta['stored_path'] );
			}
		}
	}

	/**
	 * 送信に紐づく添付を（ディスク・DB とも）削除する。
	 *
	 * @param int $submission_id 送信 ID。
	 */
	public static function delete_for_submission( $submission_id ) {
		$files = WPEF_DB::get_files_for_submission( $submission_id );
		foreach ( $files as $file ) {
			if ( ! empty( $file['stored_path'] ) ) {
				self::unlink_stored( $file['stored_path'] );
			}
		}
		WPEF_DB::delete_files_for_submission( $submission_id );
	}

	/**
	 * 相対保存パスのファイルを削除する（uploads 配下に限定）。
	 *
	 * @param string $stored_path 相対パス。
	 */
	private static function unlink_stored( $stored_path ) {
		$path = self::absolute_path( $stored_path );
		if ( $path && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore
		}
	}

	/**
	 * 相対保存パスを uploads 配下の絶対パスに解決する（外部参照を防ぐ）。
	 *
	 * @param string $stored_path 相対パス。
	 * @return string|null
	 */
	private static function absolute_path( $stored_path ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$base = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$path = wp_normalize_path( $base . ltrim( $stored_path, '/' ) );
		// uploads 配下であることを保証。
		if ( 0 !== strpos( $path, $base ) || false !== strpos( $stored_path, '..' ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * ダウンロード URL（nonce 付き）。
	 *
	 * @param int $file_id ファイル ID。
	 * @return string
	 */
	public static function download_url( $file_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wpef_download_file',
					'file'   => (int) $file_id,
				),
				admin_url( 'admin-post.php' )
			),
			'wpef_download_file_' . (int) $file_id
		);
	}

	/**
	 * 権限付きダウンロードを処理する。
	 */
	public static function handle_download() {
		$file_id = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : 0;
		check_admin_referer( 'wpef_download_file_' . $file_id );
		if ( ! current_user_can( WPEF_Admin::capability() ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-entry-form' ) );
		}

		$file = WPEF_DB::get_file( $file_id );
		if ( ! $file ) {
			wp_die( esc_html__( 'ファイルが見つかりません。', 'wp-entry-form' ) );
		}
		$path = self::absolute_path( $file['stored_path'] );
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'ファイルが見つかりません。', 'wp-entry-form' ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $file['mime_type'] ? $file['mime_type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['original_name'] ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}
}
