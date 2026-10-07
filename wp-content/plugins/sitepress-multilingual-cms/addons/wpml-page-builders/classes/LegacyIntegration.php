<?php

namespace WPML\PB;

use WPML\PB\Integrations\Divi\Helper;

use function WPML\Container\make;

class LegacyIntegration {

	public static function load() {
		global $sitepress;

		$integrationClasses = [
			\WPML\PB\FullSiteEditing\TemplateTranslationHooks::class,
		];

		if ( defined( 'WPB_VC_VERSION' ) ) {
			$integrationClasses[] = \WPML\Compatibility\WPBakery\Hooks\TranslationJobLabels::class;
			$integrationClasses[] = \WPML\Compatibility\WPBakery\Hooks\TranslationJobImages::class;
			$integrationClasses[] = \WPML\Compatibility\WPBakery\Hooks\TranslationGuiLabels::class;
			$integrationClasses[] = \WPML\Compatibility\WPBakery\Hooks\Editor::class;

			$wpml_visual_composer = new \WPML_Compatibility_Plugin_Visual_Composer( new \WPML_Debug_BackTrace( null, 12 ) );
			$wpml_visual_composer->add_hooks();

			$wpml_visual_composer_grid = new \WPML_Compatibility_Plugin_Visual_Composer_Grid_Hooks(
				$sitepress,
				new \WPML_Translation_Element_Factory( $sitepress )
			);
			$wpml_visual_composer_grid->add_hooks();

			make( \WPML\Compatibility\WPBakery\Styles::class )->add_hooks();
		}

		if ( defined( 'FUSION_BUILDER_VERSION' ) ) {
			$integrationClasses[] = \WPML_Compatibility_Plugin_Fusion_Hooks_Factory::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\Frontend\Hooks::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\Backend\Hooks::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\DynamicContent::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\FormContent::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\FormNotifications::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\Hooks\Editor::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\Hooks\TranslationJobLabels::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\Hooks\TranslationJobImages::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\Hooks\TranslationGuiLabels::class;
			$integrationClasses[] = \WPML\Compatibility\FusionBuilder\Hooks\MultilingualOptions::class;
		}

		if ( function_exists( 'avia_lang_setup' ) ) {
			$integrationClasses[] = \WPML\Compatibility\Enfold\Hooks\TranslationJobLabels::class;
			$integrationClasses[] = \WPML\Compatibility\Enfold\Hooks\TranslationJobImages::class;
			$integrationClasses[] = \WPML\Compatibility\Enfold\Hooks\TranslationGuiLabels::class;

			global $iclTranslationManagement;
			$enfold = new \WPML_Compatibility_Theme_Enfold( $iclTranslationManagement );
			$enfold->init_hooks();
		}

		if ( defined( 'ET_BUILDER_THEME' ) || defined( 'ET_BUILDER_PLUGIN_VERSION' ) ) {
			$integrationClasses[] = \WPML_Compatibility_Divi::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\DynamicContent::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Search::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\DiviOptionsEncoding::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\ThemeBuilderFactory::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Builder::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\TinyMCE::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\DisplayConditions::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\DoubleQuotes::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\WooShortcodes::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Hooks\Editor::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Hooks\EditorFrontend::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Hooks\DomainsBackendEditor::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Hooks\GutenbergUpdate::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Hooks\TranslationJobLabels::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Hooks\TranslationJobImages::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\Hooks\TranslationGuiLabels::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\ConvertThemeOptions::class;
			$integrationClasses[] = \WPML\Compatibility\Divi\DynamicContent\Hooks::class;

			if ( Helper::isRunningDivi5() ) {
				$integrationClasses[] = \WPML\Compatibility\Divi\V5\DynamicContent::class;
				$integrationClasses[] = \WPML\Compatibility\Divi\V5\LanguageSwitcher::class;
				$integrationClasses[] = \WPML\Compatibility\Divi\V5\MediaUrls::class;
				$integrationClasses[] = \WPML\Compatibility\Divi\V5\CanvasHooks::class;
				$integrationClasses[] = \WPML\Compatibility\Divi\V5\WooCommerce\ProductDescriptionCache::class;
			}
		}
		$loader = new \WPML_Action_Filter_Loader();
		$loader->load( $integrationClasses );
	}
}
