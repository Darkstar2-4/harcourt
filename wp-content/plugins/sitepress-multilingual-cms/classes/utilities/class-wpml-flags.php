<?php
use WPML\FP\Obj;
use WPML\TM\Settings\Flags\Options;

class WPML_Flags {
	private $cache;

	private $wpdb;

	private $filesystem;

	public function __construct( $wpdb, icl_cache $cache, WP_Filesystem_Direct $filesystem ) {
		$this->wpdb       = $wpdb;
		$this->cache      = $cache;
		$this->filesystem = $filesystem;
	}

	public function get_flag( $lang_code ) {
		$flag = $this->cache->get( $lang_code );

		if ( ! $flag ) {
			$flag = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT flag, from_template
                                                    FROM {$this->wpdb->prefix}icl_flags
                                                    WHERE lang_code=%s",
					$lang_code
				)
			);

			$this->cache->set( $lang_code, $flag );
		}

		return $flag;
	}

	public function get_flag_url( $lang_code ) {
		$flag = $this->get_flag( $lang_code );
		if ( ! $flag ) {
			return '';
		}

		$path = '';
		if ( $flag->from_template ) {
			$wp_upload_dir = wp_upload_dir();
			$base_path     = $wp_upload_dir['basedir'] . '/';
			$base_url      = $wp_upload_dir['baseurl'];
			$path          = 'flags/';
		} else {
			$base_path = self::get_wpml_flags_directory();
			$base_url  = self::get_wpml_flags_url();
		}
		$path .= $flag->flag;

		if ( $this->flag_file_exists( $base_path . $path ) ) {
			return $this->append_path_to_url( $base_url, $path );
		}

		return '';
	}

	public function get_flag_image( $lang_code, $size = [], $fallback_text = '', $css_classes = [] ) {
		$url = $this->get_flag_url( $lang_code );

		if ( ! $url ) {
			return $fallback_text;
		}

		$class_attribute = is_array( $css_classes ) && ! empty( $css_classes )
			? ' class="' . implode( ' ',  $css_classes ) . '"'
			: '';

		return '<img' . $class_attribute . '
					width="' . Obj::propOr( 18, 0, $size ) . '"
					height="' . Obj::propOr( 12, 1, $size ) . '"
					src="' . esc_url( $url ) . '"
					alt="' . esc_attr( sprintf( __( 'Flag for %s', 'sitepress' ), $lang_code ) ) . '"
				/>';
	}

	public function clear() {
		$this->cache->clear();
	}

	public function get_wpml_flags( $allowed_file_types = null ) {
		if ( null === $allowed_file_types ) {
			$allowed_file_types = array( 'gif', 'jpeg', 'png', 'svg' );
		}

		$files = $this->filesystem->dirlist( $this->get_wpml_flags_directory(), false );

		if ( ! $files ) {
			return [];
		}

		$files = array_keys( $files );

		$result = $this->filter_flag_files( $allowed_file_types, $files );
		sort( $result );

		return $result;
	}

	final public function get_wpml_flags_directory() {
		return WPML_PLUGIN_PATH . '/res/flags/';
	}

	final public static function get_wpml_flags_url() {
		return ICL_PLUGIN_URL . '/res/flags/';
	}

	final public static function get_wpml_flags_by_locales_url() {
		return ICL_PLUGIN_URL . '/res/flags_by_locales.json';
	}

	final public static function get_wpml_flag_image_ext() {
		return Options::getFormat();
	}

	private function flag_file_exists( $path ) {
		return $this->filesystem->exists( $path );
	}

	private function filter_flag_files( $allowed_file_types, $files ) {
		$result = array();
		foreach ( $files as $file ) {
			$path = $this->get_wpml_flags_directory() . $file;
			if ( $this->flag_file_exists( $path ) ) {
				$ext = pathinfo( $path, PATHINFO_EXTENSION );
				if ( in_array( $ext, $allowed_file_types, true ) ) {
					$result[] = $file;
				}
			}
		}

		return $result;
	}

	private function append_path_to_url( $base_url, $path ) {
		$base_url_parts = wp_parse_url( $base_url );

		$base_url_path_components = array();
		if ( $base_url_parts && array_key_exists( 'path', $base_url_parts ) ) {
			$base_url_path_components = explode( '/', untrailingslashit( $base_url_parts['path'] ) );
		}

		$sub_dir_path_components = explode( '/', trim( $path, '/' ) );
		foreach ( $sub_dir_path_components as $sub_dir_path_part ) {
			$base_url_path_components[] = $sub_dir_path_part;
		}

		$base_url_parts['path'] = implode( '/', $base_url_path_components );

		return http_build_url( $base_url_parts );
	}
}
