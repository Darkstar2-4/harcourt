<?php
/**
 * Class that handles URLs functionality.
 *
 * @link    https://wpmudev.com
 * @since   4.11.4
 * @author  Joel James <joel@incsub.com>
 * @package WPMUDEV_Dashboard_Sui_Page_Urls
 */

// If this file is called directly, abort.
defined( 'WPINC' ) || die;

/**
 * Class WPMUDEV_Dashboard_Sui_Page_Urls
 */
class WPMUDEV_Dashboard_Sui_Page_Urls {
	/**
	 * Dashboard page link.
	 *
	 * @var string
	 */
	public string $dashboard_url = '';

	/**
	 * Settings page link.
	 *
	 * @var string
	 */
	public string $settings_url = '';

	/**
	 * Plugins page link.
	 *
	 * @var string
	 */
	public string $plugins_url = '';

	/**
	 * Support page link.
	 *
	 * @var string
	 */
	public string $support_url = '';

	/**
	 * Tools page link.
	 *
	 * @var string
	 */
	public string $tools_url = '';

	/**
	 * Remote site base URL.
	 *
	 * @var string
	 */
	public string $remote_site = 'https://wpmudev.com/';

	/**
	 * Support URL link.
	 *
	 * @var string
	 */
	public string $external_support_url = '';

	/**
	 * Hub2 page link.
	 *
	 * @var string
	 */
	public string $hub_url = '';

	/**
	 * Hub1 page link.
	 *
	 * @var string
	 */
	public string $hub_url_old = 'https://wpmudev.com/hub';

	/**
	 * Documentation section urls.
	 *
	 * @var string[]
	 */
	public array $documentation_url = array();

	/**
	 * Community page link.
	 *
	 * @var string
	 */
	public string $community_url = '';

	/**
	 * Academy page url.
	 *
	 * @var string
	 */
	public string $academy_url = '';

	/**
	 * Hub accounts page url.
	 *
	 * @var string
	 */
	public string $hub_account_url = '';

	/**
	 * Trail page url.
	 *
	 * @var string
	 */
	public string $trial_url = '';

	/**
	 * Backward compat.
	 *
	 * @var string
	 */
	public string $real_support_url = '';

	/**
	 * Themes page url.
	 *
	 * @var string
	 */
	public string $themes_url = '';

	/**
	 * Whip page url.
	 *
	 * @var string
	 */
	public string $whip_url = '';

	/**
	 * Blog page url.
	 *
	 * @var string
	 */
	public string $blog_url = '';

	/**
	 * Roadmap page url.
	 *
	 * @var string
	 */
	public string $roadmap_url = '';

	/**
	 * Analytics page url.
	 *
	 * @var string
	 */
	public string $analytics_url = '';

	/**
	 * Whitelabel page url.
	 *
	 * @var string
	 */
	public string $whitelabel_url = '';

	/**
	 * Skip trial page url.
	 *
	 * @var string
	 */
	public string $skip_trial_url = '';

	/**
	 * Skip trial page url.
	 *
	 * @var string
	 */
	public string $switch_to_free_url = '';

	/**
	 * System info URL.
	 *
	 * @var string
	 */
	public string $system_info_url = '';

	/**
	 * Account details URL.
	 *
	 * @var string
	 */
	public string $hub_account_details_url = '';

	/**
	 * Hub security info URL.
	 *
	 * @var string
	 */
	public string $hub_security_info_url = '';

	/**
	 * Trial info URL.
	 *
	 * @var string
	 */
	public string $trial_info_url = '';

	/**
	 * Forgot password URL.
	 *
	 * @var string
	 */
	public string $forgot_password_url = '';

	/**
	 * Register URL.
	 *
	 * @var string
	 */
	public string $register_url = '';

	/**
	 * Plugins page URL.
	 *
	 * @var string
	 */
	public string $wpmudev_plugins_url = '';

	/**
	 * Documentation page URL.
	 *
	 * @var string
	 */
	public string $wpmudev_docs_url = '';

	/**
	 * Support page URL.
	 *
	 * @var string
	 */
	public string $wpmudev_support_url = '';

	/**
	 * Hub support page URL.
	 *
	 * @var string
	 */
	public string $hub_support_url = '';

	/**
	 * Terms of Service page URL.
	 *
	 * @var string
	 */
	public string $wpmudev_tos_url = '';

	/**
	 * Privacy Policy URL.
	 *
	 * @var string
	 */
	public string $wpmudev_privacy_url = '';

	/**
	 * Facebook URL.
	 *
	 * @var string
	 */
	public string $facebook_url = 'https://www.facebook.com/wpmudev/';

	/**
	 * Twitter URL.
	 *
	 * @var string
	 */
	public string $twitter_url = 'https://twitter.com/wpmudev';

