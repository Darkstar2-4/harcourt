<?php

use WPML\ATE\Proxies\ProxyInterceptorLoader;
use WPML\ATE\Proxies\Widget;
use WPML\LIB\WP\User;
use WPML\TM\ATE\ATEDashboardLoader;
use WPML\TM\ATE\JobSender\JobSenderRepository;
use WPML\TM\ATE\NoCreditPopup;

use function WPML\Container\make;

abstract class WPML_TM_AMS_Translation_Abstract_Console_Section {
	const ATE_APP_ID = 'eate_widget';
	const TAB_ORDER = 500;
	const CONTAINER_SELECTOR = '#ams-ate-console';
	const TAB_SELECTOR = '.wpml-tabs .nav-tab.nav-tab-active.nav-tab-ate-ams';
	const SLUG = 'ate-ams';
	const SECTION_SLUG = 'tools';

	private $sitepress;
	private $endpoints;

	private $auth;

	private $ams_api;
	protected $proxyInterceptorLoader;

	protected $dashboardLoader;


	public function __construct( SitePress $sitepress, WPML_TM_ATE_AMS_Endpoints $endpoints, WPML_TM_ATE_Authentication $auth, WPML_TM_AMS_API $ams_api, ATEDashboardLoader $dashboardLoader, ProxyInterceptorLoader $proxyInterceptorLoader ) {
		$this->sitepress              = $sitepress;
		$this->endpoints              = $endpoints;
		$this->auth                   = $auth;
		$this->ams_api                = $ams_api;
		$this->dashboardLoader        = $dashboardLoader;
		$this->proxyInterceptorLoader = $proxyInterceptorLoader;
	}

	public abstract function getCachingManager();


	public function get_order() {
		return static::TAB_ORDER;
	}

	public function get_slug() {
		return static::SLUG;
	}


	public function get_section_slug() {
		return static::SECTION_SLUG;
	}

	public function get_capabilities() {
		return [ User::CAP_MANAGE_TRANSLATIONS, User::CAP_ADMINISTRATOR, User::CAP_MANAGE_OPTIONS ];
	}

	abstract public function get_caption();

	public function get_callback() {
		return array( $this, 'render' );
	}

	public function is_visible() {
		return true;
	}

	public function render() {
		$supportUrl  = 'https://wpml.org/forums/forum/english-support/?utm_source=plugin&utm_medium=gui&utm_campaign=wpmltm';
		$supportLink = '<a target="_blank" rel="nofollow" href="' . esc_url( $supportUrl ) . '">'
					   . esc_html__( 'contact our support team', 'wpml-translation-management' )
					   . '</a>';


		?>
		<div id="ams-ate-console">
			<div class="notice inline notice-error" style="display:none; padding:20px">
				<?php
				echo sprintf(
				// translators: %s is a link with 'contact our support team'
					esc_html(
						__( 'There is a problem connecting to automatic translation. Please check your internet connection and try again in a few minutes. If you continue to see this message, please %s.', 'wpml-translation-management' )
					),
					$supportLink
				);
				?>
			</div>
			<span class="spinner is-active" style="float:left"></span>
		</div>
		<script type="text/javascript">
			setTimeout( function () {
				jQuery( '#ams-ate-console .notice' ).show()
				jQuery( '#ams-ate-console .spinner' ).removeClass( 'is-active' )
			}, 20000 )
		</script>
		<?php
	}

	public function admin_enqueue_scripts( $hook ) {
		$this->admin_enqueue_tab_scripts();
	}

	protected function admin_enqueue_tab_scripts() {
		if ( $this->is_tab() ) {
			$script_url = add_query_arg(
				[
					Widget::QUERY_VAR_ATE_WIDGET_SCRIPT  => Widget::SCRIPT_NAME,
					Widget::QUERY_VAR_ATE_WIDGET_SECTION => $this->get_section_slug()
				],
				trailingslashit( home_url() )
			);

			$deps = [];
			if ( $this->proxyInterceptorLoader->shouldEnableProxy() ) {
				$deps[] = $this->proxyInterceptorLoader::HANDLE_JS;
			}
			wp_enqueue_script( self::ATE_APP_ID, $script_url, $deps, ICL_SITEPRESS_SCRIPT_VERSION, true );
		}
	}

	abstract protected function is_tab();

	private function get_post_types_data() {
		$translatable_types = $this->sitepress->get_translatable_documents( true );

		$data = [];
		if ( $translatable_types ) {
			foreach ( $translatable_types as $name => $post_type ) {
				$data[ esc_js( $name ) ] = [
					'labels'      => [
						'name'          => esc_js( $post_type->labels->name ),
						'singular_name' => esc_js( $post_type->labels->singular_name ),
					],
					'description' => esc_js( $post_type->description ),
				];
			}
		}

		return $data;
	}

