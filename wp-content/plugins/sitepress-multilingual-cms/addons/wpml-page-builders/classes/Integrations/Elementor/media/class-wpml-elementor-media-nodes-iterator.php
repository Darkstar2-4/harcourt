<?php

class WPML_Elementor_Media_Nodes_Iterator implements IWPML_PB_Media_Nodes_Iterator {

	private $node_provider;

	public function __construct( WPML_Elementor_Media_Node_Provider $node_provider ) {
		$this->node_provider = $node_provider;
	}

	public function translate( $data_array, $lang, $source_lang ) {
		foreach ( $data_array as &$node ) {
			if ( $this->is_parent_node( $node ) ) {
				$node['elements'] = $this->translate( $node['elements'], $lang, $source_lang );
			} elseif ( $this->is_valid_media_node( $node ) ) {
				$node = $this->translate_node( $node, $lang, $source_lang );
			}

			if ( isset( $node['settings'] ) ) {
				$node['settings'] = $this->node_provider->get( 'all_nodes' )->translate( $node['settings'], $lang, $source_lang );
			}
		}

		return $data_array;
	}

	private function is_parent_node( $node ) {
		return isset( $node['elements'] ) && $node['elements'];
	}

	private function is_valid_media_node( $node ) {
		return isset( $node['elType'], $node['widgetType'], $node['settings'] )
			&& 'widget' === $node['elType'];
	}

	private function translate_node( $node_data, $lang, $source_lang ) {
		$node = $this->node_provider->get( $node_data['widgetType'] );

		if ( $node ) {
			$node_data['settings'] = $node->translate( $node_data['settings'], $lang, $source_lang );
		}

		return $node_data;
	}

	public function get_media() {
		return $this->node_provider->get_media();
	}
}
