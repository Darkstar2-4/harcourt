<?php

class WPML_Term_Actions extends WPML_Full_Translation_API {

	private $delete_recursion_flag = false;

	public function generate_unique_term_slug_ajax_handler() {
		if ( $this->sitepress->get_wp_api()->is_ajax() && wp_verify_nonce( $_POST['nonce'], 'wpml_generate_unique_slug_nonce' ) ) {
			$term          = array_key_exists( 'term', $_POST ) ? sanitize_text_field( $_POST['term'] ) : '';
			$taxonomy      = array_key_exists( 'taxonomy', $_POST ) ? sanitize_text_field( $_POST['taxonomy'] ) : '';
			$language_code = array_key_exists( 'language_code', $_POST ) ? sanitize_text_field( $_POST['language_code'] ) : '';

			wp_send_json_success(
				array(
					'slug' => urldecode( $this->term_translations->generate_unique_term_slug( $term, '', $taxonomy, $language_code ) ),
				)
			);
		} else {
			wp_send_json_error();
		}
	}

	function save_term_actions( $tt_id, $taxonomy ) {
		if ( ! $this->sitepress->is_translated_taxonomy( $taxonomy ) ) {
			return;
		};
		$post_action  = filter_input( INPUT_POST, 'action' );
		$term_lang    = $this->get_term_lang( $tt_id, $post_action, $taxonomy );
		$trid         = $this->get_saved_term_trid( $tt_id, $post_action );
		$src_language = $this->term_translations->get_source_lang_code( $tt_id );
		$this->sitepress->set_element_language_details(
			$tt_id,
			'tax_' . $taxonomy,
			$trid,
			$term_lang,
			$src_language
		);

		add_action( "saved_{$taxonomy}", array( $this, 'sync_term_meta' ), PHP_INT_MAX, 2 );
	}

	public function sync_term_meta( $term_id, $tt_id ) {
		$is_new_term      = 'created_term' === current_filter();
		$sync_meta_action = new WPML_Sync_Term_Meta_Action( $this->sitepress, $tt_id, $is_new_term );
		$sync_meta_action->run();
	}

	function delete_term_actions( $term_taxonomy_id, $taxonomy_name ) {
		$element_type = 'tax_' . $taxonomy_name;

		$lang_details = $this->sitepress->get_element_language_details( $term_taxonomy_id, $element_type );
		if ( ! $lang_details ) {
			return;
		}

		$trid = $lang_details->trid;

		if ( empty( $lang_details->source_language_code ) ) {
			if ( ! $this->delete_recursion_flag && $this->sitepress->get_setting( 'sync_delete_tax' ) ) {
				$this->delete_recursion_flag = true;
				$translations                = $this->sitepress->get_element_translations( $trid, $element_type );
				$this->delete_translations( $term_taxonomy_id, $taxonomy_name, $translations );
				$this->delete_recursion_flag = false;
			} else {
				$this->set_new_original_term( $trid, $lang_details->language_code );
			}
		}

		$update_args = array(
			'element_id'   => $term_taxonomy_id,
			'element_type' => $element_type,
			'context'      => 'tax',
		);

		do_action( 'wpml_translation_update', array_merge( $update_args, array( 'type' => 'before_delete' ) ) );
		$this->wpdb->delete(
			$this->wpdb->prefix . 'icl_translations',
			array(
				'element_type' => $element_type,
				'element_id'   => $term_taxonomy_id,
			)
		);
		do_action( 'wpml_translation_update', array_merge( $update_args, array( 'type' => 'after_delete' ) ) );
	}

	public function set_new_original_term( $trid, $deleted_language_code ) {
		if ( $trid && $deleted_language_code ) {
			$order_languages = $this->sitepress->get_setting( 'languages_order' );
			$this->term_translations->reload();
			$translations         = $this->term_translations->get_element_translations( false, $trid );
			$new_source_lang_code = false;
			foreach ( $order_languages as $lang_code ) {
				if ( isset( $translations[ $lang_code ] ) ) {
					$new_source_lang_code = $lang_code;
					break;
				}
			}
			if ( $new_source_lang_code ) {
				$rows_updated = $this->wpdb->update(
					$this->wpdb->prefix . 'icl_translations',
					array( 'source_language_code' => $new_source_lang_code ),
					array(
						'trid'                 => $trid,
						'source_language_code' => $deleted_language_code,
					)
				);

				if ( 0 < $rows_updated ) {
					do_action( 'wpml_translation_update', array( 'trid' => $trid ) );
				}

				$this->wpdb->query(
					"UPDATE {$this->wpdb->prefix}icl_translations
									 SET source_language_code = NULL
									 WHERE language_code = source_language_code"
				);
			}
		}
	}