	private function get_user_admin_language() {
		return $this->sitepress->get_user_admin_language( wp_get_current_user()->ID );
	}

	public function get_ams_constructor() {
		$registration_data = $this->ams_api->get_registration_data();

		$noCreditPopup = make( NoCreditPopup::class );

		$currentSender = JobSenderRepository::get();

		$app_constructor = [
			'section'              => $this->get_section_slug(),
			'host'                 => esc_js( $this->endpoints->get_base_url( WPML_TM_ATE_AMS_Endpoints::SERVICE_AMS ) ),
			'wpml_host'            => esc_js( get_site_url() ),
			'wpml_home'            => esc_js( get_home_url() ),
			'secret_key'           => esc_js( $registration_data['secret'] ),
			'shared_key'           => esc_js( $registration_data['shared'] ),
			'status'               => esc_js( $registration_data['status'] ),
			'tm_user_id'           => $currentSender->id,
			'tm_email'             => esc_js( $currentSender->email ),
			'tm_user_name'         => esc_js( $currentSender->username ),
			'tm_user_display_name' => esc_js( $currentSender->displayName ),
			'website_uuid'         => esc_js( $this->auth->get_site_id() ),
			'site_key'             => esc_js($this->sitepress->get_sitekey()),
			'dependencies'         => [
				'sitepress-multilingual-cms' => [
					'version' => ICL_SITEPRESS_VERSION,
				],
			],
			'tab'                  => self::TAB_SELECTOR,
			'container'            => self::CONTAINER_SELECTOR,
			'post_types'           => $this->get_post_types_data(),
			'ui_language'          => esc_js( $this->get_user_admin_language() ),
			'restNonce'            => wp_create_nonce( 'wp_rest' ),
			'authCookie'           => [
				'name'  => LOGGED_IN_COOKIE,
				'value' => $_COOKIE[ LOGGED_IN_COOKIE ],
			],
			'languages'            => $noCreditPopup->getLanguagesData(),
		];

		return $app_constructor;
	}

	public function getWidgetScriptUrl() {
		return $this->endpoints->get_base_url( WPML_TM_ATE_AMS_Endpoints::SERVICE_AMS ) . '/mini_app/run.js';
	}

	public function getDashboardScriptUrl() {
		return $this->endpoints->get_base_url( WPML_TM_ATE_AMS_Endpoints::SERVICE_AMS ) . '/mini_app/dashboard.js';
	}


	public function getAppData( $tryFromCache = false ) {
		$cachingManager = $this->getCachingManager();

		$fetchAndCache = function () use ( $cachingManager ) {
			$remoteAppData = $this->fetchRemoteAppData();
			$cachingManager->cacheApp( $remoteAppData['app'], 2 );

			return $remoteAppData;
		};

		if ( ! ( $tryFromCache && $cachingManager ) ) {
			return $this->fetchRemoteAppData();
		}

		$cachedAppData = $cachingManager->getCachedAppData( $this->get_ams_constructor() );

		if ( ! $cachedAppData ) {
			return $fetchAndCache();
		}

		return $cachedAppData;
	}

	public function fetchRemoteAppData() {
		$errors      = [];
		$app         = '';
		$constructor = '';
		$headers     = [];
		$isJs        = false;

		$response = wp_remote_request( $this->getWidgetScriptUrl(), [ 'timeout' => 20 ] );

		if ( is_wp_error( $response ) ) {
			$errors[] = 'WP_Error response';
			$errors[] = $response->get_error_message();
		} else {
			$headerData = wp_remote_retrieve_headers( $response )->getAll();
			if ( ! $headerData ) {
				$errors[] = 'Empty headers when retrieving the ATE Widget App';
			} else {
				$isJs = $headerData && strpos( $headerData['content-type'], 'javascript' );
			}

			$app         = wp_remote_retrieve_body( $response );
			$constructor = wp_json_encode( $this->get_ams_constructor() );

			if ( ! $app || ! trim( $app ) ) {
				$errors[] = 'Empty response when retrieving the ATE Widget App';
			}

			$headers = [
				$_SERVER['SERVER_PROTOCOL'] . ' ' . $response['response']['code'] . ' ' . $response['response']['message'],
			];

			if ( isset( $headerData['content-type'] ) ) {
				$headers[] = 'content-type: ' . $headerData['content-type'];
			} else {
				$errors[] = 'Empty content-type header when retrieving the ATE Widget App';
			}
		}

		return [
			'app'         => $app,
			'constructor' => $constructor,
			'headers'     => $headers,
			'isJs'        => $isJs,
			'errors'      => $errors,
			'response'    => $response,
		];
	}
}
