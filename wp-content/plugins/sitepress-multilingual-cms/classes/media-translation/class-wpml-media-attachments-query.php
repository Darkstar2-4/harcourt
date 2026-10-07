<?php

class WPML_Media_Attachments_Query implements IWPML_Action {


	public function add_hooks() {
		add_action( 'pre_get_posts', array( $this, 'adjust_attachment_query_action' ), 10 );
	}

	public function adjust_attachment_query_action( $query ) {
		return $this->adjust_attachment_query( $query );
	}

	public function adjust_attachment_query( $query ) {
		$should_suppress_filters = ! ( isset( $query->query_vars['force_suppress_filters'] ) && true === $query->query_vars['force_suppress_filters'] );
		if ( $should_suppress_filters && ! is_admin() ) {
			$should_suppress_filters = false;
		}

		if ( isset( $query->query['post_type'] ) && 'attachment' === $query->query['post_type'] && $should_suppress_filters ) {
			$query->set( 'suppress_filters', false );
		}
		return $query;

	}
}
