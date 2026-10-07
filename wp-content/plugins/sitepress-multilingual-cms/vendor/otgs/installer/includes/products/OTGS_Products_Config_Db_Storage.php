<?php

class OTGS_Products_Config_Db_Storage {
	const PRODUCTS_CONFIG_KEY = 'otgs_installer_products_urls';
	const PRODUCT_VERSIONS_CONFIG_KEY = 'otgs_installer_products_urls_version';

	public function get_repository_products_url( $repository_id ) {
		$products = get_option( self::PRODUCTS_CONFIG_KEY, [] );
		return isset( $products[ $repository_id ] ) ? $products[$repository_id] : null;
	}

	public function store_repository_products_url( $repository_id, $repository_products_url ) {
		$products_config = get_option( self::PRODUCTS_CONFIG_KEY, [] );
		$products_config[ $repository_id ] = $repository_products_url;

		return update_option( self::PRODUCTS_CONFIG_KEY, $products_config, 'yes');
	}

	public function clear_repository_products_url( $repository_id ) {
		$products_config = get_option( self::PRODUCTS_CONFIG_KEY, [] );
		unset( $products_config[ $repository_id ] );

		return update_option( self::PRODUCTS_CONFIG_KEY, $products_config, 'yes');
	}

	public function clear_repository_product_version( $repository_id ) {
		$products_config = get_option( self::PRODUCT_VERSIONS_CONFIG_KEY, [] );
		unset( $products_config[ $repository_id ] );

		return update_option( self::PRODUCT_VERSIONS_CONFIG_KEY, $products_config, 'yes');
	}

	public function get_repository_product_version( $repository_id ) {
		$products = get_option( self::PRODUCT_VERSIONS_CONFIG_KEY, [] );
		return isset( $products[ $repository_id ] ) ? $products[$repository_id] : null;
	}

	public function update_repository_product_version( $repository_id, $repository_product_version ) {
		$products_config = get_option( self::PRODUCT_VERSIONS_CONFIG_KEY, [] );
		$products_config[ $repository_id ] = $repository_product_version;

		return update_option( self::PRODUCT_VERSIONS_CONFIG_KEY, $products_config, 'yes');
	}
}
