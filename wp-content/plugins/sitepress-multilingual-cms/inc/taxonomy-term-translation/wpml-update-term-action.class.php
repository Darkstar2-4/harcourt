<?php

class WPML_Update_Term_Action extends WPML_WPDB_And_SP_User {

	private $is_valid = true;
	private $is_update;
	private $wp_new_term_args = array();
	private $taxonomy;
	private $trid;
	private $lang_code;
	private $source_lang_code = null;
	private $existing_translations = array();
	private $term_id;
	private $old_slug;

	public function __construct( &$wpdb, &$sitepress, $args ) {
		parent::__construct( $wpdb, $sitepress );
		$term     = false;
		$slug     = '';
		$taxonomy = '';
		$lang_code = '';
		$trid      = null;
		$original_tax_id = false;
		$parent          = 0;
		$description     = false;
		$term_group      = false;
		$source_language = null;

		extract( $args, EXTR_OVERWRITE );

		if ( (string) $term !== '' && $taxonomy ) {
			$this->wp_new_term_args['name'] = $term;
			$this->taxonomy                 = $taxonomy;
		} else {
			$this->is_valid = false;

			return;
		}
		if ( $parent ) {
			$this->wp_new_term_args['parent'] = $parent;
		}
		if ( $description ) {
			$this->wp_new_term_args['description'] = $description;
		}
		if ( $term_group ) {
			$this->wp_new_term_args['term_group'] = $term_group;
		}
		$this->wp_new_term_args['term_group'] = $term_group;
		$this->is_valid                       = $this->set_language_information( $trid, $original_tax_id, $lang_code, $source_language );
		$this->set_action_type();
		if ( ! $this->is_update || ( $this->is_update && $slug != $this->old_slug && ! empty( $slug ) ) ) {
			if ( trim( $slug ) == '' ) {
				$slug = sanitize_title( $term );
			}
			$slug                           = WPML_Terms_Translations::term_unique_slug( $slug, $taxonomy, $lang_code );
			$this->wp_new_term_args['slug'] = $slug;
		}
	}

	public function execute() {
		global $sitepress;

		$switch_lang = new WPML_Temporary_Switch_Language( $sitepress, $this->lang_code );

		remove_action( 'create_term', array( $sitepress, 'create_term' ), 1 );
		remove_action( 'edit_term', array( $sitepress, 'create_term' ), 1 );
		add_action( 'create_term', array( $this, 'add_term_language_action' ), 1, 3 );
		add_filter( 'get_terms', array( 'WPML_Terms_Translations', 'get_terms_filter' ), 10, 2 );
		$new_term = false;

		if ( $this->is_valid ) {
			if ( $this->is_update && $this->term_id ) {
				$new_term = wp_update_term( $this->term_id, $this->taxonomy, $this->wp_new_term_args );
			} else {
				$new_term = wp_insert_term( $this->wp_new_term_args['name'], $this->taxonomy, $this->wp_new_term_args );
			}
		}
		add_action( 'create_term', array( $sitepress, 'create_term' ), 1, 3 );
		add_action( 'edit_term', array( $sitepress, 'create_term' ), 1, 3 );
		remove_action( 'create_term', array( $this, 'add_term_language_action' ), 1 );
		remove_filter( 'get_terms', array( 'WPML_Terms_Translations', 'get_terms_filter' ), 10 );

		if ( ! is_array( $new_term ) ) {
			$new_term = false;
		}

		unset( $switch_lang );
		return $new_term;
	}

	public function add_term_language_action( $term_id, $term_taxonomy_id, $taxonomy ) {
		if ( $this->is_valid && ! $this->is_update && $this->taxonomy == $taxonomy ) {
			$this->sitepress->set_element_language_details(
				$term_taxonomy_id,
				'tax_' . $taxonomy,
				$this->trid,
				$this->lang_code,
				$this->source_lang_code
			);
		}
	}

	private function set_language_information( $trid, $original_tax_id, $lang_code, $source_language ) {
		if ( ! $lang_code || ! $this->sitepress->is_active_language( $lang_code ) ) {
			return false;
		} else {
			$this->lang_code = $lang_code;
		}
		if ( ! $trid && $original_tax_id ) {
			$trid = $this->sitepress->get_element_trid( $original_tax_id, 'tax_' . $this->taxonomy );
		}
		if ( $trid ) {
			$this->trid                  = $trid;
			$this->existing_translations = $this->sitepress->get_element_translations( $trid, 'tax_' . $this->taxonomy );

			foreach ( $this->existing_translations as $lang => $translation ) {
				if ( $original_tax_id && isset( $translation->element_id ) && $translation->element_id == $original_tax_id && isset( $translation->language_code ) && $translation->language_code ) {
					$this->source_lang_code = $translation->language_code;
					break;
				} elseif ( isset( $translation->language_code ) && $translation->language_code && ! $translation->source_language_code ) {
					$this->source_lang_code = $translation->language_code;
				}
			}
		}

		return true;
	}

	private function set_action_type() {
		if ( ! $this->trid ) {
			$this->is_update = false;
		} elseif ( isset( $this->existing_translations[ $this->lang_code ] ) ) {
			$existing_db_entry = $this->existing_translations[ $this->lang_code ];
			if ( isset( $existing_db_entry->element_id ) && $existing_db_entry->element_id ) {
				$term = $this->wpdb->get_row(
					$this->wpdb->prepare(
						"SELECT t.term_id, t.slug FROM {$this->wpdb->terms} AS t
						 JOIN {$this->wpdb->term_taxonomy} AS tt ON t.term_id=tt.term_id
						 WHERE term_taxonomy_id=%d",
						$existing_db_entry->element_id
					)
				);
				if ( $term->term_id && $term->slug ) {
					$this->is_update = true;
					$this->term_id   = $term->term_id;
					$this->old_slug  = $term->slug;
				} else {
					$this->is_update = false;
				}
			} else {
				$this->sitepress->delete_element_translation( $this->trid, 'tax_' . $this->taxonomy, $this->lang_code );
				$this->is_update = false;
			}
		} else {
			$this->is_update = false;
		}
	}
}