	public function isTranslatedTermValidForRelationDeletion( $term, $postTranslations ) {
		$valid = isset( $postTranslations[ $term->language_code ] );

		if ( $term->original && $valid ) {
			$valid = ! $postTranslations[ $term->language_code ]->original;
		}

		return $valid;
	}

	public function deleted_term_relationships( $post_id, $delete_terms, $taxonomy ) {
		$post     = get_post( $post_id );
		$postTrid = $this->sitepress->get_element_trid( $post_id, 'post_' . $post->post_type );

		if ( ! $postTrid ) {
			return;
		}

		$isOriginalPost = function ( $translation ) use ( $post_id ) {
			return $translation->original == 1 && $translation->element_id == $post_id;
		};

		$postTranslations = $this->sitepress->get_element_translations( $postTrid, 'post_' . $post->post_type );
		$originalPost     = wpml_collect( $postTranslations )->filter( $isOriginalPost )->first();

		if ( ! $originalPost ) {
			return;
		}

		$getTermsTrids = function ( $termId ) use ( $taxonomy ) {
			return $this->sitepress->get_element_trid( $termId, 'tax_' . $taxonomy );
		};

		$onlyValidTermsTrids = function ( $trid ) {
			return \WPML\FP\Logic::isTruthy( $trid );
		};

		$getDeletedTermsTranslations = function ( $trid ) use ( $taxonomy ) {
			return $this->sitepress->get_element_translations( $trid, 'tax_' . $taxonomy );
		};

		$deleteTermTranslationsRelations = function ( $deletedTermTranslation ) use ( $postTranslations ) {
			if ( $this->isTranslatedTermValidForRelationDeletion( $deletedTermTranslation, $postTranslations ) ) {
				$translated_post = $postTranslations[ $deletedTermTranslation->language_code ];
				$this->wpdb->delete(
					$this->wpdb->term_relationships,
					array(
						'object_id'        => $translated_post->element_id,
						'term_taxonomy_id' => $deletedTermTranslation->element_id,
					)
				);
			}
		};

		$deletedTermsTranslations = wpml_collect( $delete_terms )
			->map( $getTermsTrids )
			->filter( $onlyValidTermsTrids )
			->map( $getDeletedTermsTranslations )
			->flatten()
			->toArray();

		\WPML\FP\Fns::map( \WPML\FP\Fns::tap( $deleteTermTranslationsRelations ), $deletedTermsTranslations );
	}

	public function added_term_relationships( $object_id ) {
		$i                 = $this->wpdb->prefix . 'icl_translations';
		$current_ttids_sql =
			$this->wpdb->prepare(
				"SELECT
									ctt.taxonomy,
									ctt.term_id,
									p.ID
								FROM {$this->wpdb->posts} p
								JOIN {$i} i
								  ON p.ID = i.element_id
								     AND i.element_type = CONCAT('post_', p.post_type)
							    JOIN {$i} ip
									ON ip.trid = i.trid
										AND i.source_language_code = ip.language_code
								JOIN {$i} it
								JOIN {$this->wpdb->term_taxonomy} tt
								    ON tt.term_taxonomy_id = it.element_id
								       AND CONCAT('tax_', tt.taxonomy) = it.element_type
								JOIN {$this->wpdb->term_relationships} objrel
								  ON objrel.object_id = ip.element_id
								    AND objrel.term_taxonomy_id = tt.term_taxonomy_id
								JOIN {$i} itt
								  ON itt.trid = it.trid
								    AND itt.language_code = i.language_code
								JOIN {$this->wpdb->term_taxonomy} ctt
								  ON ctt.term_taxonomy_id = itt.element_id
								LEFT JOIN {$this->wpdb->term_relationships} trans_rel
									ON trans_rel.object_id = p.ID
										AND trans_rel.term_taxonomy_id = ctt.term_taxonomy_id
								WHERE  ip.element_id = %d
									AND trans_rel.object_id IS NULL",
				$object_id
			);

		$corrections = $this->wpdb->get_results( $current_ttids_sql );

		is_array( $corrections ) && $this->apply_added_term_changes( $corrections );
	}

	private function apply_added_term_changes( $corrections ) {
		$changes = array();

		foreach ( $corrections as $correction ) {
			if ( ! $this->sitepress->is_translated_taxonomy( $correction->taxonomy ) ) {
				continue;
			}
			if ( ! isset( $changes[ $correction->taxonomy ] ) ) {
				$changes[ $correction->ID ][ $correction->taxonomy ] = array();
			}
			$changes[ $correction->ID ][ $correction->taxonomy ][] = (int) $correction->term_id;
		}
		foreach ( $changes as $post_id => $tax_changes ) {
			foreach ( $tax_changes as $taxonomy => $term_ids ) {
				remove_action(
					'set_object_terms',
					array( 'WPML_Terms_Translations', 'set_object_terms_action' ),
					10
				);
				$this->sitepress->get_wp_api()->wp_set_object_terms( $post_id, $term_ids, $taxonomy, true );
				add_action(
					'set_object_terms',
					array( 'WPML_Terms_Translations', 'set_object_terms_action' ),
					10,
					6
				);
			}
		}
	}

