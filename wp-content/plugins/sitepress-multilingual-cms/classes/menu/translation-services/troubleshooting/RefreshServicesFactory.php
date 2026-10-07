<?php

namespace WPML\TM\Menu\TranslationServices\Troubleshooting;

use function WPML\Container\make;

class RefreshServicesFactory implements \IWPML_Backend_Action_Loader {

	public function create() {
		$hooks = null;

		if ( $this->is_visible() ) {
			$hooks = $this->create_an_instance();
		}

		return $hooks;
	}

	public function create_an_instance() {
		$templateService = make(
			\WPML_Twig_Template_Loader::class,
			[ ':paths' => [ WPML_TM_PATH . '/templates/menus/translation-services' ] ]
		);

		$tpClientFactory = make( \WPML_TP_Client_Factory::class );

		return new RefreshServices( $templateService->get_template(), $tpClientFactory->create()->services() );
	}

	private function is_visible() {
		return ( isset( $_GET['page'] ) && 'sitepress-multilingual-cms/menu/troubleshooting.php' === $_GET['page'] ) ||
			   ( isset( $_POST['action'] ) && RefreshServices::AJAX_ACTION === $_POST['action'] );
	}
}
