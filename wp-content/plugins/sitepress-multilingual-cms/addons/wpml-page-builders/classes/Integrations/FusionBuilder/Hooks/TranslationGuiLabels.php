<?php

namespace WPML\Compatibility\FusionBuilder\Hooks;

use WPML\Compatibility\BaseTranslationGuiLabels;
use WPML\LIB\WP\Hooks;
use function WPML\FP\spreadArgs;

class TranslationGuiLabels extends BaseTranslationGuiLabels {

	const POST_TYPE_TEMPLATE        = 'fusion_template';
	const POST_TYPE_OFF_CANVAS      = 'awb_off_canvas';
	const POST_TYPE_ELEMENT         = 'fusion_element';
	const POST_TYPE_ELASTIC_SLIDERS = 'themefusion_elastic';
	const POST_TYPE_PORTFOLIO       = 'avada_portfolio';
	const POST_TYPE_FAQ             = 'avada_faq';
	const POST_TYPE_LAYOUT_SECTION  = 'fusion_tb_section';

	protected function getPostTypes() {
		return [
			self::POST_TYPE_TEMPLATE,
			self::POST_TYPE_OFF_CANVAS,
			self::POST_TYPE_ELEMENT,
			self::POST_TYPE_ELASTIC_SLIDERS,
			self::POST_TYPE_PORTFOLIO,
			self::POST_TYPE_FAQ,
		];
	}

	protected function getFormat() {
		// Translators: %s: Post type label. For example, Avada Templates.
		return __( 'Avada %s', 'sitepress' );
	}

	public function adjustObjectLabels( $postTypeObject ) {
		if ( $postTypeObject->name === self::POST_TYPE_LAYOUT_SECTION ) {
			$postTypeObject->labels->singular_name = $this->formatLabel( $postTypeObject->labels->singular_name, $postTypeObject->name, false );
			return $postTypeObject;
		}

		if ( ! in_array( $postTypeObject->name, $this->getPostTypes(), true ) ) {
			return $postTypeObject;
		}

		$postTypeObject->labels->name          = $this->formatLabel( $postTypeObject->labels->name, $postTypeObject->name, true );
		$postTypeObject->labels->singular_name = $this->formatLabel( $postTypeObject->labels->singular_name, $postTypeObject->name, false );

		return $postTypeObject;
	}

}
