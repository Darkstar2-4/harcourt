<?php

interface IWPML_Page_Builders_Translatable_Nodes {

	public function get( $node_id, $element );

	public function update( $node_id, $element, WPML_PB_String $string );

	public function get_string_name( $node_id, $field, $settings );

	public function initialize_nodes_to_translate();
}