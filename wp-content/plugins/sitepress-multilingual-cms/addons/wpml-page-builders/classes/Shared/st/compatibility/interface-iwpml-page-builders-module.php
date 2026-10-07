<?php

interface IWPML_Page_Builders_Module {

	const FIELD_SEPARATOR = '>';

	public function get( $node_id, $element, $strings );

	public function update( $node_id, $element, WPML_PB_String $pbString );
}
