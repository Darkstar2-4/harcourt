<?php

namespace OTGS\Installer\Api;

use OTGS\Installer\Api\Endpoint\Subscription as SubscriptionEndpoint;
use OTGS\Installer\Api\Endpoint\ProductBucketUrl as ProductBucketUrlEndpoint;
use OTGS_Installer_Logger_Storage;
use OTGS_Installer_Plugin_Factory;
use OTGS_Installer_Plugin_Finder;

class InstallerApiClientFactory {
	public static function create( OTGS_Installer_Logger_Storage $loggerStorage, $repositoryId, $repositoryApiUrl ) {
		$client = new Client\Client( new \WP_Http(), $repositoryApiUrl );

		$siteUrl              = new SiteUrl();
		$subscriptionEndpoint = new SubscriptionEndpoint(
			$repositoryId,
			$siteUrl,
			new OTGS_Installer_Plugin_Finder( new OTGS_Installer_Plugin_Factory() )
		);

		$productBucketUrlEndpoint = new ProductBucketUrlEndpoint(
			$repositoryId,
			$siteUrl
		);

		return new InstallerApiClient( $loggerStorage, $client, $subscriptionEndpoint, $productBucketUrlEndpoint );
	}
}
