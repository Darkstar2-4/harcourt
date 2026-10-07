<?php

class WPML_TF_Settings_Read extends WPML_TF_Settings_Handler {

	public function get( $settings_class ) {
		if ( ! class_exists( $settings_class ) ) {
			throw new InvalidArgumentException( $settings_class . ' does not exist.' );
		}

		if ( ! in_array( 'IWPML_TF_Settings', class_implements( $settings_class ) ) ) {
			throw new InvalidArgumentException( $settings_class . ' should implement IWPML_TF_Settings.' );
		}

		$settings_properties = get_option( $this->get_option_name( $settings_class ) );

		$settings = new $settings_class();

		if ( is_array( $settings_properties ) ) {
			$this->set_properties( $settings, $settings_properties );
		}

		return $settings;
	}

	private function set_properties( IWPML_TF_Settings $settings, array $settings_properties ) {
		foreach ( $settings->get_properties() as $property_name => $property_value ) {

			if ( method_exists( $settings, 'set_' . $property_name ) ) {

				if ( isset( $settings_properties[ $property_name ] ) ) {
					call_user_func( array( $settings, 'set_' . $property_name ), $settings_properties[ $property_name ] );
				}
			} else {
				throw new BadMethodCallException( 'The method set_' . $property_name . ' is required in ' . get_class( $settings ) . '.' );
			}
		}
	}
}
