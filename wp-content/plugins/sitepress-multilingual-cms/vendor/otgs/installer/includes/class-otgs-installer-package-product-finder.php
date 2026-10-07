<?php

class OTGS_Installer_Package_Product_Finder {

	public function get_product_in_repository_by_subscription( OTGS_Installer_Repository $repository, $subscription = null ) {
		$product = null;

		if ( ! $subscription ) {
			$subscription = $repository->get_subscription();
		}
		if ( $subscription ) {
			$product = $repository->get_product_by_subscription_type();

			if ( ! $product ) {
				$product = $repository->get_product_by_subscription_type_equivalent();

				if ( ! $product ) {
					$product = $repository->get_product_by_subscription_type_on_upgrades();
				}
			}
		}

		return $product;
	}
}