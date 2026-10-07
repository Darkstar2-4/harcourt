<?php

namespace WPML\Compatibility\Enfold\Hooks;

use WPML\Compatibility\BaseTranslationGuiLabels;

class TranslationGuiLabels extends BaseTranslationGuiLabels {

	const POST_TYPE_TEMPLATE  = 'alb_custom_layout';
	const POST_TYPE_PORTFOLIO = 'portfolio';

	protected function getPostTypes() {
		return [
			self::POST_TYPE_TEMPLATE,
			self::POST_TYPE_PORTFOLIO,
		];
	}

	protected function getFormat() {
		// Translators: %s: Post type label. For example, Enfold Templates.
		return __( 'Enfold %s', 'sitepress' );
	}

}
