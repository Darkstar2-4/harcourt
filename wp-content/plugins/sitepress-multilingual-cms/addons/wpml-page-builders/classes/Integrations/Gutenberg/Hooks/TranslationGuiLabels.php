<?php

namespace WPML\PB\Gutenberg\Hooks;

use WPML\Compatibility\BaseTranslationGuiLabels;

class TranslationGuiLabels extends BaseTranslationGuiLabels implements \WPML\PB\Gutenberg\Integration {

	const POST_TYPE_PATTERN       = 'wp_block';
	const POST_TYPE_TEMPLATE      = 'wp_template';
	const POST_TYPE_TEMPLATE_PART = 'wp_template_part';

	protected function getPostTypes() {
		return [
			self::POST_TYPE_PATTERN,
			self::POST_TYPE_TEMPLATE,
			self::POST_TYPE_TEMPLATE_PART,
		];
	}

	protected function getFormat() {
		// Translators: %s: Post type label. For example, Site Templates.
		return __( 'Site %s', 'sitepress' );
	}

}
