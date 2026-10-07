<?php


class WPML_Taxonomy_Translation {

	private $ui = null;
	private $taxonomy = '';
	private $args = array();
	private $screen_options_factory = null;

	public function __construct( $taxonomy = '', $args = array(), $screen_options_factory = null ) {
		$this->taxonomy = $taxonomy;
		$this->args = $args;
		$this->screen_options_factory = $screen_options_factory;
		add_action('init', array($this, 'prepareUi'), SitePress::INIT_HOOK_TRANSLATIONS_PRIORITY + 1 );
	}

	public function prepareUi() {
		global $sitepress;
		$this->ui = new WPML_Taxonomy_Translation_UI( $sitepress, $this->taxonomy, $this->args, $this->screen_options_factory );
	}

	public function render() {
		if ( ! $this->ui ) {
			$this->prepareUi();
		}
		$this->ui->render();
	}

}
