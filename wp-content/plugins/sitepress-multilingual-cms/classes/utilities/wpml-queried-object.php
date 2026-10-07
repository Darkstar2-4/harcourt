<?php

class WPML_Queried_Object {

	private $sitepress;

	private $queried_object;

	private $queried_object_details;

	public function __construct( SitePress $sitepress ) {
		$this->sitepress      = $sitepress;
		$this->queried_object = get_queried_object();
	}

	public function has_object() {
		return (bool) $this->queried_object;
	}

	public function get_source_language_code() {
		return $this->get_queried_object_detail( 'source_language_code' );
	}

	public function get_language_code() {
		return $this->get_queried_object_detail( 'language_code' );
	}

	private function get_queried_object_detail( $key ) {
		$detail = null;

		if ( ! $this->queried_object_details ) {

			if ( $this->is_post() ) {

				$this->queried_object_details = $this->sitepress->get_element_language_details(
					$this->get_id(),
					$this->get_element_type()
				);
			}
		}

		if ( isset( $this->queried_object_details->{$key} ) ) {
			$detail = $this->queried_object_details->{$key};
		}

		return $detail;
	}

	public function is_post() {
		return isset( $this->queried_object->ID, $this->queried_object->post_type );
	}

	public function get_id() {
		$id = null;

		if ( isset( $this->queried_object->ID ) ) {
			$id = $this->queried_object->ID;
		}

		return $id;
	}

	public function get_element_type() {
		$type = null;

		if ( $this->is_instance_of_post_type() ) {
			$type = 'post_' . $this->get_post_type_name();
		}
		if ( $this->is_instance_of_post() ) {
			$type = 'post_' . $this->get_post_type();
		}
		if ( $this->is_instance_of_taxonomy() ) {
			$type = 'tax_' . $this->get_taxonomy();
		}

		return $type;
	}

	public function get_source_url() {
		$url            = null;
		$language_links = $this->sitepress->get_ls_languages();

		if ( isset( $language_links[ $this->get_source_language_code() ]['url'] ) ) {
			$url = $language_links[ $this->get_source_language_code() ]['url'];
		}

		return $url;
	}

	public function get_post_type() {
		if ( $this->is_instance_of_post() ) {
			return $this->queried_object->post_type;
		}

		return null;
	}

	public function get_taxonomy() {
		if ( $this->is_instance_of_taxonomy() ) {
			return $this->queried_object->taxonomy;
		}
	}

	public function get_post_type_name() {
		if ( $this->is_instance_of_post_type() ) {
			return $this->queried_object->name;
		}
	}

	public function is_instance_of_post() {
		return $this->queried_object instanceof WP_Post;
	}

	public function is_instance_of_taxonomy() {
		return $this->queried_object instanceof WP_Term;
	}

	public function is_instance_of_post_type() {
		return $this->queried_object instanceof WP_Post_Type;
	}

	public function is_instance_of_user() {
		return $this->queried_object instanceof WP_User;
	}
}
