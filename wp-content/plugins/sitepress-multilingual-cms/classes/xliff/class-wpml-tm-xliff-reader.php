<?php

abstract class WPML_TM_Xliff_Reader extends WPML_TM_Xliff_Shared {

	abstract public function get_data( $content );

	public function load_xliff( $content ) {
		try {
			$xml = simplexml_load_string( $content );
		} catch ( Exception $e ) {
			$xml = false;
		}

		return $xml ? $xml
			: new WP_Error(
				'not_xml_file',
				sprintf(
					__( 'The xliff file could not be read.', 'wpml-translation-management' )
				)
			);
	}
}
