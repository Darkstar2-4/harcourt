<?php

use WPML\API\Sanitize;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;

class WPML_Post_Edit_Ajax {
	const AJAX_ACTION_SWITCH_POST_LANGUAGE = 'wpml_switch_post_language';

	public static $post_custom_field_settings;

	public static function wpml_save_term_action() {
		global $sitepress;

		if ( ! wpml_is_action_authenticated( 'wpml_save_term' ) ) {
			wp_send_json_error( 'Wrong Nonce' );
		}

		$lang        = Sanitize::stringProp( 'term_language_code', $_POST );
		$taxonomy    = Sanitize::stringProp( 'taxonomy', $_POST );
		$slug        = Sanitize::stringProp( 'slug', $_POST );
		$name        = Sanitize::stringProp( 'name', $_POST );

		$trid        = filter_var( $_POST['trid'], FILTER_SANITIZE_NUMBER_INT );
		$description = wp_kses_post( $_POST['description'] );
		$meta_data   = isset( $_POST['meta_data'] ) ? $_POST['meta_data'] : array();

		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		remove_filter( 'term_description', 'wp_kses_data' );

		$new_term_object = self::save_term_ajax( $sitepress, $lang, $taxonomy, $slug, $name, $trid, $description, $meta_data );
		$sitepress->get_wp_api()->wp_send_json_success( $new_term_object );
	}

	public static function save_term_ajax( $sitepress, $lang, $taxonomy, $slug, $name, $trid, $description, $meta_data ) {
		$new_term_object = false;

		if ( $name !== "" && $taxonomy && $trid && $lang ) {

			$args = array(
				'taxonomy'  => $taxonomy,
				'lang_code' => $lang,
				'term'      => $name,
				'trid'      => $trid,
				'overwrite' => true
			);

			if ( $slug ) {
				$args[ 'slug' ] = $slug;
			}
			if ( $description ) {
				$args[ 'description' ] = $description;
			}

			$switch_lang = new WPML_Temporary_Switch_Language( $sitepress, $lang );
			$is_new_term = ! term_exists( $name, $taxonomy );
			$res = WPML_Terms_Translations::create_new_term( $args );
			$switch_lang->restore_lang();

			if ( $res && isset( $res[ 'term_taxonomy_id' ] ) ) {
				$switch_lang = new WPML_Temporary_Switch_Language( $sitepress, $lang );
				$new_term_object = get_term_by( 'term_taxonomy_id', (int) $res['term_taxonomy_id'], $taxonomy );
				$switch_lang->restore_lang();

				$lang_details                   = $sitepress->get_element_language_details( $new_term_object->term_taxonomy_id, 'tax_' . $new_term_object->taxonomy );
				$new_term_object->trid          = $lang_details->trid;
				$new_term_object->language_code = $lang_details->language_code;
				if ( self::add_term_metadata( $res, $meta_data, $is_new_term ) ) {
					$new_term_object->meta_data = get_term_meta( $res['term_id'] );
				}

				WPML_Terms_Translations::icl_save_term_translation_action( $taxonomy, $res );

				self::capture_taxonomy_term_translation_event( $sitepress, $taxonomy, $lang, $trid, $res, $name, $slug, $description );

				$term_hierarchy_sync = wpml_get_hierarchy_sync_helper( 'term' );
				if ( is_taxonomy_hierarchical( $taxonomy ) && $term_hierarchy_sync->is_need_sync( $taxonomy, false, $res['term_id'] ) ) {
					$term_hierarchy_sync->sync_element_hierarchy( $taxonomy, false, $res['term_id'] );
				}
			}
		}

		return $new_term_object;
	}

