<?php

namespace Smush\Core\Resize;

use Smush\Core\Settings;

class Resize_Helper {
	/**
	 * @var Settings|null
	 */
	private $settings;

	public function __construct() {
		$this->settings = Settings::get_instance();
	}

	public function get_resize_dimensions( $file_path = '', $attachment_id = 0 ) {
		$dimensions = $this->settings->get_setting( 'wp-smush-resize_sizes', array() );
		$dimensions = apply_filters(
			'wp_smush_resize_sizes',
			array(
				'width'  => empty( $dimensions['width'] ) ? 0 : (int) $dimensions['width'],
				'height' => empty( $dimensions['height'] ) ? 0 : (int) $dimensions['height'],
			),
			$file_path,
			$attachment_id
		);

		return (object) $dimensions;
	}
}