<?php

class WPML_Page_Name_Query_Filter extends WPML_Name_Query_Filter_Translated {

	protected $id_index = 'page_id';

	public function __construct( &$sitepress, &$post_translations, &$wpdb ) {
		parent::__construct( 'page', $sitepress, $post_translations, $wpdb );
		$this->indexes = array( 'name', 'pagename' );
	}

	protected function maybe_adjust_query_by_pid( $page_query, $pid, $index ) {
		$page_query = parent::maybe_adjust_query_by_pid( $page_query, $pid, $index );

		$is_page_for_posts = 'page' == get_option( 'show_on_front' ) && (int) $pid === (int) get_option( 'page_for_posts' );
		if ( $is_page_for_posts ) {
			$page_query->query_vars['post_status'] = [ 'publish', 'private' ];
			$page_query->query_vars['perm']        = 'readable';
			$page_query->query_vars['name']        = '';
			$page_query->query_vars['page_id']     = 0;
			$page_query->post_status               = [ 'publish', 'private' ];
			$page_query->is_page                   = false;
			$page_query->is_singular               = false;
			$page_query->is_home                   = true;
			$page_query->is_posts_page             = true;
		}

		return $page_query;
	}


	protected function adjusting_id( $page_query ) {
		$page_query->is_page = true;

		return $page_query;
	}
}
