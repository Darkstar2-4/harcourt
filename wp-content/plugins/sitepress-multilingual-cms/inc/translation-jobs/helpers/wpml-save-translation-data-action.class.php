<?php

use WPML\Legacy\Translation\Save\SyncParentPost\SyncParentPost;
use WPML\Translation\TranslationElements\FieldCompression;
use WPML\Hooks\WpmlSavePostHooks;
use WPML\TM\Jobs\JobLog;

class WPML_Save_Translation_Data_Action extends WPML_Translation_Job_Helper_With_API {

	const SKIP_SYNC_POST_DATE_FOR_TYPES = [
		'wp_navigation',
		'wp_template_part'
	];

	private $sitepress;

	private $tm_records;

	private $data;

	private $redirect_target = false;
	private $translate_link_targets_in_posts;
	private $translate_link_targets_in_strings;

	private $sync_parent_post;

	public function __construct( $data, $tm_records ) {
		global $wpdb, $ICL_Pro_Translation, $sitepress, $wpml_post_translations;
		parent::__construct();
		$this->sitepress                         = $sitepress;
		$this->data                              = $data;
		$this->tm_records                        = $tm_records;
		$translate_link_targets_global_state     = new WPML_Translate_Link_Target_Global_State( $sitepress );
		$this->translate_link_targets_in_posts   = new WPML_Translate_Link_Targets_In_Posts( $translate_link_targets_global_state, $wpdb, $ICL_Pro_Translation );
		$this->translate_link_targets_in_strings = new WPML_Translate_Link_Targets_In_Strings( $translate_link_targets_global_state, $wpdb, new WPML_WP_API(), $ICL_Pro_Translation );
		$this->sync_parent_post                  = new SyncParentPost( $wpdb, $sitepress, $wpml_post_translations );
	}

