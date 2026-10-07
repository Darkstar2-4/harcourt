<?php

interface IWPML_PB_Media_Update {

	public function translate( $post );

	public function find_media( $post );

	public function get_media();
}
