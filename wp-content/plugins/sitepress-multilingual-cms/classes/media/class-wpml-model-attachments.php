<?php

class WPML_Model_Attachments {
	const ATTACHMENT_TYPE = 'post_attachment';

	private $sitepress;

	private $status_helper;

	public function __construct( SitePress $sitepress, WPML_Post_Status $status_helper ) {
		$this->sitepress = $sitepress;
		$this->status_helper = $status_helper;
	}

	public function duplicate_post_meta_data( $attachment_id, $duplicated_attachment_id ) {
		$media_meta_data_duplicate = apply_filters( 'wpml_media_meta_data_duplicate', [
			'_wp_attachment_metadata',
			'_wp_attached_file',
			'_wp_attachment_image_alt',
		] );

		foreach ( $media_meta_data_duplicate as $meta_key ) {
			$duplicated_meta_value = get_post_meta( $duplicated_attachment_id, $meta_key, true );

			if ( ! $duplicated_meta_value ) {
				$source_meta_value = get_post_meta( $attachment_id, $meta_key, true );
				update_post_meta( $duplicated_attachment_id, $meta_key, $source_meta_value );
			}
		}

		update_post_meta( $duplicated_attachment_id, 'wpml_media_processed', 1 );

		do_action( 'wpml_media_create_duplicate_attachment', $attachment_id, $duplicated_attachment_id );
	}

	public function find_duplicated_attachment( $trid, $target_language ) {
		$attachment_translations = $this->sitepress->get_element_translations( $trid, self::ATTACHMENT_TYPE, true, true );
		if ( is_array( $attachment_translations ) ) {
			foreach ( $attachment_translations as $attachment_translation ) {
				if ( $attachment_translation->language_code === $target_language ) {
					return get_post( $attachment_translation->element_id );
				}
			}
		}

		return null;
	}

	public function fetch_translated_parent_id( $attachment, $parent_id_of_attachement, $target_language ) {
		$translated_parent_id  = null;

		if ( null !== $attachment && $attachment->post_parent ) {
			$translated_parent_id = $attachment->post_parent;
		}

		if ( $parent_id_of_attachement ) {
			$parent_post = get_post( $parent_id_of_attachement );
			if ( $parent_post ) {
				$translated_parent_id = $parent_id_of_attachement;
				$parent_id_language_code = $this->sitepress->get_language_for_element( $parent_post->ID, 'post_' . $parent_post->post_type );
				if ( $parent_id_language_code !== $target_language ) {
					$translated_parent_id = $this->sitepress->get_object_id( $parent_post->ID, $parent_post->post_type, false, $target_language );
				}
			}
		}

		return $translated_parent_id;
	}

	public function update_parent_id_in_existing_attachment( $new_parent_id, $attachment ) {
		if ( $this->is_valid_post_type( $attachment->post_type ) ) {
			wp_update_post( array( 'ID' => $attachment->ID, 'post_parent' => $new_parent_id ) );
		}
	}

	private function is_valid_post_type( $post_type ) {
		$post_types = array_keys( get_post_types( ) );

		return in_array( $post_type, $post_types, true );
	}

	public function duplicate_attachment( $attachment_id, $target_language, $parent_id_in_target_language, $trid ) {
		$post = get_post( $attachment_id );
		$post->post_parent = $parent_id_in_target_language;
		$post->ID          = null;

		$duplicated_attachment_id = $this->insert_attachment( $post );
		if ( ! $duplicated_attachment_id ) {
			throw new WPML_Media_Exception( 'Error occured during inserting duplicated attachment to db' );
		}

		$this->add_language_information_to_attachment( $attachment_id, $duplicated_attachment_id, $target_language, $trid );

		return $duplicated_attachment_id;
	}


	private function insert_attachment( $post ) {
		$add_attachment_filters_temp = null;
		if ( array_key_exists( 'add_attachment', $GLOBALS['wp_filter'] ) ) {
			$add_attachment_filters_temp = $GLOBALS['wp_filter']['add_attachment'];
			unset( $GLOBALS['wp_filter']['add_attachment'] );
		}

		$duplicated_attachment_id = wp_insert_post( $post );
		if ( ! is_int( $duplicated_attachment_id ) ) {
			$duplicated_attachment_id = 0;
		}

		if ( null !== $add_attachment_filters_temp ) {
			$GLOBALS['wp_filter']['add_attachment'] = $add_attachment_filters_temp;
			unset( $add_attachment_filters_temp );
		}

		return $duplicated_attachment_id;
	}

	private function add_language_information_to_attachment( $attachment_id, $duplicated_attachment_id, $target_language, $trid ) {
		$source_language = $this->sitepress->get_language_for_element( $attachment_id, self::ATTACHMENT_TYPE );
		$this->sitepress->set_element_language_details( $duplicated_attachment_id, self::ATTACHMENT_TYPE, $trid, $target_language, $source_language );
		$this->status_helper->set_status( $duplicated_attachment_id, ICL_TM_DUPLICATE );
		$this->status_helper->set_update_status( $duplicated_attachment_id, false );
	}
}
