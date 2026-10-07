<?php

abstract class WPML_Cornerstone_Media_Node {

	protected $media_translate;

	public function __construct( IWPML_PB_Media_Find_And_Translate $media_translate ) {
		$this->media_translate = $media_translate;
	}

	abstract public function translate( $node_data, $target_lang, $source_lang );
}
