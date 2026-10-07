<?php

class WPML_TM_Support_Info_Filter {
	private $support_info;

	function __construct( WPML_TM_Support_Info $support_info ) {
		$this->support_info = $support_info;
	}

	public function filter_blocks( array $blocks ) {

		$is_simplexml_extension_loaded      = $this->support_info->is_simplexml_extension_loaded();
		$blocks['php']['data']['simplexml'] = array(
			'label'      => __( 'SimpleXML extension', 'wpml-translation-management' ),
			'value'      => $is_simplexml_extension_loaded ? __( 'Loaded', 'wpml-translation-management' ) : __( 'Not loaded', 'wpml-translation-management' ),
		);

		return $blocks;
	}
}
