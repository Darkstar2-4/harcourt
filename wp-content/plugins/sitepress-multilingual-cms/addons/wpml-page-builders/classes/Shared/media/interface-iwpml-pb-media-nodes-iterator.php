<?php

interface IWPML_PB_Media_Nodes_Iterator {

	public function translate( $data, $lang, $source_lang );

	public function get_media();
}
