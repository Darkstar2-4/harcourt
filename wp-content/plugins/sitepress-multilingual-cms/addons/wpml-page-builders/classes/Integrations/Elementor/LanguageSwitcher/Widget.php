<?php
namespace WPML\PB\Elementor\LanguageSwitcher;

use Elementor\Widget_Base;

class Widget extends Widget_Base {

	private $adaptor;

	public function __construct( $data = [], $args = null, ?WidgetAdaptor $adaptor = null ) {
		$this->adaptor = $adaptor ?: new WidgetAdaptor();
		$this->adaptor->setTarget( $this );
		parent::__construct( $data, $args );
	}

	public function get_name() {
		return $this->adaptor->getName();
	}

	public function get_title() {
		return $this->adaptor->getTitle();
	}

	public function get_icon() {
		return $this->adaptor->getIcon();
	}

	public function get_categories() {
		return $this->adaptor->getCategories();
	}

	protected function register_controls() {
		$this->adaptor->registerControls();
	}

	protected function render() {
		$this->adaptor->render();
	}
}
