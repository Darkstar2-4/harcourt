<?php

namespace Smush\Core\Smush;

use Smush\Core\Settings;

class Smusher_Options_Provider {

	/**
	 * @var Settings
	 */
	protected $settings;

	public function __construct() {
		$this->settings = Settings::get_instance();
	}

	public function get_options() {
		$use_http = $this->settings->get_setting( 'wp-smush-use_http' );
		$api_url  = defined( 'WP_SMUSH_API_HTTP' ) ? WP_SMUSH_API_HTTP : WP_SMUSH_API;
		$protocol = $use_http ? 'http' : 'https';

		$settings = $this->settings;

		return ( new Smusher_Options() )
			->set_lossy_level( $this->settings->get_lossy_level_setting() )
			->set_strip_exif( $this->settings->get( 'strip_exif' ) )
			->set_api_key( $this->settings->get_api_key() )
			->set_api_url( $api_url )
			->set_streaming_enabled( $this->settings->streaming_enabled() )
			->set_extra_headers( array() )
			->set_parallel_optimization_enabled( apply_filters( 'wp_smush_parallel_optimization', WP_SMUSH_PARALLEL ) )
			->set_protocol( $protocol )
			->set_max_size( $this->settings->get_file_size_limit() )
			->set_on_disable_streaming( function () use ( $settings ) {
				$settings->set( 'disable_streams', WP_SMUSH_VERSION );
			} )
			->set_on_switch_to_http( function () use ( $settings ) {
				$settings->set_setting( 'wp-smush-use_http', 1 );
			} );
	}
}