	function save_translation() {
		global $wpdb, $sitepress, $iclTranslationManagement, $wpml_post_translations;

		$new_post_id   = false;
		$is_incomplete = false;
		$data          = $this->data;
		$job                 = ! empty( $data['job_id'] ) ? $this->get_translation_job( $data['job_id'], true ) : null;
		$needs_second_update = $job && $job->needs_update ? 1 : 0;
		$original_post       = null;
		$element_type_prefix = null;
		if ( is_object( $job ) ) {
			$element_type_prefix = $iclTranslationManagement->get_element_type_prefix_from_job( $job );
			$original_post       = $iclTranslationManagement->get_post( $job->original_doc_id, $element_type_prefix );
		}

		JobLog::add( 'save_translation_start', [
			'job_id'              => $data['job_id'] ?? null,
			'original_doc_id'     => is_object( $job ) ? ( $job->original_doc_id ?? null ) : null,
			'language_code'       => is_object( $job ) ? ( $job->language_code ?? null ) : null,
			'source_language_code'=> is_object( $job ) ? ( $job->source_language_code ?? null ) : null,
			'element_type_prefix' => $element_type_prefix,
			'has_complete_flag'   => ! empty( $data['complete'] ),
		] );

		$is_external      = apply_filters( 'wpml_is_external', false, $element_type_prefix );
		$data_to_validate = array(
			'original_post' => $original_post,
			'type_prefix'   => $element_type_prefix,
			'data'          => $data,
			'is_external'   => $is_external,
		);

		$validation_results = $this->get_validation_results( $job, $data_to_validate );

		if ( ! $validation_results['is_valid'] ) {
			JobLog::addError( 'save_translation_validation_failed', [
				'job_id'   => $data['job_id'] ?? null,
				'messages' => $validation_results['messages'] ?? [],
			] );
			$this->handle_failed_validation( $validation_results, $data_to_validate );
			$res = false;
		} else {
			foreach ( $data['fields'] as $fieldname => $field ) {
				if ( substr( $fieldname, 0, 6 ) === 'field-' ) {
					$field = apply_filters( 'wpml_tm_save_translation_cf', $field, $fieldname, $data );
				}
				$this->save_translation_field( $field['tid'], $field, $data['job_id'] );
				if ( ! isset( $field['finished'] ) || ! $field['finished'] ) {
					$is_incomplete = true;
				}
			}

			$icl_translate_job  = $this->tm_records->icl_translate_job_by_job_id( $data['job_id'] );
			$rid                = $icl_translate_job->rid();
			$translation_status = $this->tm_records->icl_translation_status_by_rid( $rid );
			$translation_id     = $translation_status->translation_id();

			JobLog::add( 'save_translation_resolved', [
				'job_id'         => $data['job_id'] ?? null,
				'rid'            => $rid,
				'translation_id' => $translation_id,
				'element_id'     => $translation_status->element_id(),
				'target_lang'    => is_object( $job ) ? ( $job->language_code ?? null ) : null,
			] );
			if ( ( $is_incomplete === true || empty( $data['complete'] ) ) && empty( $data['resign'] ) ) {
				$iclTranslationManagement->update_translation_status(
					array(
						'translation_id' => $translation_id,
						'status'         => ICL_TM_IN_PROGRESS,
					)
				);
				$icl_translate_job->update( array( 'translated' => 0 ) );

				self::notify_job_in_progress( $element_type_prefix, $job );
			}

			$element_id = $translation_status->element_id();
			delete_post_meta( $element_id, '_icl_lang_duplicate_of' );

			if ( ! empty( $data['complete'] ) && ! $is_incomplete ) {
				$icl_translate_job->complete();

				$job = $this->get_translation_job( $data['job_id'], true );

				if ( $is_external ) {
					self::save_external( $element_type_prefix, $job, [ $this, 'decode_field_data' ] );
				} else {
					if ( $element_id ) {
						$postarr['ID'] = $_POST['post_ID'] = $element_id;
					} else {
						$postarr['post_status'] = ! $sitepress->get_setting( 'translated_document_status' ) ? 'draft' : $original_post->post_status;
					}

					foreach ( $job->elements as $field ) {
						switch ( $field->field_type ) {
							case 'title':
								$postarr['post_title'] = $this->decode_field_data( $field->field_data_translated, $field->field_format );
								break;
							case 'body':
								$postarr['post_content'] = $this->decode_field_data(
									$field->field_data_translated,
									$field->field_format
								);
								break;
							case 'excerpt':
								$postarr['post_excerpt'] = $this->decode_field_data( $field->field_data_translated, $field->field_format );
								break;
							case 'URL':
								$postarr['post_name'] = $this->decode_field_data( $field->field_data_translated, $field->field_format );
								break;
							default:
								break;
						}
					}

					$postarr['post_author'] = $original_post->post_author;
					$postarr['post_type']   = $original_post->post_type;

					if ( $sitepress->get_setting( 'sync_comment_status' ) ) {
						$postarr['comment_status'] = $original_post->comment_status;
					}
					if ( $sitepress->get_setting( 'sync_ping_status' ) ) {
						$postarr['ping_status'] = $original_post->ping_status;
					}
					if ( $sitepress->get_setting( 'sync_page_ordering' ) ) {
						$postarr['menu_order'] = $original_post->menu_order;
					}
					if ( $sitepress->get_setting( 'sync_private_flag' ) && $original_post->post_status == 'private' ) {
						$postarr['post_status'] = 'private';
					}
					if ( $sitepress->get_setting( 'sync_password' ) && $original_post->post_password ) {
						$postarr['post_password'] = $original_post->post_password;
					}

					$shouldSkipPostDateSync = in_array( $original_post->post_type, self::SKIP_SYNC_POST_DATE_FOR_TYPES );
					if ( ! $shouldSkipPostDateSync && $sitepress->get_setting( 'sync_post_date' ) ) {
						$postarr['post_date'] = $original_post->post_date;
					}

					$postarr = $this->sync_parent_post->linkParentTranslatedPostOrFlagOriginal( $original_post->post_parent, $job->language_code, $postarr );

					$_POST['trid']                   = $translation_status->trid();
					$_POST['lang']                   = $job->language_code;
					$_POST['skip_sitepress_actions'] = true;
					$_POST['needs_second_update']    = $needs_second_update;

					$postarr = apply_filters( 'icl_pre_save_pro_translation', $postarr );

					$postarr = apply_filters( 'wpml_pre_save_pro_translation', $postarr, $job );

					if ( $element_id ) {
						$translated_document_page_url = $sitepress->get_setting( 'translated_document_page_url' );
						switch ( $translated_document_page_url ) {
							case 'force-generate':
								$postarr['post_name'] = '';
								break;
						}

						$existing_post            = get_post( $element_id );
						$postarr['post_date']     = $existing_post->post_date;
						$postarr['post_date_gmt'] = $existing_post->post_date_gmt;
					}

					$new_post_id = wpml_get_create_post_helper()->insert_post( $postarr, $job->language_code );
					$this->sync_parent_post->linkUnlinkedChildPosts( $original_post->ID, $job->language_code, $new_post_id );

					JobLog::add( 'save_translation_post_inserted', [
						'job_id'          => $data['job_id'] ?? null,
						'rid'             => $rid,
						'new_post_id'     => $new_post_id,
						'pre_existing_element_id' => $element_id,
					] );

					$link = get_edit_post_link( $new_post_id );
					if ( '' === $link ) {
						$link = get_permalink( $new_post_id );
					}

					if ( ! $element_id ) {
						$deletedRows = $wpdb->delete(
							$wpdb->prefix . 'icl_translations',
							array(
								'element_id'   => $new_post_id,
								'element_type' => 'post_' . $postarr['post_type'],
							)
						);
						$linkAffected = $wpdb->update( $wpdb->prefix . 'icl_translations', array( 'element_id' => $new_post_id ), array( 'translation_id' => $translation_id ) );

						$linkEvent = ( $linkAffected === 0 || $linkAffected === false )
							? 'save_translation_element_id_link_failed'
							: 'save_translation_element_id_linked';
						$linkData  = [
							'job_id'              => $data['job_id'] ?? null,
							'rid'                 => $rid,
							'translation_id'      => $translation_id,
							'new_post_id'         => $new_post_id,
							'icl_translations_update_affected_rows' => is_int( $linkAffected ) ? $linkAffected : null,
							'icl_translations_delete_affected_rows' => is_int( $deletedRows ) ? $deletedRows : null,
						];
						if ( $linkAffected === 0 || $linkAffected === false ) {
							JobLog::addError( $linkEvent, $linkData );
						} else {
							JobLog::add( $linkEvent, $linkData );
						}

						$user_message = __( 'Translation added: ', 'wpml-translation-management' ) . '<a href="' . $link . '">' . $postarr['post_title'] . '</a>.';
					} else {
						$user_message = __( 'Translation updated: ', 'wpml-translation-management' ) . '<a href="' . $link . '">' . $postarr['post_title'] . '</a>.';
					}

					icl_cache_clear( $postarr['post_type'] . 's_per_language' );
					do_action( 'wpml_pro_translation_after_post_save', $new_post_id );

					if ( ! current_user_can( 'manage-categories' ) && ! empty( $postarr['tax_input'] ) ) {
						foreach ( $postarr['tax_input'] as $taxonomy => $terms ) {
							wp_set_post_terms( $new_post_id, $terms, $taxonomy, false );
						}
					}

					$data['fields'] = apply_filters( 'wpml_tm_job_fields', $data['fields'], $job );

					do_action( 'icl_pro_translation_saved', $new_post_id, $data['fields'], $job );
					do_action( 'wpml_translation_job_saved', $new_post_id, $data['fields'], $job );

					$new_post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID=%d", $new_post_id ) );
					foreach ( $job->elements as $job_element ) {
						if ( $job_element->field_type === 'body' ) {
							$fields_data_translated = apply_filters( 'wpml_tm_job_data_post_content', $new_post_content );
							$fields_data_translated = FieldCompression::compressAndTrack( $fields_data_translated, false, $data['job_id'] );
							$wpdb->update(
								$wpdb->prefix . 'icl_translate',
								array( 'field_data_translated' => $fields_data_translated ),
								array(
									'job_id'     => $data['job_id'],
									'field_type' => 'body',
								)
							);

							break;
						}
					}

					$sitepress->copy_custom_fields( $original_post->ID, $new_post_id );

					$copied_custom_fields = array( '_top_nav_excluded', '_cms_nav_minihome' );
					foreach ( $copied_custom_fields as $ccf ) {
						$val = get_post_meta( $original_post->ID, $ccf, true );
						update_post_meta( $new_post_id, $ccf, $val );
					}

					if ( $sitepress->get_setting( 'sync_page_template' ) ) {
						$_wp_page_template = get_post_meta( $original_post->ID, '_wp_page_template', true );
						if ( ! empty( $_wp_page_template ) ) {
							update_post_meta( $new_post_id, '_wp_page_template', $_wp_page_template );
						}
					}

					$this->package_helper->save_job_custom_fields(
						$job,
						$new_post_id,
						\WPML\TM\Settings\Repository::getCustomFields()
					);

					$sticky_posts       = get_option( 'sticky_posts' );
					$sticky_posts       = is_array( $sticky_posts ) ? $sticky_posts : [];
					$is_original_sticky = $original_post->post_type == 'post' && in_array( $original_post->ID, $sticky_posts );

					if ( $is_original_sticky && $sitepress->get_setting( 'sync_sticky_flag' ) ) {
						stick_post( $new_post_id );
					} else {
						if ( $original_post->post_type == 'post' && ! is_null( $element_id ) ) {
							unstick_post( $new_post_id );
						}
					}

					$this->add_message(
						array(
							'type' => 'updated',
							'text' => $user_message,
						)
					);
					$this->clear_page_name_cache( $new_post_id );
					WPML_Pre_Option_Page::maybe_clear_privacy_policy_cache( $original_post->ID, $postarr['post_type'] );
				}

				if ( $this->get_tm_setting( array( 'notification', 'completed' ) ) != ICL_TM_NOTIFICATION_NONE
					 && $data['job_id']
				) {
					do_action( 'wpml_tm_complete_job_notification', $data['job_id'], ! is_null( $element_id ) );
				}

				$iclTranslationManagement->set_page_url( $new_post_id );

				if ( isset( $job ) && isset( $job->language_code ) && isset( $job->source_language_code ) ) {
					$this->save_terms_for_job( $data['job_id'] );
				}

				if ( $sitepress->get_setting( 'sync_post_format' ) ) {
					$_wp_post_format = get_post_format( $original_post->ID );
					$_wp_post_format && set_post_format( $new_post_id, $_wp_post_format );
				}

				do_action( 'icl_pro_translation_completed', $new_post_id, $data['fields'], $job );
				do_action( 'wpml_pro_translation_completed', $new_post_id, $data['fields'], $job );
				$this->sitepress->executeSavePostHookOnPostTranslationSave( $new_post_id );

				$expectedStatus = apply_filters( 'wpml_tm_applied_job_status', ICL_TM_COMPLETE, $job, $new_post_id );
				$translation_status->update( [
					'status'       => $expectedStatus,
					'needs_update' => $needs_second_update,
				] );

				if ( JobLog::canLog() ) {
					$actualStatus = $wpdb->get_var( $wpdb->prepare(
						"SELECT status FROM {$wpdb->prefix}icl_translation_status WHERE rid = %d LIMIT 1",
						$rid
					) );

					if ( $actualStatus === null ) {
						JobLog::addError( 'save_translation_status_rid_missing_after_update', [
							'job_id'          => $data['job_id'] ?? null,
							'rid'             => $rid,
							'translation_id'  => $translation_id,
							'expected_status' => (int) $expectedStatus,
							'new_post_id'     => $new_post_id,
						] );
					} elseif ( (int) $actualStatus !== (int) $expectedStatus ) {
						JobLog::addError( 'save_translation_status_unexpected_after_update', [
							'job_id'          => $data['job_id'] ?? null,
							'rid'             => $rid,
							'expected_status' => (int) $expectedStatus,
							'actual_status'   => (int) $actualStatus,
							'new_post_id'     => $new_post_id,
						] );
					} else {
						JobLog::add( 'save_translation_status_finalised', [
							'job_id'       => $data['job_id'] ?? null,
							'rid'          => $rid,
							'status'       => (int) $actualStatus,
							'new_post_id'  => $new_post_id,
							'needs_update' => $needs_second_update,
						] );
					}
				}

				if ( ! defined( 'REST_REQUEST' ) && ! defined( 'XMLRPC_REQUEST' ) && ! defined( 'DOING_AJAX' ) && ! isset( $_POST['xliff_upload'] ) ) {
					$action_type           = is_null( $element_id ) ? 'added' : 'updated';
					$element_id            = is_null( $element_id ) ? $new_post_id : $element_id;
					$this->redirect_target = admin_url( sprintf( 'admin.php?page=%s&%s=%d&element_type=%s', WPML_TM_FOLDER . '/menu/translations-queue.php', $action_type, $element_id, $element_type_prefix ) );
				}
			} else {
				$this->add_message(
					array(
						'type' => 'updated',
						'text' => __( 'Translation (incomplete) saved.', 'wpml-translation-management' ),
					)
				);
			}

			$res = true;
		}

		return $res;
	}

