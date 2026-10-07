<?php

namespace WPML\Legacy\Translation\Save\SyncParentPost;

class SyncParentPost {

	private $isSyncPageParentEnabled;

	private $wpml_post_translations;

	private $wpdb;

	public function __construct( \wpdb $wpdb, \SitePress $sitepress, \WPML_Post_Translation $wpml_post_translations ) {
		$this->isSyncPageParentEnabled = $sitepress->get_setting( 'sync_page_parent' );
		$this->wpml_post_translations  = $wpml_post_translations;
		$this->wpdb					= $wpdb;
	}

	public function linkParentTranslatedPostOrFlagOriginal( $original_parent_post_id, $language_code, $postarr ) {
		if ( ! $this->isSyncPageParentEnabled ) {
			return $postarr;
		}
		if ( $original_parent_post_id ) {
			$translated_parent_id = $this->wpml_post_translations->element_id_in( $original_parent_post_id, $language_code );
			if ( isset( $translated_parent_id ) ) {

				$_POST['post_parent'] = $postarr['post_parent'] = $translated_parent_id;
				$_POST['parent_id']   = $postarr['parent_id'] = $translated_parent_id;

			} else {
				update_post_meta( $original_parent_post_id, $this->getMetaChildKey( $language_code ), true);
			}
		}

		return $postarr;
	}

	public function linkUnlinkedChildPosts( $original_post_id, $language_code, $translated_post_id ) {
		if ( ! $this->isSyncPageParentEnabled ) {
			return;
		}
		$hasUnlinkedChilds = get_post_meta( $original_post_id, $this->getMetaChildKey( $language_code ), true );

		if ( ! $hasUnlinkedChilds ) {
			return;
		}

		$query = $this->wpdb->prepare( "SELECT ID FROM {$this->wpdb->posts} WHERE post_parent = %d", $original_post_id );
		$original_child_post_ids = $this->wpdb->get_col($query);

		if ( empty( $original_child_post_ids ) ) {
			delete_post_meta( $original_post_id, $this->getMetaChildKey( $language_code ) );
			return;
		}

		$this->wpml_post_translations->prefetch_ids( $original_child_post_ids );
		foreach ( $original_child_post_ids as $original_child_post_id ) {
			$translated_child_post_id = $this->wpml_post_translations->element_id_in( $original_child_post_id, $language_code );
			if ( isset( $translated_child_post_id ) ) {
				wp_update_post( array(
					'ID' => $translated_child_post_id,
					'post_parent' => $translated_post_id,
				) );
			}
		}

		delete_post_meta( $original_post_id, $this->getMetaChildKey( $language_code ) );
	}


	private function getMetaChildKey( $language_code ) {
		return sprintf( '_wpml_has_%s_unlinked_childs', $language_code );
	}
}