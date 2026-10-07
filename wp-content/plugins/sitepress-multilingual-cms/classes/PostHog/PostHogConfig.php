<?php

namespace WPML\PostHog\Config;

use WPML\Core\Component\PostHog\Application\Service\Config\ConfigService;
use WPML\Infrastructure\WordPress\Component\Site\Application\Query\SiteUrlQuery;
use WPML\Legacy\SharedKernel\Installer\Application\Query\WpmlActivePluginsQuery;
use WPML\Legacy\SharedKernel\Installer\Application\Query\WpmlSiteKeyQuery;
use WPML\LIB\WP\User;
use WPML\PostHog\State\PostHogState;

class PostHogConfig {

	public static function create() {
		$config = ( new ConfigService() )->create();

		$siteKeyQuery           = new WpmlSiteKeyQuery();
		$currentUser            = User::getCurrent();
		$siteUrlQuery           = new SiteUrlQuery();
		$wpmlActivePluginsQuery = new WpmlActivePluginsQuery();

		return [
			'apiKey'                  => $config->getApiKey(),
			'host'                    => $config->getHost(),
			'personProfiles'          => $config->getPersonProfiles(),
			'disableSurveys'          => $config->getDisableSurveys(),
			'autoCapture'             => $config->getAutoCapture(),
			'capturePageView'         => $config->getCapturePageView(),
			'capturePageLeave'        => $config->getCapturePageLeave(),
			'disableSessionRecording' => $config->getDisableSessionRecording(),
			'siteKey'                 => $siteKeyQuery->get() ?: '',
			'wpUserEmail'             => $currentUser ? $currentUser->user_email : null,
			'siteUrl'                 => $siteUrlQuery->get(),
			'wpmlActivePlugins'       => $wpmlActivePluginsQuery->getActivePlugins(),
			'trackingMode'            => PostHogState::getTrackingMode(),
		];
	}
}
