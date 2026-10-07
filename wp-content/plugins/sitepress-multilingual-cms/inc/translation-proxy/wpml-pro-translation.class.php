<?php

use WPML\FP\Fns;
use WPML\FP\Lst;
use WPML\FP\Str;
use function WPML\Container\make;
use function WPML\FP\pipe;

class WPML_Pro_Translation extends WPML_TM_Job_Factory_User {

	private static $translated_links = [];

	public $errors = array();
	private $tmg;

	private $cms_id_helper;

	private $xliff_reader_factory;


	private $sitepress;

	private $update_pm;

	private $is_language_switched = false;

	function __construct( &$job_factory ) {
		parent::__construct( $job_factory );
		global $iclTranslationManagement, $wpdb, $sitepress, $wpml_post_translations, $wpml_term_translations;

		$this->tmg                  =& $iclTranslationManagement;
		$this->xliff_reader_factory = new WPML_TM_Xliff_Reader_Factory( $this->job_factory );
		$wpml_tm_records            = new WPML_TM_Records( $wpdb, $wpml_post_translations, $wpml_term_translations );
		$this->cms_id_helper        = new WPML_TM_CMS_ID( $wpml_tm_records, $job_factory );
		$this->sitepress            = $sitepress;

		add_filter( 'xmlrpc_methods', array( $this, 'custom_xmlrpc_methods' ) );
		add_action(
			'post_submitbox_start',
			array(
				$this,
				'post_submitbox_start',
			)
		);
		add_action(
			'icl_ajx_custom_call',
			array(
				$this,
				'ajax_calls',
			),
			10,
			2
		);

		add_action( 'wpml_minor_edit_for_gutenberg', array( $this, 'gutenberg_minor_edit' ), 10, 0 );

		$this->update_pm = new WPML_Update_PickUp_Method( $this->sitepress );
	}

	public function &get_cms_id_helper() {
		return $this->cms_id_helper;
	}

	function ajax_calls( $call, $data ) {
		switch ( $call ) {
			case 'set_pickup_mode':
				$response = $this->update_pm->update_pickup_method( $data, $this->get_current_project() );
				if ( 'no-ts' === $response ) {
					wp_send_json_error( array( 'message' => __( 'Please activate translation service first.', 'wpml-translation-management' ) ) );
				}
				if ( 'cant-update' === $response ) {
					wp_send_json_error( array( 'message' => __( 'Could not update the translation pickup mode.', 'wpml-translation-management' ) ) );
				}

				wp_send_json_success( array( 'message' => __( 'Ok', 'wpml-translation-management' ) ) );
				break;
		}
	}

	public function get_current_project() {
		return TranslationProxy::get_current_project();
	}

	function send_post( $postOrPackage, $target_languages, $translator_id, $job_id, $tp_batch_info = null ) {
		global $sitepress, $iclTranslationManagement;

		$this->maybe_init_translation_management( $iclTranslationManagement );

		if ( is_numeric( $postOrPackage ) ) {
			$postOrPackage = get_post( $postOrPackage );
		}
		if ( ! $postOrPackage ) {
			return false;
		}

		$element_id          = $postOrPackage->ID;
		$element_type           = $postOrPackage->post_type;
		$element_type_prefix = $iclTranslationManagement->get_element_type_prefix_from_job_id( $job_id );
		$element_type        = $element_type_prefix . '_' . $element_type;

		if( $postOrPackage instanceof WPML_Package ) {
			$note = $postOrPackage->translator_note;
		} else {
			$note = WPML_TM_Translator_Note::get( $element_id );
		}

		if ( ! $note ) {
			$note = null;
		}
		$err             = false;
		$tp_job_id       = false;
		$source_language = $sitepress->get_language_for_element( $element_id, $element_type );
		$target_language = is_array( $target_languages ) ? end( $target_languages ) : $target_languages;
		if ( empty( $target_language ) || $target_language === $source_language ) {
			return false;
		}
		$translation = $this->tmg->get_element_translation( $element_id, $target_language, $element_type );
		if ( ! $translation ) {
			$err = true;
		}
		if ( ! $err && ( $translation->needs_update || $translation->status == ICL_TM_NOT_TRANSLATED || $translation->status == ICL_TM_WAITING_FOR_TRANSLATOR ) ) {
			$project = TranslationProxy::get_current_project();

			if ( $iclTranslationManagement->is_external_type( $element_type_prefix ) ) {
				$job_object = new WPML_External_Translation_Job( $job_id );
			} else {
				$job_object = new WPML_Post_Translation_Job( $job_id );
				$job_object->load_terms_from_post_into_job();
			}

			list( $err, $project, $tp_job_id ) = $job_object->send_to_tp( $project, $translator_id, $this->cms_id_helper, $this->tmg, $note, $tp_batch_info );
			if ( $err ) {
				$this->enqueue_project_errors( $project );
			}
		}

		return $err ? false : $tp_job_id;
	}

