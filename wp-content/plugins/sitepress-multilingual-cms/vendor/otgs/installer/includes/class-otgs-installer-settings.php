<?php

namespace OTGS\Installer;

class Settings {

	private static $settings;

	private static $settings_common;

	private static $common_keys = [
		'subscription',
		'channel',
		'ts_info',
		'last_successful_subscription_fetch',
		'using_products_fallback'
	];

	public static function load() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		$settings = \get_option( 'wp_installer_settings' );

		if ( is_array( $settings ) || empty( $settings ) ) {
			return $settings;
		}

		$settings = self::uncompress( $settings );
		$settings = self::pre_1_8_clean_up( $settings );

		self::$settings = $settings;
		return self::$settings;
	}

	private static function load_common() {
		if ( null !== self::$settings_common ) {
			return self::$settings_common;
		}

		$common = \get_option( 'wp_installer_settings_common' );

		if ( is_array( $common ) ) {
			return $common;
		}

		if ( ! $common ) {
			$settings = self::load();
			if ( ! empty( $settings ) ) {
				self::save( $settings );
				self::$settings_common = $settings;
				return self::$settings_common;
			}

			return;
		}

		self::$settings_common = self::uncompress( $common );
		return self::$settings_common;
	}



	public static function load_subscriptions() {
		return self::load_common();
	}

	public static function load_channels() {
		return self::load_common();
	}

	public static function load_ts_info() {
		return self::load_common();
	}

	public static function is_using_products_fallback( $repository_id ) {
		$common = self::load_common();

		if ( isset( $common['repositories'][ $repository_id ]['using_products_fallback'] ) ) {
			return (bool) $common['repositories'][ $repository_id ]['using_products_fallback'];
		}

		return false;
	}

	public static function requires_update() {
		$last_update = \get_option( 'wp_installer_last_update', false );

		if ( ! $last_update || ( time() - $last_update ) > 86400 ) {
			\update_option( 'wp_installer_last_update', time(), false );
			return true;
		}

		return false;
	}


	public static function save( &$settings ) {
		$settings  = self::pre_1_8_clean_up( $settings );
		$changelog = self::extract_changelog( $settings );
		$common    = self::get_common( $settings );

		self::$settings        = $settings;
		self::$settings_common = $common;

		\update_option( 'wp_installer_settings', self::compress( $settings ), false );
		\update_option( 'wp_installer_settings_common', self::compress( $common ), true );

		self::save_changelog( $changelog );
	}

	public static function is_gz_on() {
		return function_exists( 'gzuncompress' ) && function_exists( 'gzcompress' );
	}

	private static function compress( $content ) {
		$content = serialize( $content );
		if ( self::is_gz_on() ) {
			$content = gzcompress( $content );
		}
		return base64_encode( (string) $content );
	}

	private static function uncompress( $content ) {
		$content = base64_decode( $content );
		if ( self::is_gz_on() ) {
			$content = gzuncompress( $content );
		}
		return unserialize( (string) $content );
	}

	private static function get_common( $settings ) {
		$common = [ 'repositories' => [] ];
		foreach ( $settings['repositories'] as $repository_id => $repository ) {
			foreach ( self::$common_keys as $key ) {
				if ( isset( $repository[ $key ] ) ) {
					if ( ! isset( $common['repositories'][ $repository_id ] ) ) {
						$common['repositories'][ $repository_id ] = [];
					}

					$common['repositories'][ $repository_id ][ $key ] = $repository[ $key ];
				}
			}
		}
		return $common;
	}

	private static function extract_changelog( &$settings ) {
		$changelog = array();
		foreach ( $settings['repositories'] as $repository_id => $repository ) {
			foreach ( $repository['data']['downloads']['plugins'] as $slug => $download ) {
				if ( isset( $download['changelog'] ) && ! empty( $download['changelog'] ) ) {
					$changelog[ $slug ] = $download['changelog'];

					$settings['repositories'][ $repository_id ]['data']['downloads']['plugins'][ $slug ]['changelog'] = '';
				}
			}
		}
		return $changelog;
	}

	private static function save_changelog( $changelog ) {
		if ( empty( $changelog ) ) {
			return;
		}
		if ( ! file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$creds_ok = WP_Filesystem();
		if ( ! $creds_ok ) {
			return;
		}

		global $wp_filesystem;

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wpml';
		$file    = trailingslashit( $dir ) . 'changelog.txt';

		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			if ( ! $wp_filesystem->mkdir( $dir, defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755 ) ) {
				return;
			}
		}

		$wp_filesystem->put_contents(
			$file,
			self::compress( $changelog ),
			defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644
		);
	}

	public static function read_changelog() {
		if ( ! file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$creds_ok = WP_Filesystem();
		if ( ! $creds_ok ) {
			return '';
		}

		global $wp_filesystem;

		$uploads = wp_upload_dir();
		$file    = trailingslashit( $uploads['basedir'] ) . 'wpml/changelog.txt';

		if ( ! $wp_filesystem->exists( $file ) ) {
			return '';
		}

		$changelog = $wp_filesystem->get_contents( $file );
		if ( false === $changelog ) {
			return '';
		}

		return self::uncompress( $changelog );
	}


	public static function get_changelog_for_plugin( $slug ) {
		$changelogs = self::read_changelog();
		if ( is_array( $changelogs ) && isset( $changelogs[ $slug ] ) ) {
			return $changelogs[ $slug ];
		}
		return '';
	}


	private static function pre_1_8_clean_up( $settings ) {
		if ( empty( $settings['_pre_1_8_clean_up'] ) ) {
			$settings['_pre_1_8_clean_up'] = true;
			foreach ( $settings['repositories'] as $repository_id => $repository ) {
				foreach ( $repository['data']['downloads']['plugins'] as $slug => $download ) {
					if ( ! isset( $download['channel'] ) ) {
						$settings['repositories'][ $repository_id ]['data']['downloads']['plugins'][ $slug ]['channel'] = '';
					}
				}
			}
		}

		return $settings;
	}
}
