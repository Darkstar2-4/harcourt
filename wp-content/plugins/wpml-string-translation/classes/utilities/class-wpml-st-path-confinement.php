<?php

class WPML_ST_Path_Confinement {

	public static function resolve_contained( $path, $root, $allow_root = false ) {
		$canonical_root = realpath( $root );
		if ( false === $canonical_root ) {
			return false;
		}

		$canonical_path = realpath( $path );
		if ( false === $canonical_path ) {
			return false;
		}

		if ( $allow_root && $canonical_path === $canonical_root ) {
			return $canonical_path;
		}

		if ( strpos( $canonical_path, $canonical_root . DIRECTORY_SEPARATOR ) === 0 ) {
			return $canonical_path;
		}

		return false;
	}

	public static function resolve_source_path_for_domain( $path, $domain ) {
		$roots = self::get_component_roots_for_domain( $domain );

		if ( 1 !== count( $roots ) ) {
			return false;
		}

		return self::resolve_contained( $path, $roots[0], true );
	}

	private static function get_component_roots_for_domain( $domain ) {
		$map = self::get_domain_component_map();

		if ( ! isset( $map[ $domain ] ) ) {
			return [];
		}

		$roots = [];
		foreach ( $map[ $domain ] as $root ) {
			$canonical_root = realpath( $root );
			if ( false !== $canonical_root ) {
				$roots[ $canonical_root ] = true;
			}
		}

		return array_keys( $roots );
	}

	private static function get_domain_component_map() {
		$map = [];

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( (array) get_plugins() as $plugin_file => $plugin_data ) {
			if ( empty( $plugin_data['TextDomain'] ) ) {
				continue;
			}

			$plugin_dir = dirname( $plugin_file );
			$root       = '.' === $plugin_dir
				? WPML_PLUGINS_DIR . '/' . $plugin_file
				: WPML_PLUGINS_DIR . '/' . $plugin_dir;

			if ( false !== self::resolve_contained( $root, WPML_PLUGINS_DIR, true ) ) {
				$map[ $plugin_data['TextDomain'] ][] = $root;
			}
		}

		foreach ( (array) wp_get_mu_plugins() as $mu_plugin_file ) {
			if ( ! is_file( $mu_plugin_file ) || ! is_readable( $mu_plugin_file ) ) {
				continue;
			}

			$mu_plugin_data = get_plugin_data( $mu_plugin_file, false, false );
			if ( ! empty( $mu_plugin_data['TextDomain'] )
				&& false !== self::resolve_contained( $mu_plugin_file, WPMU_PLUGIN_DIR, true )
			) {
				$map[ $mu_plugin_data['TextDomain'] ][] = $mu_plugin_file;
			}
		}

		foreach ( (array) wp_get_themes() as $theme ) {
			$text_domain = $theme->get( 'TextDomain' );
			if ( ! $text_domain ) {
				continue;
			}

			if ( false !== self::resolve_contained( $theme->get_stylesheet_directory(), $theme->get_theme_root(), true ) ) {
				$map[ $text_domain ][] = $theme->get_stylesheet_directory();
			}
		}

		return $map;
	}
}
