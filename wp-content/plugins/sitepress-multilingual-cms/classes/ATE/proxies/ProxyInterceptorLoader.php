<?php

namespace WPML\ATE\Proxies;

use WPML\Core\Component\WpmlProxy\Application\Service\WpmlProxyService;
use WPML\Infrastructure\Dic;

class ProxyInterceptorLoader {

	const PROXY_REST_PATH = '/wpml/v1/proxy';
	const HANDLE_JS = 'wpml-proxy-interceptor';

	private $isInitialized = false;

	private static $instance = null;

	private $allowed_domains = [];

	private $bypassed_http_requests = [];

	private $wpml_proxy_service;


	private function __construct( WpmlProxyService $wpmlProxyService, ProxyRoutingRules $proxyRoutingRules ) {
		$this->allowed_domains        = $proxyRoutingRules->getAllowedDomains();
		$this->bypassed_http_requests = $proxyRoutingRules->getBypassedHttpRequests();
		$this->wpml_proxy_service     = $wpmlProxyService;
	}


	public static function get() {
		global $wpml_dic;
		if ( null === self::$instance ) {
			self::$instance = new self( $wpml_dic->make( WpmlProxyService::class ), new ProxyRoutingRules() );
		}

		return self::$instance;
	}

	public function shouldEnableProxy() {
		return $this->wpml_proxy_service->isEnabled();
	}

	public function enqueueJS( string $handle, string $url, array $deps = array(), $ver = false, $in_footer = true ) {
		if ( ! $this->isInitialized ) {
			$this->enable();
		}
		$url = $this->getProxyUrl( $url );
		$url = add_query_arg( [ '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], $url );

		$deps = array_merge( $deps, [ self::HANDLE_JS ] );

		wp_register_script( $handle, $url, $deps, $ver, $in_footer );

		wp_enqueue_script( $handle );
	}

	public function enable() {
		$src = plugins_url( 'res/js/wpml-proxy-interceptor.js', WPML_PLUGIN_PATH . '/sitepress.php' );
		wp_register_script( self::HANDLE_JS, $src, [], ICL_SITEPRESS_SCRIPT_VERSION, true );

		wp_add_inline_script(
			self::HANDLE_JS,
			'window.wpmlProxyOptions = ' . wp_json_encode(
				[
					'domains'              => $this->allowed_domains,
					'bypassedHttpRequests' => $this->bypassed_http_requests,
					'proxyPath'            => (string) $this->getProxyUrl( '' ),
					'nonce'                => wp_create_nonce( 'wp_rest' ),
				]
			) . ';',
			'before'
		);

		wp_enqueue_script( self::HANDLE_JS );
		$this->isInitialized = true;
	}

	private function getProxyUrl( $url = '' ) {
		$proxyUrl = get_rest_url( null, self::PROXY_REST_PATH );
		if ( ! empty( $url ) ) {
			$proxyUrl = add_query_arg( [ 'url' => $url ], $proxyUrl );
		}

		return $proxyUrl;
	}
}