	private function get_term_lang( $tt_id, $post_action, $taxonomy ) {
		$term_lang = filter_input(
			INPUT_POST,
			'icl_tax_' . $taxonomy . '_language',
			FILTER_SANITIZE_FULL_SPECIAL_CHARS
		);
		$term_lang = $term_lang ? $term_lang : $this->get_term_lang_ajax( $taxonomy, $post_action );
		$term_lang = $term_lang ? $term_lang : $this->get_lang_from_post( $post_action, $tt_id );

		$term_lang = $term_lang ? $term_lang : $this->sitepress->get_current_language();
		$term_lang = apply_filters( 'wpml_create_term_lang', $term_lang );
		$term_lang = $this->sitepress->is_active_language( $term_lang ) ? $term_lang
			: $this->sitepress->get_default_language();

		return $term_lang;
	}

	private function get_lang_from_post( $post_action, $tt_id ) {
		$icl_post_lang = filter_input( INPUT_POST, 'icl_post_language' );
		$term_lang     = $post_action === 'editpost' && $icl_post_lang ? $icl_post_lang : null;
		$term_lang     = $post_action === 'post-quickpress-publish' ? $this->sitepress->get_default_language()
			: $term_lang;
		$term_lang     = ! $term_lang && $post_action === 'inline-save-tax' || $post_action === 'editedtag'
			? $this->term_translations->get_element_lang_code( $tt_id ) : $term_lang;
		$term_lang     = ! $term_lang && $post_action === 'inline-save'
			? $this->post_translations->get_element_lang_code(
				filter_input( INPUT_POST, 'post_ID', FILTER_SANITIZE_NUMBER_INT )
			) : $term_lang;

		return $term_lang;
	}

	public function get_term_lang_ajax( $taxonomy, $post_action ) {
		if ( isset( $_POST['_ajax_nonce'] ) && filter_var( $_POST['_ajax_nonce'] ) !== false
			 && $post_action === 'add-' . $taxonomy
		) {
			$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
			parse_str( (string) wpml_parse_url( $referrer, PHP_URL_QUERY ), $qvars );
			$term_lang = ! empty( $qvars['post'] ) && $this->sitepress->is_translated_post_type(
				get_post_type( (int) $qvars['post'] )
			)
				? $this->post_translations->get_element_lang_code( $qvars['post'] )
				: ( isset( $qvars['lang'] ) ? $qvars['lang'] : null );
		}

		return isset( $term_lang ) ? $term_lang : null;
	}

	private function get_saved_term_trid( $tt_id, $post_action ) {
		if ( $post_action === 'editpost' ) {
			$trid = $this->term_translations->get_element_trid( $tt_id );
		} elseif ( $post_action === 'editedtag' ) {
			$translation_of = filter_input( INPUT_POST, 'icl_translation_of', FILTER_VALIDATE_INT );
			$translation_of = $translation_of ? $translation_of : filter_input( INPUT_POST, 'icl_translation_of' );

			$trid           = $translation_of === 'none' ? false
				: ( $translation_of
					? $this->term_translations->get_element_trid( $translation_of )
					: $trid = filter_input( INPUT_POST, 'icl_trid', FILTER_SANITIZE_NUMBER_INT )
				);
		} else {
			$trid = filter_input( INPUT_POST, 'icl_trid', FILTER_SANITIZE_NUMBER_INT );
			$trid = $trid
				? $trid
				: $this->term_translations->get_element_trid(
					filter_input( INPUT_POST, 'icl_translation_of', FILTER_VALIDATE_INT )
				);
			$trid = $trid ? $trid : $this->term_translations->get_element_trid( $tt_id );
		}

		return $trid;
	}

	private function delete_translations( $term_taxonomy_id, $taxonomy, array $translations ) {
		$has_filter = remove_filter( 'get_term', array( $this->sitepress, 'get_term_adjust_id' ), 1 );
		foreach ( $translations as $translation ) {
			if ( (int) $translation->element_id !== (int) $term_taxonomy_id ) {
				wp_delete_term( $translation->term_id, $taxonomy );
			}
		}
		if ( $has_filter ) {
			add_filter( 'get_term', array( $this->sitepress, 'get_term_adjust_id' ), 1, 1 );
		}
	}
}
