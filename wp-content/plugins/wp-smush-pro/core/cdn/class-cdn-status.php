<?php
namespace Smush\Core\CDN;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * TODO: reuse instead of the raw status object
 */
class CDN_Status {

	/**
	 * CDN has been fully provisioned and propagated.
	 *
	 * @var bool
	 */
	private $cdn_enabled = false;

	/**
	 * CDN provisioning is still in progress (subdomain / propagation pending).
	 *
	 * @var bool
	 */
	private $cdn_enabling = false;

	/**
	 * CDN endpoint hostname, e.g. "abc123.smushcdn.com".
	 * Empty string when the subdomain has not been generated yet.
	 *
	 * @var string
	 */
	private $endpoint_url = '';

	/**
	 * Numeric site ID used to build the CDN base URL path segment.
	 *
	 * @var int
	 */
	private $site_id = 0;

	/**
	 * Bandwidth consumed in the current period (bytes).
	 *
	 * @var float
	 */
	private $bandwidth = 0.0;

	/**
	 * Plan bandwidth ceiling in GB.
	 *
	 * @var int
	 */
	private $bandwidth_plan = 10;

	/**
	 * Private constructor – use from_setting() to instantiate.
	 */
	private function __construct() {
	}

	public static function from_setting( $raw ) {
		$instance = new self();
		if ( empty( $raw ) || ! is_object( $raw ) ) {
			return $instance;
		}

		$instance->cdn_enabled    = isset( $raw->cdn_enabled ) && (bool) $raw->cdn_enabled;
		$instance->cdn_enabling   = isset( $raw->cdn_enabling ) && (bool) $raw->cdn_enabling;
		$instance->endpoint_url   = isset( $raw->endpoint_url ) ? (string) $raw->endpoint_url : '';
		$instance->site_id        = isset( $raw->site_id ) ? absint( $raw->site_id ) : 0;
		$instance->bandwidth      = isset( $raw->bandwidth ) ? (float) $raw->bandwidth : 0.0;
		$instance->bandwidth_plan = isset( $raw->bandwidth_plan ) ? (int) $raw->bandwidth_plan : 10;

		return $instance;
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/**
	 * Whether CDN setup is complete and the CDN is actively serving images.
	 *
	 * @return bool
	 */
	public function is_cdn_enabled() {
		return $this->cdn_enabled;
	}

	/**
	 * Whether CDN provisioning is still in progress.
	 *
	 * @return bool
	 */
	public function is_cdn_enabling() {
		return $this->cdn_enabling;
	}

	/**
	 * CDN endpoint hostname (without scheme).
	 *
	 * @return string
	 */
	public function get_endpoint_url() {
		return $this->endpoint_url;
	}

	/**
	 * Numeric site ID used in the CDN base URL.
	 *
	 * @return int
	 */
	public function get_site_id() {
		return $this->site_id;
	}

	/**
	 * Bandwidth consumed in bytes.
	 *
	 * @return float
	 */
	public function get_bandwidth() {
		return $this->bandwidth;
	}

	/**
	 * Plan bandwidth ceiling in GB.
	 *
	 * @return int
	 */
	public function get_bandwidth_plan() {
		return $this->bandwidth_plan;
	}

	// -------------------------------------------------------------------------
	// Derived helpers
	// -------------------------------------------------------------------------

	/**
	 * Full CDN base URL, e.g. "https://abc123.smushcdn.com/3232/".
	 *
	 * Returns an empty string when either endpoint_url or site_id is missing,
	 * which happens while the CDN subdomain is still being provisioned.
	 *
	 * @return string
	 */
	public function get_base_url() {
		if ( empty( $this->endpoint_url ) || empty( $this->site_id ) ) {
			return '';
		}

		return trailingslashit( "https://{$this->endpoint_url}/{$this->site_id}" );
	}

	/**
	 * Derived status string based on CDN state and bandwidth usage.
	 * Returns: 'activating' | 'overcap' | 'upgrade' | 'enabled'
	 *
	 * Note: does NOT handle 'disabled' — that is CDN_Helper's concern.
	 *
	 * @return string
	 */
	public function get_status_string() {
		if ( $this->cdn_enabling ) {
			return 'activating';
		}

		$plan       = $this->bandwidth_plan ?: 10;
		$percentage = round( 100 * $this->bandwidth / 1024 / 1024 / 1024 / $plan );

		if ( $percentage >= 100 ) {
			return 'overcap';
		}

		if ( $percentage >= 90 ) {
			return 'upgrade';
		}

		return $this->cdn_enabled ? 'enabled' : 'disabled';
	}

	// -------------------------------------------------------------------------
	// React serialisation
	// -------------------------------------------------------------------------

	/**
	 * Camel-case array for wp_send_json_success() or localization.
	 *
	 * Includes the two computed fields (`cdnActive`, `cdnBaseUrl`) that the
	 * React frontend expects from the toggle CDN ajax response.
	 *
	 * @return array
	 */
	public function to_react_props() {
		return array(
			'cdnEnabled'    => $this->cdn_enabled,
			'cdnEnabling'   => $this->cdn_enabling,
			'endpointUrl'   => $this->endpoint_url,
			'siteId'        => $this->site_id,
			'bandwidth'     => $this->bandwidth,
			'bandwidthPlan' => $this->bandwidth_plan,
		);
	}
}