	function server_languages_map( $language_name, $server2plugin = false ) {
		if ( is_array( $language_name ) ) {
			return array_map( array( $this, 'server_languages_map' ), $language_name );
		}
		$map = array(
			'Norwegian Bokmål'     => 'Norwegian',
			'Portuguese, Brazil'   => 'Portuguese',
			'Portuguese, Portugal' => 'Portugal Portuguese',
		);

		$map = $server2plugin ? array_flip( $map ) : $map;

		return isset( $map[ $language_name ] ) ? $map[ $language_name ] : $language_name;
	}

	public function custom_xmlrpc_methods( $methods ) {
		$icl_methods['translationproxy.test_xmlrpc']        = '__return_true';
		$icl_methods['translationproxy.updated_job_status'] = array(
			$this,
			'xmlrpc_updated_job_status',
		);

		$methods = array_merge( $methods, $icl_methods );
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && preg_match( '#<methodName>([^<]+)</methodName>#i', $this->sitepress->get_wp_api()->get_raw_post_data(), $matches ) ) {
			$method = $matches[1];
			if ( array_key_exists( $method, $icl_methods ) ) {
				set_error_handler( array( $this, 'translation_error_handler' ), E_ERROR | E_USER_ERROR );
			}
		}

		return $methods;
	}

	public function xmlrpc_updated_job_status( $args ) {
		global $wpdb;

		$tp_id     = isset( $args[0] ) ? $args[0] : 0;
		$cms_id    = isset( $args[1] ) ? $args[1] : 0;
		$status    = isset( $args[2] ) ? $args[2] : '';
		$signature = isset( $args[3] ) ? $args[3] : '';

		if ( ! $this->authenticate_request( $tp_id, $cms_id, $status, $signature ) ) {
			return new IXR_Error( 401, 'Wrong signature' );
		}

		try {

			$jobs_repository = wpml_tm_get_jobs_repository();

			$job_match = $jobs_repository->get(
				new WPML_TM_Jobs_Search_Params(
					array(
						'scope' => 'remote',
						'tp_id' => $tp_id,
					)
				)
			);

			if ( $job_match ) {
				$jobs_array = $job_match->toArray();
				$job        = $jobs_array[0];
				$job->set_status( WPML_TP_Job_States::map_tp_state_to_local( $status ) );

				$tp_sync_updated_job = new WPML_TP_Sync_Update_Job( $wpdb, $this->sitepress );
				$job_updated         = $tp_sync_updated_job->update_state( $job );

				if ( $job_updated && WPML_TP_Job_States::CANCELLED !== $status ) {
					$apply_tp_translation = new WPML_TP_Apply_Single_Job(
						wpml_tm_get_tp_translations_repository(),
						new WPML_TP_Apply_Translation_Strategies( $wpdb )
					);
					$apply_tp_translation->apply( $job );

				}

				return 1;

			}
		} catch ( Exception $e ) {
			return new IXR_Error( $e->getCode(), $e->getMessage() );
		}

		return 0;
	}

	private function authenticate_request( $tp_id, $cms_id, $status, $signature ) {
		$project = TranslationProxy::get_current_project();

		return sha1( $project->id . $project->access_key . $tp_id . $cms_id . $status ) === $signature;
	}

	function get_wpml_wp_api() {
		return $this->sitepress->get_wp_api();
	}

	private static function content_get_link_paths( $body ) {

		$regexp_links = array(
			"/<a[^>]*href\s*=\s*([\"\']??)([^\"^>]+)[\"\']??([^>]*)>/i",
		);

		$links = array();

		foreach ( $regexp_links as $regexp ) {
			if ( preg_match_all( $regexp, is_null( $body ) ? '' : $body, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$links[] = $match;
				}
			}
		}

		return $links;
	}

	public function fix_links_to_translated_content( $element_id, $target_lang_code, $element_type = 'post', $preLoaded = [] ) {
		global $wpdb, $sitepress;

		$wpml_element_type          = $element_type;
		$body                       = '';
		$postExcerpt = '';
		$string_type                = null;
		$links_fixed_status_factory = new WPML_Links_Fixed_Status_Factory( $wpdb, new WPML_WP_API() );
		$links_fixed_status         = $links_fixed_status_factory->create( $element_id, $wpml_element_type );


		if ( strpos( $element_type, 'post' ) === 0 ) {
			if (
				is_array( $preLoaded )
				&& array_key_exists( 'post_type', $preLoaded )
				&& array_key_exists( 'post_content', $preLoaded )
				&& array_key_exists( 'post_excerpt', $preLoaded )
			) {
				$wpml_element_type = 'post_' . $preLoaded['post_type'];
				$body              = $preLoaded['post_content'];
				$postExcerpt       = $preLoaded['post_excerpt'];
			} else {
				$post_prepared = $wpdb->prepare( "SELECT post_content, post_excerpt, post_type FROM {$wpdb->posts} WHERE ID=%d", array( $element_id ) );
				$post          = $wpdb->get_row( $post_prepared );

				if ( ! $post ) {
					$links_fixed_status->set( true );

					return 0;
				}

				$body              = $post->post_content;
				$postExcerpt       = $post->post_excerpt;
				$wpml_element_type = 'post_' . $post->post_type;
			}
		} elseif ( $element_type == 'string' ) {
			if (
				is_array( $preLoaded )
				&& array_key_exists( 'value', $preLoaded )
				&& array_key_exists( 'string_id', $preLoaded )
			) {
				$body               = $preLoaded['value'];
				$original_string_id = $preLoaded['string_id'];
			} else {
				$data = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT string_id, value
						FROM {$wpdb->prefix}icl_string_translations
						WHERE id=%d",
						array( $element_id )
					)
				);

				if ( ! $data ) {
					$links_fixed_status->set( true );
					return 0;
				}

				$body               = $data->value;
				$original_string_id = $data->string_id;
			}

			$string_type = $wpdb->get_var( $wpdb->prepare( "SELECT type FROM {$wpdb->prefix}icl_strings WHERE id=%d", $original_string_id ) );
			if ( 'LINK' === $string_type ) {
				$body = '<a href="' . $body . '">removeit</a>';
			}
		}

		if ( ! AbsoluteLinks::has_href_attribute( $body ) && ! AbsoluteLinks::has_href_attribute( $postExcerpt ) ) {
			$links_fixed_status->set( true );
			return 0;
		}

		$translate_link_targets = make( 'WPML_Translate_Link_Targets' );
		$absolute_links         = make( 'AbsoluteLinks' );

		$getTranslatedLink = function ( $link ) use ( $translate_link_targets, $absolute_links, $element_type, $target_lang_code, $sitepress ) {
			$id_link = $target_lang_code . '-' . $link[0];
			if ( isset( self::$translated_links[ $id_link ] ) ) {
				return [
					'from' => $link[0],
					'to'   => self::$translated_links[ $id_link ],
				];
			}

			if ( ! $this->is_language_switched ) {
				$this->is_language_switched = true;
				$sitepress->switch_lang( $target_lang_code );
			}

			$link_url = WPML_Same_Site_Url_Normalizer::normalize_url( $link[2] );

			if ( $absolute_links->is_home( $link_url ) ) {
				$translatedLink = $absolute_links->convert_url( $link_url, $target_lang_code );
				$translatedLink = Str::replace( $link[2], $translatedLink, $link[0] );
			} else {
				add_filter( 'wpml_force_translated_permalink', '__return_true' );
				$translatedLink = $translate_link_targets->convert_text( $link[0] );
				remove_filter( 'wpml_force_translated_permalink', '__return_true' );
				if ( self::should_links_be_converted_back_to_sticky( $element_type ) ) {
					$translatedLink = $absolute_links->convert_text( $translatedLink );
				}
			}

			self::$translated_links[ $id_link ] = $translatedLink;

			return $translatedLink !== $link[0]
				? [
					'from' => $link[0],
					'to'   => $translatedLink,
				]
				: null;
		};

		$getTranslatedLinks = pipe(
			Fns::map( $getTranslatedLink ),
			Fns::filter( Fns::identity() )
		);

		$links = self::content_get_link_paths( $body );
		$postExcerptLinks = self::content_get_link_paths( $postExcerpt );

		$translatedLinks = $getTranslatedLinks( $links );
		$postExcerptTranslatedLinks = $getTranslatedLinks( $postExcerptLinks );

		$replaceLink = function ( $body, $link ) {
			return str_replace( $link['from'], $link['to'], $body );
		};

		$new_body = Fns::reduce( $replaceLink, $body, $translatedLinks );
		$newExcerpt = Fns::reduce($replaceLink, $postExcerpt, $postExcerptTranslatedLinks);

		if ( strpos( $element_type, 'post' ) === 0 ) {
			$updatePost = [];

			if ( $new_body != $body ) {
				$updatePost['post_content'] = $new_body;
			}

			if ( $newExcerpt != $postExcerpt ) {
				$updatePost['post_excerpt'] = $newExcerpt;
			}

			if ( ! empty( $updatePost ) ) {
				$updated = $wpdb->update(
					$wpdb->posts,
					$updatePost,
					[ 'ID' => $element_id ]
				);

				if ( false !== $updated ) {
					clean_post_cache( $element_id );
				}
			}
		} elseif ( $element_type == 'string' && $new_body != $body ) {
			if ( 'LINK' === $string_type ) {
				$new_body = str_replace( array( '<a href="', '">removeit</a>' ), array( '', '' ), $new_body );
				$wpdb->update(
					$wpdb->prefix . 'icl_string_translations',
					array(
						'value'  => $new_body,
						'status' => ICL_TM_COMPLETE,
					),
					array( 'id' => $element_id )
				);
				do_action( 'icl_st_add_string_translation', $element_id );
			} else {
				$wpdb->update( $wpdb->prefix . 'icl_string_translations', array( 'value' => $new_body ), array( 'id' => $element_id ) );
			}
		}

		$links_fixed_status_factory = new WPML_Links_Fixed_Status_Factory( $wpdb, new WPML_WP_API() );
		$links_fixed_status         = $links_fixed_status_factory->create( $element_id, $wpml_element_type );
		$links_fixed_status->set( true );

		if ( $this->is_language_switched ) {
			$this->is_language_switched = false;
			$sitepress->switch_lang();
		}

		return sizeof( $translatedLinks );

	}

	function translation_error_handler( $error_number, $error_string, $error_file, $error_line ) {
		switch ( $error_number ) {
			case E_ERROR:
			case E_USER_ERROR:
				throw new Exception( $error_string . ' [code:e' . $error_number . '] in ' . $error_file . ':' . $error_line );
			case E_WARNING:
			case E_USER_WARNING:
				return true;
			default:
				return true;
		}

	}

	private static function should_links_be_converted_back_to_sticky( $element_type ) {
		return 'string' !== $element_type && ! empty( $GLOBALS['WPML_Sticky_Links'] );
	}

	function post_submitbox_start() {
		$show_box_style = $this->get_show_minor_edit_style();
		if ( false !== $show_box_style ) {
			?>
			<p id="icl_minor_change_box" style="float:left;padding:0;margin:3px;<?php echo $show_box_style; ?>">
				<label><input type="checkbox" name="icl_minor_edit" value="1" style="min-width:15px;"/>&nbsp;
					<?php esc_html_e( 'Minor edit - don\'t update translation', 'wpml-translation-management' ); ?>
				</label>
				<br clear="all"/>
			</p>
			<?php
		}
	}

	public function gutenberg_minor_edit() {
		$show_box_style = $this->get_show_minor_edit_style();
		if ( false !== $show_box_style ) {
			?>
			<div id="icl_minor_change_box" style="<?php echo $show_box_style; ?>" class="icl_box_paragraph">
				<p>
					<strong><?php esc_html_e( 'Minor edit', 'wpml-translation-management' ); ?></strong>
				</p>
				<label><input type="checkbox" name="icl_minor_edit" value="1" style="min-width:15px;"/>&nbsp;
					<?php esc_html_e( "Don't update translation", 'wpml-translation-management' ); ?>
				</label>
			</div>
			<?php
		}
	}

	private function get_show_minor_edit_style() {
		global $post, $iclTranslationManagement;
		if ( empty( $post ) || ! $post->ID ) {
			return false;
		}

		$translations   = $iclTranslationManagement->get_element_translations( $post->ID, 'post_' . $post->post_type );
		$show_box_style = 'display:none';
		foreach ( $translations as $t ) {
			if ( $t->element_id == $post->ID ) {
				return false;
			}
			if ( $t->status == ICL_TM_COMPLETE && ! $t->needs_update ) {
				$show_box_style = '';
				break;
			}
		}

		return $show_box_style;
	}


	private function add_error( $project_error ) {
		$this->errors[] = $project_error;
	}

	function enqueue_project_errors( $project ) {
		if ( isset( $project ) && isset( $project->errors ) && $project->errors ) {
			foreach ( $project->errors as $project_error ) {
				$this->add_error( $project_error );
			}
		}
	}

	private function maybe_init_translation_management( $iclTranslationManagement ) {
		if ( empty( $this->tmg->settings ) ) {
			$iclTranslationManagement->init();
		}
	}
}