	public static function copy_from_original_fields( $content_type, $excerpt_type, $trid, $lang ) {
		global $wpdb;
		$post_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND language_code=%s",
			                $trid,
			                $lang ) );
		$post    = get_post( $post_id );

		$fields_to_copy = array( 'content' => 'post_content',
								 'title'   => 'post_title',
								 'excerpt' => 'post_excerpt' );

		$fields_contents = array();
		if ( ! empty( $post ) ) {
			foreach ( $fields_to_copy as $editor_key => $editor_field ) {
				if ( $editor_key === 'content' || $editor_key === 'excerpt' ) {
					$editor_var = 'rich';
					if ( $editor_key === 'content' ) {
						$editor_var = $content_type;
					} elseif ( $editor_key === 'excerpt' ) {
						$editor_var = $excerpt_type;
					}

					if ( function_exists( 'format_for_editor' ) ) {
						$html_pre = $post->$editor_field;
						if($editor_var == 'rich') {
							$html_pre = convert_chars( $html_pre );
							$html_pre = wpautop( $html_pre );
						}
						$html_pre = format_for_editor( $html_pre, $editor_var );
					} else {
						if ( $editor_var === 'rich' ) {
							$html_pre = wp_richedit_pre( $post->$editor_field );
						} else {
							$html_pre = wp_htmledit_pre( $post->$editor_field );
						}
					}

					$fields_contents[$editor_key] = htmlspecialchars_decode( $html_pre );
				} elseif ( $editor_key === 'title' ) {
					$fields_contents[ $editor_key ] = strip_tags( $post->$editor_field );
				}
			}
			$fields_contents[ 'builtin_custom_fields' ] = apply_filters( 'wpml_copy_from_original_custom_fields',
			                                                    self::copy_from_original_custom_fields( $post ) );

			$fields_contents['external_custom_fields'] = self::copy_meta_values_from_original( $post );
		} else {
			$fields_contents[ 'error' ] = __( 'Post not found', 'sitepress' );
		}
		do_action( 'icl_copy_from_original', $post_id );

		return $fields_contents;
	}

	public static function copy_from_original_custom_fields( $post ) {

		$elements                 = array();
		$elements [ 'post_type' ] = $post->post_type;
		$elements[ 'excerpt' ]    = array(
			'editor_name' => 'excerpt',
			'editor_type' => 'text',
			'value'       => $post->post_excerpt
		);

		return $elements;
	}

	private static function copy_meta_values_from_original ($post) {
		global $wpdb;

		if ( ! self::$post_custom_field_settings instanceof WPML_Custom_Field_Setting_Factory ) {
			$translation_management = wpml_load_core_tm();
			self::$post_custom_field_settings = new WPML_Custom_Field_Setting_Factory( $translation_management );
		}

		$post_custom_fields = self::$post_custom_field_settings->get_post_meta_keys();

		if ( ! is_array( $post_custom_fields ) || empty( $post_custom_fields ) ) {
			return array();
		}

		$sql = "SELECT meta_key as name, meta_value as value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ("
		       . wpml_prepare_in( $post_custom_fields ) . ')';

		return $wpdb->get_results( $wpdb->prepare( $sql, $post->ID ), ARRAY_A );
	}

	public static function wpml_switch_post_language() {
		global $sitepress, $wpdb;

		$nonce = $_POST['nonce'];
		if ( ! wp_verify_nonce( $nonce, self::AJAX_ACTION_SWITCH_POST_LANGUAGE ) ) {
			wp_send_json_error();
		}

		$to      = false;
		$post_id = false;

		if ( isset( $_POST[ 'wpml_to' ] ) ) {
			$to = $_POST[ 'wpml_to' ];
		}
		if ( isset( $_POST[ 'wpml_post_id' ] ) ) {
			$post_id = $_POST[ 'wpml_post_id' ];
		}

		$result = false;

		if ( $post_id && $to ) {

			if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
				wp_send_json_error( __( 'You are not allowed to edit this post.', 'sitepress' ), 403 );
			}

			$post_type      = get_post_type( $post_id );
			$wpml_post_type = 'post_' . $post_type;
			$trid           = $sitepress->get_element_trid( $post_id, $wpml_post_type );


			$query_for_existing_translation = $wpdb->prepare( "	SELECT translation_id, element_id
																FROM {$wpdb->prefix}icl_translations
																WHERE element_type = %s
																	AND trid = %d
																	AND language_code = %s",
			                                                  $wpml_post_type, $trid, $to );
			$existing_translation           = $wpdb->get_row( $query_for_existing_translation );

			if ( $existing_translation && $existing_translation->element_id != $post_id ) {
				$result = false;
			} else {
				$sitepress->set_element_language_details( $post_id, $wpml_post_type, $trid, $to );
				WPML_Terms_Translations::sync_post_terms_language( $post_id );
				require_once WPML_PLUGIN_PATH . '/inc/cache.php';
				icl_cache_clear( $post_type . 's_per_language', true );

				$result = $to;
			}

			\WPML\LIB\WP\Cache::clearMemoizedFunction( 'get_source_language_by_trid', (int) $trid );
		}

		wp_send_json_success( $result );
	}

	public static function wpml_get_default_lang() {
		global $sitepress;
		$nonce = isset( $_POST['_icl_nonce'] ) ? sanitize_text_field( $_POST['_icl_nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, 'wpml_get_default_lang' ) ) {
			wp_send_json_error( esc_html__( 'Invalid request!', 'sitepress' ), 400 );
		}

		wp_send_json_success( $sitepress->get_default_language() );
	}

	private static function add_term_metadata( $term, $meta_data, $is_new_term ) {
		global $sitepress;

		foreach ( $meta_data as $meta_key => $meta_value ) {
			delete_term_meta( $term['term_id'], $meta_key );
			$data = self::safe_maybe_unserialize( stripslashes( $meta_value ) );
			if ( ! add_term_meta( $term['term_id'], $meta_key, $data ) ) {
				throw new RuntimeException( sprintf( 'Unable to add term meta form term: %d', $term['term_id'] ) );
			}
		}

		$sync_meta_action = new WPML_Sync_Term_Meta_Action( $sitepress, $term[ 'term_taxonomy_id' ], $is_new_term );
		$sync_meta_action->run();

		return true;
	}

	private static function safe_maybe_unserialize( $data ) {
		if ( is_serialized( $data ) ) {
			return @unserialize( trim( $data ), [ 'allowed_classes' => false ] );
		}

		return $data;
	}

	private static function capture_taxonomy_term_translation_event( $sitepress, $taxonomy, $lang, $trid, $res, $name, $slug, $description ) {

		if ( ! \WPML\PostHog\State\PostHogState::isEnabled() ) {
			return;
		}

		$source_language = $sitepress->get_source_language_by_trid( $trid );

		$original_term_tax_id = (int) $sitepress->get_original_element_id_by_trid( $trid );
		$original_term = $original_term_tax_id ?
			get_term_by( 'term_taxonomy_id', $original_term_tax_id, $taxonomy, OBJECT, 'no' ) :
			false;

		$event_props = array(
			'taxonomy'             => $taxonomy,
			'target_language'      => $lang,
			'source_language'      => $source_language,
			'term_id'              => $res['term_id'],
			'term_taxonomy_id'     => $res['term_taxonomy_id'],
			'is_hierarchical'      => is_taxonomy_hierarchical( $taxonomy ),
			'original_term_name'   => $original_term ? $original_term->name : '',
			'original_term_slug'   => $original_term ? $original_term->slug : '',
			'original_term_desc'   => $original_term ? $original_term->description : '',
			'translated_term_name' => $name,
			'translated_term_slug' => $slug,
			'translated_term_desc' => $description,
		);

		\WPML\PostHog\Event\CaptureEvent::capture(
			( new EventInstanceService() )->getTaxonomyTermTranslationSavedEvent( $event_props )
		);
	}

}
