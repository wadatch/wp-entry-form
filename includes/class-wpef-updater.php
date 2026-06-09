<?php
/**
 * GitHub Releases を更新元にする自前アップデータ。
 *
 * WordPress の更新チェック（update_plugins トランジェント）に、GitHub の最新リリース情報を
 * 注入する。リリースに添付された配布 zip（トップに wp-entry-form/ を持つ）を更新パッケージに
 * 使うため、管理画面の「更新」「自動更新を有効化」がそのまま機能する。
 * GitHub API のレート制限を避けるため、最新リリース情報は transient にキャッシュする。
 *
 * @package WP_Entry_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 自己更新（GitHub Releases）。
 */
class WPEF_Updater {

	/**
	 * GitHub リポジトリ（owner/repo）。
	 */
	const REPO = 'wadatch/wp-entry-form';

	/**
	 * 最新リリースのキャッシュキー。
	 */
	const CACHE_KEY = 'wpef_latest_release';

	/**
	 * キャッシュ時間（秒）。
	 */
	const CACHE_TTL = 21600; // 6 時間。

	/**
	 * フック登録。
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		// 更新完了時はキャッシュを破棄。
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * プラグインのスラッグ（ディレクトリ名）。
	 *
	 * @return string
	 */
	private static function slug() {
		return dirname( WPEF_BASENAME );
	}

	/**
	 * 更新チェックに最新リリースを注入する。
	 *
	 * @param mixed $transient update_plugins トランジェント。
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$latest = self::get_latest_release();
		if ( empty( $latest['version'] ) || empty( $latest['package'] ) ) {
			return $transient;
		}

		$item = array(
			'slug'        => self::slug(),
			'plugin'      => WPEF_BASENAME,
			'new_version' => $latest['version'],
			'url'         => $latest['url'],
			'package'     => $latest['package'],
		);

		if ( version_compare( $latest['version'], WPEF_VERSION, '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ WPEF_BASENAME ] = (object) $item;
		} else {
			// 最新の場合も no_update に載せておく（自動更新 UI のため）。
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ WPEF_BASENAME ] = (object) $item;
		}

		return $transient;
	}

	/**
	 * 「詳細を表示」ポップアップ用の情報を返す。
	 *
	 * @param mixed  $result 既定の結果。
	 * @param string $action アクション。
	 * @param object $args   引数（slug を含む）。
	 * @return mixed
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$latest = self::get_latest_release();
		if ( empty( $latest['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WP Entry Form',
			'slug'          => self::slug(),
			'version'       => $latest['version'],
			'author'        => '<a href="https://github.com/wadatch">wadatch</a>',
			'homepage'      => $latest['url'],
			'download_link' => $latest['package'],
			'last_updated'  => $latest['published'],
			'sections'      => array(
				'changelog' => $latest['changelog'] ? wpautop( esc_html( $latest['changelog'] ) ) : esc_html__( '変更履歴はありません。', 'wp-entry-form' ),
			),
		);
	}

	/**
	 * 最新リリース情報を取得する（キャッシュ付き）。
	 *
	 * @return array version / package / changelog / url / published（取得失敗時は空配列要素）。
	 */
	private static function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$info = array(
			'version'   => '',
			'package'   => '',
			'changelog' => '',
			'url'       => '',
			'published' => '',
		);

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wp-entry-form',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// 失敗時も短時間キャッシュして API を叩きすぎない。
			set_transient( self::CACHE_KEY, $info, HOUR_IN_SECONDS );
			return $info;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) ) {
			$info['version']   = isset( $data['tag_name'] ) ? ltrim( (string) $data['tag_name'], 'v' ) : '';
			$info['changelog'] = isset( $data['body'] ) ? (string) $data['body'] : '';
			$info['url']       = isset( $data['html_url'] ) ? (string) $data['html_url'] : '';
			$info['published'] = isset( $data['published_at'] ) ? (string) $data['published_at'] : '';

			// 配布 zip（トップに wp-entry-form/ を持つ）のアセットを探す。
			if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
				foreach ( $data['assets'] as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
						$info['package'] = (string) $asset['browser_download_url'];
						break;
					}
				}
			}
		}

		set_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
		return $info;
	}

	/**
	 * キャッシュを破棄する。
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
