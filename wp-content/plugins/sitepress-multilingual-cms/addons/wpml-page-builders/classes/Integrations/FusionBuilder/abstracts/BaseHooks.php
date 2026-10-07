<?php

namespace WPML\Compatibility\FusionBuilder;

abstract class BaseHooks {

	const HANDLE = 'wpml-compatibility-fusion';

	const SCRIPT_SRC = '/dist/js/compatibility/fusion_builder/app.js';

	const STYLE_SRC = '/res/css/compatibility/fusion_builder.css';

	const OBJECT_NAME = 'WPML_COMPATIBILITY_FUSION';

	protected function enqueue_style() {
		self::check_asset( self::STYLE_SRC );

		wp_enqueue_style(
			self::HANDLE,
			self::get_url( self::STYLE_SRC ),
			[],
			ICL_SITEPRESS_VERSION
		);
	}

	protected function enqueue_script() {
		self::check_asset( self::SCRIPT_SRC );

		wp_enqueue_script(
			self::HANDLE,
			self::get_url( self::SCRIPT_SRC ),
			[],
			ICL_SITEPRESS_VERSION,
			true
		);
	}

	protected function localize_script( $data ) {
		wp_localize_script(
			self::HANDLE,
			self::OBJECT_NAME,
			$data
		);
	}

	private static function check_asset( $uri ) {
		$filepath = WPML_PLUGIN_PATH . $uri;

		if ( ! file_exists( $filepath ) ) {
			throw new \Exception( "The asset $filepath is missing in WPML Core" );
		}
	}

	private static function get_url( $uri ) {
		return ICL_PLUGIN_URL . $uri;
	}
}
