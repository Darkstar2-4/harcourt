<?php

abstract class WPML_TM_Post_Link {

	protected $sitepress;

	protected $post_id;

	public function __construct( $sitepress, $post_id ) {
		$this->sitepress = $sitepress;
		$this->post_id   = $post_id;
	}
}