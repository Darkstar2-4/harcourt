<?php

class OTGS_Installer_Repository {

	private $id;
	private $subscription;
	private $packages;
	private $product_name;
	private $api_url;

	public function __construct( array $params = array() ) {
		foreach ( get_object_vars( $this ) as $property => $value ) {
			if ( array_key_exists( $property, $params ) ) {
				$this->$property = $params[ $property ];
			}
		}
	}

	public function get_id() {
		return $this->id;
	}

	public function get_product_name() {
		return $this->product_name;
	}

	public function get_api_url( $ssl = true ) {
		$api_url = $this->api_url;

		if ( ! $ssl ) {
			$api_url           = wp_parse_url( $api_url );
			$api_url['scheme'] = 'http';
			$api_url           = http_build_url( $api_url );
		}

		return $api_url;
	}

	public function get_subscription() {
		return $this->subscription;
	}

	public function get_packages() {
		return $this->packages;
	}

	public function get_product_by_subscription_type() {
		return $this->get_product_by( 'get_product_by_subscription_type' );
	}

	public function get_product_by_subscription_type_equivalent() {
		return $this->get_product_by( 'get_product_by_subscription_type_equivalent' );
	}

	public function get_product_by_subscription_type_on_upgrades() {
		return $this->get_product_by( 'get_product_by_subscription_type_on_upgrades' );
	}

	public function set_subscription( $subscription = null ) {
		$this->subscription = $subscription;
	}

	private function get_product_by( $function_name ) {
		$subscription_type = $this->subscription->get_type();
		foreach ( $this->packages as $package ) {
			$product = $package->$function_name( $subscription_type );
			if ( $product ) {
				return $product;
			}
		}

		return null;
	}

}
