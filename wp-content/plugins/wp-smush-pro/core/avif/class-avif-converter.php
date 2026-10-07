<?php

namespace Smush\Core\Avif;

use Smush\Core\Smush\Smusher;
use Smush\Core\Smush\Smusher_Options;

class Avif_Converter extends Smusher {
	/**
	 * @var Avif_Helper
	 */
	private $avif_helper;

	public function __construct( $options ) {
		$original_extra_headers = $options->get_extra_headers() ?? array();
		$new_extra_headers      = array_merge(
			$original_extra_headers,
			array( 'avif' => 'true' )
		);

		$options->set_extra_headers( $new_extra_headers );

		parent::__construct( $options );

		$this->avif_helper = new Avif_Helper();
	}

	protected function save_smushed_image_file( $file_path, $image ) {
		$avif_file_path = $this->avif_helper->get_avif_file_path( $file_path, true );
		$file_saved     = file_put_contents( $avif_file_path, $image );
		if ( ! $file_saved ) {
			return false;
		}

		return $avif_file_path;
	}

	protected function save_from_resource( $input_stream, $target_file_path, $file_md5, $chunk_size ) {
		$avif_file_path = $this->avif_helper->get_avif_file_path( $target_file_path, true );

		return parent::save_from_resource( $input_stream, $avif_file_path, $file_md5, $chunk_size );
	}

	protected function get_type_label() {
		return 'AVIF';
	}
}