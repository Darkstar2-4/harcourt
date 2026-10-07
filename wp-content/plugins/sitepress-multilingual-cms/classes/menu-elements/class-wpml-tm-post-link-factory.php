<?php

class WPML_TM_Post_Link_Factory {

	private $sitepress;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress = $sitepress;
	}

	public function view_link( $post_id ) {

		return (string) ( new WPML_TM_Post_View_Link_Title( $this->sitepress,
			(int) $post_id ) );
	}

	public function view_link_anchor( $post_id, $anchor, $target = '' ) {

		return (string) ( new WPML_TM_Post_View_Link_Anchor( $this->sitepress,
			(int) $post_id, $anchor, $target ) );
	}

	public function edit_link_anchor( $post_id, $anchor ) {

		return (string) ( new WPML_TM_Post_Edit_Link_Anchor( $this->sitepress,
			(int) $post_id, $anchor ) );
	}
}