	private function clear_page_name_cache( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}

		$base_key = 'get_single_slug_adjusted_IDs' . $post->post_type . $post->post_name;
		wp_cache_delete( $base_key . $post->post_parent, 'WPML_Page_Name_Query_Filter' );
		wp_cache_delete( $base_key, 'WPML_Page_Name_Query_Filter' );
	}

	function get_redirect_target() {

		return $this->redirect_target;
	}

	private function save_translation_field( $tid, $field, $job_id = null ) {
		global $wpdb;

		$update = [];
		if ( isset( $field['data'] ) ) {
			$update['field_data_translated'] = FieldCompression::compressAndTrack( $field['data'], false, $job_id );
		}
		$update['field_finished'] = isset( $field['finished'] ) && $field['finished'] ? 1 : 0;

		$wpdb->update( $wpdb->prefix . 'icl_translate', $update, array( 'tid' => $tid ) );
	}

	private function handle_failed_validation( $validation_results, $data_to_validate ) {
		if ( isset( $validation_results['messages'] ) ) {
			$messages = (array) $validation_results['messages'];
			if ( $messages ) {
				foreach ( $messages as $message ) {
					$this->add_message(
						array(
							'type' => 'error',
							'text' => $message,
						)
					);
				}
			} else {
				$this->add_message(
					array(
						'type' => 'error',
						'text' => __( 'Submitted data is not valid.', 'wpml-translation-management' ),
					)
				);
			}
		}
		do_action( 'wpml_translation_validation_failed', $validation_results, $data_to_validate );
	}

	private function get_validation_results( $job, $data_to_validate ) {
		$is_valid                   = true;
		$original_post              = $data_to_validate['original_post'];
		$element_type_prefix        = $data_to_validate['type_prefix'];
		$validation_default_results = array(
			'is_valid' => $is_valid,
			'messages' => array(),
		);
		if ( ! $job || ! $original_post || ! $element_type_prefix ) {
			$is_valid = false;
			if ( ! $job ) {
				$validation_default_results['messages'][] = __( 'Job ID is missing', 'wpml-translation-management' );
			}
			if ( ! $original_post ) {
				$validation_default_results['messages'][] = __( 'The original post cannot be retrieved', 'wpml-translation-management' );
			}
			if ( ! $element_type_prefix ) {
				$validation_default_results['messages'][] = __( 'The type of the post cannot be retrieved', 'wpml-translation-management' );
			}
		} elseif ( ! $this->tm_records->icl_translate_job_by_job_id( $job->job_id )->is_open() ) {
			$is_valid                                 = false;
			$validation_default_results['messages'][] = __( 'This job cannot be edited anymore because a newer job for this element exists.', 'wpml-translation-management' );
		}
		$validation_default_results['is_valid'] = $is_valid;
		$validation_results                     = apply_filters( 'wpml_translation_validation_data', $validation_default_results, $data_to_validate );
		$validation_results                     = array_merge( $validation_default_results, $validation_results );

		if ( ! $is_valid && $validation_results['is_valid'] ) {
			$validation_results['is_valid'] = $is_valid;
		}

		return $validation_results;
	}

	private function save_terms_for_job( $job_id ) {
		require_once WPML_TM_PATH . '/inc/translation-jobs/wpml-translation-jobs-collection.class.php';

		$job = new WPML_Post_Translation_Job( $job_id );
		$job->save_terms_to_post();
	}

	private function add_message( $message ) {
		global $iclTranslationManagement;

		$iclTranslationManagement->add_message( $message );
	}

	private static function save_external( $element_type_prefix, $job, $decoder ) {
		do_action( 'wpml_save_external', $element_type_prefix, $job, $decoder );
	}

	private static function notify_job_in_progress( $element_type_prefix, $job ) {
		do_action( 'wpml_tm_job_in_progress', $element_type_prefix, $job );
	}
}
