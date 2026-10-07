<?php

class WPML_TM_String_Xliff_Reader extends WPML_TM_Xliff_Reader {

	public function get_data( $content ) {
		$xliff = $this->load_xliff( $content );
		$data  = array();
		if ( $xliff && ! $xliff instanceof WP_Error ) {
			foreach ( $xliff->{'file'}->{'body'}->children() as $node ) {
				$target = $this->get_xliff_node_target( $node );

				if ( ! $target && $target !== '0' ) {
					return $this->invalid_xliff_error();
				}
				$target                       = $this->replace_xliff_new_line_tag_with_new_line( $target );
				$attr                         = $node->attributes();
				$data[ (string) $attr['id'] ] = $target;
			}
		}
		return $data;
	}
}
