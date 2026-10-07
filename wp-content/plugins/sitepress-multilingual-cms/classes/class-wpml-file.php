<?php

class WPML_File {
	private $wp_api;

	private $filesystem;

	public function __construct( ?WPML_WP_API $wp_api = null, ?WP_Filesystem_Direct $filesystem = null ) {
		if ( ! $wp_api ) {
			$wp_api = new WPML_WP_API();
		}

		$this->wp_api = $wp_api;

		if ( ! $filesystem ) {
			$filesystem = new WP_Filesystem_Direct( null );
		}

		$this->filesystem = $filesystem;
	}

	public function fix_dir_separator( $path ) {
		$directory_separator = $this->wp_api->constant( 'DIRECTORY_SEPARATOR' );

		return ( '\\' === $directory_separator ) ? str_replace( '/', '\\', $path ) : str_replace( '\\', '/', $path );
	}

	public function get_uri_from_path( $path, $trimProtocol = true ) {
		$base = null;

		if ( $this->wp_api->defined( 'WP_CONTENT_DIR' ) && $this->wp_api->defined( 'WP_CONTENT_URL' ) ) {
			$base_path = $this->fix_dir_separator( (string) $this->wp_api->constant( 'WP_CONTENT_DIR' ) );

			if ( 0 === strpos( $path, $base_path ) ) {
				$base = array(
					'path' => $base_path,
					'uri'  => $this->wp_api->constant( 'WP_CONTENT_URL' ),
				);
			}

			if ( false === strpos( $path, $base_path ) ) {
				$base = array(
					'path' => $base_path,
					'uri'  => $this->wp_api->constant( 'WP_CONTENT_URL' ),
				);

				$wpml_plugin_folder_parts = explode(
					(string) $this->wp_api->constant( 'DIRECTORY_SEPARATOR' ),
					(string) $this->wp_api->constant( 'WPML_PLUGIN_PATH' )
				);

				if ( $wpml_plugin_folder_parts ) {
					$wpml_plugin_folder_parts = $this->pop_folder_array( 1, $wpml_plugin_folder_parts );

					$wpml_plugins_folder = implode(
						(string) $this->wp_api->constant( 'DIRECTORY_SEPARATOR' ),
						$wpml_plugin_folder_parts
					);
					$path                = str_replace(
						$wpml_plugins_folder,
						(string) $this->wp_api->constant( 'WP_PLUGIN_DIR' ),
						$path
					);
				}
			}
		}

		if ( ! $base ) {
			$base = array(
				'path' => (string) $this->wp_api->constant( 'ABSPATH' ),
				'uri'  => (string) site_url(),
			);
		}

		if ( $trimProtocol ) {
			$base['uri'] = preg_replace( '/(^https?:)/', '', (string) $base['uri'] );
		}

		$relative_path = substr( $path, strlen( $base['path'] ) );
		$relative_path = str_replace( array( '/', '\\' ), '/', $relative_path );
		$relative_path = ltrim( $relative_path, '/' );

		return trailingslashit( (string) $base['uri'] ) . $relative_path;
	}

	private function pop_folder_array( $times, $folder_array ) {
		$latest_folder = array_pop( $folder_array );
		if ( '..' === $latest_folder ) {
			return $this->pop_folder_array( $times + 1, $folder_array );
		}
		if ( '.' === $latest_folder ) {
			return $this->pop_folder_array( $times, $folder_array );
		}
		if ( $times > 1 ) {
			return $this->pop_folder_array( $times - 1, $folder_array );
		}
		return $folder_array;
	}

	public function get_relative_path( $path ) {
		return str_replace( $this->fix_dir_separator( ABSPATH ), '', $this->fix_dir_separator( $path ) );
	}

	public function get_full_path( $path ) {
		return ABSPATH . $this->get_relative_path( $path );
	}

	public function file_exists( $path ) {
		return $this->filesystem->is_readable( $this->get_full_path( $path ) );
	}

	public function get_file_modified_timestamp( $path ) {
		return $this->filesystem->mtime( $this->get_full_path( $path ) );
	}
}
