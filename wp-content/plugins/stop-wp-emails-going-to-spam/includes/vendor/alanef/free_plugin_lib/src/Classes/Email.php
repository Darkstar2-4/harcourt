<?php

namespace Fullworks_Free_Plugin_Lib\Classes;

class Email {
	private static $plugin_shortname;

	// Plugin shortname to ID mapping
	private static $plugin_map = [
		'SWEGTS' => 'swegts',
		'SSFGM'  => 'ssfgm',
		'LHF'    => 'lhf',
		'SUE'    => 'sue',
		'RSHFD'  => 'rshfd',
		'FAUM'   => 'faum',
		'FSS'    => 'fss',
		'MMT'    => 'mmt',
		'CSCF'   => 'cfcs',   // deliberate transpose  here
	];

	public function __construct($plugin_shortname) {
		self::$plugin_shortname = $plugin_shortname;
	}

	public function handle_optin_submission($email) {
		// Enhanced email validation
		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
			return false;
		}

		// Allow filtering of plugin map for testing/extensions
		$plugin_map = apply_filters( 'ffpl_plugin_map', self::$plugin_map );

		// Get plugin ID from map
		$plugin_id = $plugin_map[self::$plugin_shortname] ?? null;
		if (!$plugin_id) {
			return false;
		}

		// Allow filtering of the verification URL for testing
		$verify_url = apply_filters( 'ffpl_verify_url', 'https://campaign.email.fw9.uk/api/webhook/product' );

		$response = wp_remote_post($verify_url, [
			'headers' => [
				'Content-Type' => 'application/json',
				'User-Agent' => 'WordPress/' . get_bloginfo('version'),
			],
			'body' => wp_json_encode([
				'type' => 'install.installed',
				'plugin_id' => $plugin_id,
				'is_live' => true,
				'objects' => [
					'user' => [
						'is_marketing_allowed' => true,
						'email' => sanitize_email($email),
						'first' => '',
						'last' => '',
						'ip' => Security::get_client_ip(),
						'id' => null,
					],
					'install' => [
						'is_premium' => false,
						'is_active' => true,
						'license_id' => null,
						'trial_plan_id' => null,
						'trial_ends' => null,
						'country_code' => '',
						'url' => get_site_url(),
					]
				]
			]),
			'timeout' => 15,
			'sslverify' => true,
			'blocking' => true,
		]);

		if (is_wp_error($response)) {
			return false;
		}

		return wp_remote_retrieve_response_code($response) === 200;
	}
}