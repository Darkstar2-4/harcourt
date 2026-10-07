<?php

class WPML_TM_Post_View_Link_Anchor extends WPML_TM_Post_Link_Anchor {

	protected function link_target() {

		return $this->sitepress->get_wp_api()->get_permalink( $this->post_id );
	}
}