	/**
	 * Instagram URL.
	 *
	 * @var string
	 */
	public string $instagram_url = 'https://www.instagram.com/wpmu_dev/';


	/**
	 * Addmin General Settings Url
	 *
	 * @var string
	 */
	public string $general_settings_url = '';

	/**
	 * Brand pro plugin url
	 *
	 * @var string
	 */
	public string $branda_pro_plugin_page = 'https://wpmudev.com/project/ultimate-branding/';

	/**
	 * Analytics widget in admin
	 *
	 * @var string
	 */
	public string $admin_analytics_widget = 'https://wpmudev.com/project/ultimate-branding/';

	/**
	 * Construct class.
	 *
	 * @access public
	 */
	public function __construct() {
		$this->load();
	}

	/**
	 * Set URL values based on current state.
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	private function load() {
		// Set URL values.
		$this->dashboard_url          = network_admin_url( 'admin.php?page=wpmudev' );
		$this->settings_url           = $this->dashboard_url;
		$this->plugins_url            = $this->dashboard_url;
		$this->support_url            = $this->dashboard_url;
		$this->tools_url              = $this->dashboard_url;
		$this->system_info_url        = network_admin_url( 'admin.php?page=wpmudev&view=system' );
		$this->admin_analytics_widget = admin_url() . '#wdpun_analytics';

		$this->general_settings_url = admin_url( 'options-general.php' );

		// Set plugin links if logged in.
		if ( WPMUDEV_Dashboard::$api->has_key() ) {
			$this->settings_url   = network_admin_url( 'admin.php?page=wpmudev-settings' );
			$this->plugins_url    = network_admin_url( 'admin.php?page=wpmudev-plugins' );
			$this->support_url    = network_admin_url( 'admin.php?page=wpmudev-support' );
			$this->tools_url      = network_admin_url( 'admin.php?page=wpmudev-tools' );
			$this->analytics_url  = network_admin_url( 'admin.php?page=wpmudev-analytics' );
			$this->whitelabel_url = network_admin_url( 'admin.php?page=wpmudev-whitelabel' );
		}

		// Set remote url if custom api is set.
		if ( defined( 'WPMUDEV_CUSTOM_API_SERVER' ) && WPMUDEV_CUSTOM_API_SERVER ) {
			$this->remote_site = trailingslashit( WPMUDEV_CUSTOM_API_SERVER );
		}

		// External URLs.
		$this->hub_url                 = $this->remote_site . 'hub2';
		$this->external_support_url    = $this->remote_site . 'hub2/support/#ask-question';
		$this->community_url           = $this->remote_site . 'hub2/community/';
		$this->academy_url             = $this->remote_site . 'academy/';
		$this->hub_account_url         = $this->remote_site . 'hub2/account/';
		$this->blog_url                = $this->remote_site . 'blog/';
		$this->whip_url                = $this->remote_site . 'blog/get-the-whip/';
		$this->roadmap_url             = $this->remote_site . 'roadmap/';
		$this->trial_url               = $this->remote_site . '#trial';
		$this->skip_trial_url          = $this->remote_site . 'hub2/account/?skip_trial';
		$this->switch_to_free_url      = $this->remote_site . 'hub2/?switch-free=1';
		$this->hub_account_details_url = $this->remote_site . 'hub2/account/details';
		$this->hub_security_info_url   = $this->remote_site . 'manuals/hub-security';
		$this->trial_info_url          = $this->remote_site . 'docs/getting-started/how-free-trials-work/';
		$this->forgot_password_url     = $this->remote_site . 'forgot-password/';
		$this->register_url            = $this->remote_site . 'register/';
		$this->wpmudev_plugins_url     = $this->remote_site . 'plugins/';
		$this->wpmudev_docs_url        = $this->remote_site . 'docs/';
		$this->wpmudev_privacy_url     = $this->remote_site . 'privacy-policy/';
		$this->wpmudev_tos_url         = $this->remote_site . 'terms-of-service/';
		$this->wpmudev_support_url     = $this->remote_site . 'get-support/';
		$this->hub_support_url         = $this->remote_site . 'hub2/support/';

		// Documentation URLs.
		$this->documentation_url = array(
			'overview'   => $this->remote_site . 'docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/',
			'dashboard'  => $this->remote_site . 'docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/#dashboard',
			'plugins'    => $this->remote_site . 'docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/#plugins',
			'support'    => $this->remote_site . 'docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/#support',
			'analytics'  => $this->remote_site . 'docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/#analytics',
			'whitelabel' => $this->remote_site . 'docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/#white-label',
			'settings'   => $this->remote_site . 'docs/wpmu-dev-plugins/wpmu-dev-dashboard-plugin-instructions/#settings',
		);
	}

	/**
	 * Reload the URLs.
	 *
	 * @since 5.0.0
	 * @return self
	 */
	public function reload(): self {
		$this->load();

		return $this;
	}
}