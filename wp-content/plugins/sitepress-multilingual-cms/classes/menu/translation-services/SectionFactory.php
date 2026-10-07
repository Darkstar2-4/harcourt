<?php

namespace WPML\TM\Menu\TranslationServices;

use WPML\LIB\WP\Http;
use WPML\TM\Geolocalization;
use function WPML\Container\make;
use function WPML\FP\partial;
use function WPML\FP\partialRight;

class SectionFactory implements \IWPML_TM_Admin_Section_Factory {
	public function create() {
		global $sitepress;

		return new Section(
			$sitepress,
			$this->site_key_exists() ?
				$this->createServicesListRenderer() :
				partial( NoSiteKeyTemplate::class . '::render', $this->getTemplateRenderer() )
		);
	}

	private function site_key_exists() {
		$site_key = false;

		if ( class_exists( 'WP_Installer' ) ) {
			$repository_id = 'wpml';
			$site_key      = \WP_Installer()->get_site_key( $repository_id );
		}

		return $site_key;
	}

	private function createServicesListRenderer() {
		$getServicesTabs = partial(
			ServicesRetriever::class . '::get',
			$this->getTpApiServices(),
			Geolocalization::getCountryByIp( Http::post() ),
			partialRight(
				[ ServiceMapper::class, 'map' ],
				[ ActiveServiceRepository::class, 'getId' ]
			)
		);

		return partial(
			MainLayoutTemplate::class . '::render',
			$this->getTemplateRenderer(),
			ActiveServiceTemplateFactory::createRenderer(),
			\TranslationProxy::has_preferred_translation_service(),
			$getServicesTabs
		);
	}

	private function getTemplateRenderer() {
		$template = make(
			\WPML_Twig_Template_Loader::class,
			[
				':paths' => [
					WPML_TM_PATH . '/templates/menus/translation-services/',
					WPML_PLUGIN_PATH . '/templates/pagination/',
				],
			]
		)->get_template();

		return [ $template, 'show' ];
	}

	private function getTpApiServices() {
		return make( \WPML_TP_Client_Factory::class )->create()->services();
	}
}
