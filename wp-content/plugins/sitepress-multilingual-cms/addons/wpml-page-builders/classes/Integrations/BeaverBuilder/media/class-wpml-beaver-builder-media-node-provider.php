<?php

class WPML_Beaver_Builder_Media_Node_Provider {

	private $media_translate;

	private $nodes = array();

	public function __construct( IWPML_PB_Media_Find_And_Translate $media_translate ) {
		$this->media_translate = $media_translate;
	}

	public function get( $type ) {
		if ( ! array_key_exists( $type, $this->nodes ) ) {
			switch ( $type ) {
				case 'photo':
					$node = new WPML_Beaver_Builder_Media_Node_Photo( $this->media_translate );
					break;

				case 'gallery':
					$node = new WPML_Beaver_Builder_Media_Node_Gallery( $this->media_translate );
					break;

				case 'content-slider':
					$node = new WPML_Beaver_Builder_Media_Node_Content_Slider( $this->media_translate );
					break;

				case 'slideshow':
					$node = new WPML_Beaver_Builder_Media_Node_Slideshow( $this->media_translate );
					break;

				default:
					$node = null;
			}

			$this->nodes[ $type ] = $node;
		}

		return $this->nodes[ $type ];
	}

	public function get_media() {
		return $this->media_translate->get_used_media_in_post();
	}
}
