<?php
namespace WPML\BlockEditor\Blocks\LanguageSwitcher\Model\Label;

use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\LanguageItem;

interface LabelTemplateInterface {
	public function matchesXPath( \DOMXPath $domXPath, $prefix );

	public function getDisplayName( LanguageItem $languageItem );
}
