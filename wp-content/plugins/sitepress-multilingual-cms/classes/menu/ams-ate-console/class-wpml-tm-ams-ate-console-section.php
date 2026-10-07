<?php

use WPML\API\Sanitize;
use WPML\ATE\Proxies\ProxyInterceptorLoader;

class WPML_TM_AMS_ATE_Console_Section extends WPML_TM_AMS_Translation_Abstract_Console_Section implements IWPML_TM_Admin_Section {
	const ATE_APP_ID = 'eate_widget';
	const ATE_DASHBOARD_ID = 'eate_dashboard';
	const TAB_ORDER = 10000;
	const CONTAINER_SELECTOR = '#ams-ate-console';
	const TAB_SELECTOR = '.wpml-tabs .nav-tab.nav-tab-active.nav-tab-ate-ams';
	const SLUG = 'ate-ams';


	public function get_caption() {
		return __( 'Payments & Maintenance', 'sitepress' );
	}

	public function get_description() {
		return '<p class="wpml-tab-description">' . __( 'Balance, invoices and tools', 'sitepress' ) . '</p>';
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
		if ( $this->proxyInterceptorLoader->shouldEnableProxy() ) {
			$this->proxyInterceptorLoader->enable();
		}

		$this->admin_enqueue_tab_scripts();

		if ( is_admin() ) {
			$this->dashboardLoader->registerScript();
			$this->dashboardLoader->initializeScript($this->get_ams_constructor());
		}
	}



	protected function is_tab() {
		$sm   = Sanitize::stringProp( 'sm', $_GET );
		$page = Sanitize::stringProp( 'page', $_GET );

		return $sm && $page && self::SLUG === $sm && WPML_TM_FOLDER . '/menu/main.php' === $page;
	}

	public function getCachingManager() {
		return null;
	}

}
