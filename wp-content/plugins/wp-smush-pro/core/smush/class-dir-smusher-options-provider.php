<?php

namespace Smush\Core\Smush;

class Dir_Smusher_Options_Provider extends Smusher_Options_Provider {

	public function get_options() {
		return parent::get_options()
			->set_lossy_level( $this->settings->get_dir_lossy_level_setting() )
			->set_strip_exif( $this->settings->get_dir_strip_exif_setting() );
	}
}