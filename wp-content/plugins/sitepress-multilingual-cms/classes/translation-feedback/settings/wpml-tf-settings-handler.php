<?php

abstract class WPML_TF_Settings_Handler {

	protected function get_option_name( $class_name ) {
		return sanitize_title( $class_name );
	}
}
