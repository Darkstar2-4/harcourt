<?php
namespace WPML\BlockEditor\Blocks\LanguageSwitcher\Model;

class LanguageSwitcherTemplate {

	private $languageItemTemplate;

	private $currentLanguageItemTemplate;

	private $DOMDocument;

	private $DOMXpath;

	public function __construct(
		LanguageItemTemplate $languageItemTemplate,
		LanguageItemTemplate $currentLanguageItemTemplate,
		\DOMDocument $DOMDocument
	) {
		$this->languageItemTemplate = $languageItemTemplate;
		$this->currentLanguageItemTemplate = $currentLanguageItemTemplate;
		$this->DOMDocument = $DOMDocument;
		$this->DOMXpath = new \DOMXPath( $DOMDocument );
	}

	public function getLanguageItemTemplate() {
		return $this->languageItemTemplate;
	}

	public function getCurrentLanguageItemTemplate() {
		return $this->currentLanguageItemTemplate;
	}

	public function getDOMXPath() {
		return $this->DOMXpath;
	}

	public function getDOMDocument() {
		return $this->DOMDocument;
	}
}