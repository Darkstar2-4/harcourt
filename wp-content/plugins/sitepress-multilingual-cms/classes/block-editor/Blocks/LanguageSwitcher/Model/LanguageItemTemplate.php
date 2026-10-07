<?php
namespace WPML\BlockEditor\Blocks\LanguageSwitcher\Model;

use WPML\BlockEditor\Blocks\LanguageSwitcher\Model\Label\LabelTemplateInterface;

class LanguageItemTemplate {

	private $template;

	private $container;

	private $labelTemplate;

	public function __construct( \DOMNode $template, \DOMNode $container, ?LabelTemplateInterface $labelTemplate = null) {
		$this->template = $template;
		$this->container = $container;
		$this->labelTemplate = $labelTemplate;
	}

	public function getTemplate() {
		return $this->template;
	}

	public function getContainer() {
		return $this->container;
	}

	public function getLabelTemplate() {
		return $this->labelTemplate;
	}
}
