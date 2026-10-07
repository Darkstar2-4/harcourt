<?php

class WPML_TM_MCS_Search_Render {

	const TM_MCS_SEARCH_TEMPLATE = 'tm-mcs-search.twig';

	private $template;

	private $search_string;

	public function __construct( IWPML_Template_Service $template, $search_string ) {
		$this->template      = $template;
		$this->search_string = $search_string;
	}

	public function get_model() {
		$model = array(
			'strings'       => array(
				'search_for' => __( 'Search for', 'wpml-translation-management' ),
			),
			'search_string' => $this->search_string,
		);

		return $model;
	}

	public function render() {
		return $this->template->show( $this->get_model(), self::TM_MCS_SEARCH_TEMPLATE );
	}
}
