<?php

class WPML_TM_Post_View_Link_Title extends WPML_TM_Post_View_Link_Anchor {

	public function __construct( &$sitepress, $post_id ) {
		parent::__construct( $sitepress, $post_id,
			$sitepress->get_wp_api()->get_the_title( $post_id ) );
	}
}