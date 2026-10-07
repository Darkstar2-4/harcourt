<?php

namespace Smush\Core\Webp;

use Smush\Core\Smush\Smusher;
use Smush\Core\Smush\Smusher_Options;

class Webp_Converter extends Smusher {
	/**
	 * @var Webp_Helper
	 */
	private $webp_helper;

	public function __construct( $options ) {
		$original_extra_headers = $options->get_extra_headers() ?? array();
		$new_extra_headers      = array_merge(
			$original_extra_headers,
			array( 'webp' => 'true' )
		);

		$options->set_extra_headers( $new_extra_headers );

		parent::__construct( $options );

		$this->webp_helper = new Webp_Helper();
	}

	protected function save_smushed_image_file( $file_path, $image ) {
		$webp_file_path = $this->webp_helper->get_webp_file_path( $file_path, true );
		$file_saved     = file_put_contents( $webp_file_path, $image );
		if ( ! $file_saved ) {
			return false;
		}

		return $webp_file_path;
	}

	protected function save_from_resource( $input_stream, $target_file_path, $file_md5, $chunk_size ) {
		$webp_file_path = $this->webp_helper->get_webp_file_path( $target_file_path, true );

		return parent::save_from_resource( $input_stream, $webp_file_path, $file_md5, $chunk_size );
	}

	protected function get_type_label() {
		return 'WebP';
	}
}