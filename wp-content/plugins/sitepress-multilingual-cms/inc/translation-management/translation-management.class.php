<?php

use WPML\Auryn\InjectionException;
use WPML\FP\Fns;
use WPML\FP\Logic;
use WPML\FP\Maybe;
use WPML\FP\Obj;
use WPML\FP\Lst;
use WPML\FP\Relation;
use WPML\LIB\WP\User;
use WPML\TM\API\Batch;
use WPML\TM\API\Jobs;
use WPML\TM\Jobs\Dispatch\BatchBuilder;
use WPML\TM\Jobs\Dispatch\Messages;
use WPML\UIPage;
use WPML\TM\TranslationDashboard\SentContentMessages;
use WPML\TM\TranslationDashboard\FiltersStorage;
use WPML\TM\TranslationDashboard\EncodedFieldsValidation\Validator;
use function WPML\Container\make;
use function WPML\FP\invoke;
use function WPML\FP\partialRight;
use function WPML\FP\pipe;
use WPML\TM\Jobs\JobLog;
use WPML\Core\Component\PostHog\Application\Service\Event\EventInstanceService;

class TranslationManagement {

	const INIT_PRIORITY = 1500;

	const DUPLICATE_ELEMENT_ACTION = 2;
	const TRANSLATE_ELEMENT_ACTION = 1;

	private $selected_translator;
	private $current_translator;
	private $messages = array();
	public $settings;
	public $admin_texts_to_translate = array();
	private $comment_duplicator;

	private $settings_factory;

	private $cache_factory;

	private $message_ids = array( 'add_translator', 'edit_translator', 'remove_translator', 'save_notification_settings' );
	private $filters_and_actions;

	private $wpml_cookie;

	private static $send_jobs_added_for_types = [];

	private $sent_job_ids_per_type = [];


	function __construct( ?WPML_Cookie $wpml_cookie = null ) {

		global $sitepress, $wpml_cache_factory;

		$this->selected_translator     = new WPML_Translator();
		$this->selected_translator->ID = 0;
		$this->current_translator      = new WPML_Translator();
		$this->current_translator->ID  = 0;
		$this->cache_factory           = $wpml_cache_factory;

		add_action( 'init', array( $this, 'init' ), self::INIT_PRIORITY );
		add_action( 'wp_loaded', array( $this, 'wp_loaded' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 10, 0 );
		add_action( 'delete_post', array( $this, 'delete_post_actions' ), 1, 1 );
		add_action( 'icl_ajx_custom_call', array( $this, 'ajax_calls' ), 10, 2 );
		add_action( 'wpml_tm_basket_add_message', array( $this, 'add_basket_message' ), 10, 3 );

		if ( ( isset( $_GET['sm'] ) && ( $_GET['sm'] == 'dashboard' || $_GET['sm'] == 'jobs' ) )
			 || ( isset( $_GET['page'] ) && preg_match( '@/menu/main\.php$@', $_GET['page'] ) && ! isset( $_GET['sm'] ) )
			 || ( isset( $_GET['page'] ) && preg_match( '@/menu/translations-queue\.php$@', $_GET['page'] ) )
		) {
			@session_start();
		}
		add_filter( 'icl_additional_translators', array( $this, 'icl_additional_translators' ), 99, 3 );

		add_action( 'display_basket_notification', array( $this, 'display_basket_notification' ), 10, 1 );
		Fns::each(
			function( $type ) {
				if ( ! in_array( $type, self::$send_jobs_added_for_types, true ) ) {
					add_action( "wpml_tm_send_{$type}_jobs", [ $this, 'action_send_jobs' ], 10, 3 );
					self::$send_jobs_added_for_types[] = $type;
				}
			},
			[ 'post', 'package', 'st-batch' ]
		);
		$this->init_comments_synchronization();
		add_action( 'wpml_loaded', array( $this, 'wpml_loaded_action' ) );

		add_filter( 'wpml_translation_job_id', array( $this, 'get_translation_job_id_filter' ), 10, 2 );

		$this->filters_and_actions = new WPML_Translation_Management_Filters_And_Actions( $this, $sitepress );

		$this->wpml_cookie = $wpml_cookie ?: new WPML_Cookie();
	}

	public function wpml_loaded_action() {
		$this->load_settings_if_required();
		if ( is_admin() ) {
			add_action( 'wpml_config', array( $this, 'wpml_config_action' ), 10, 1 );
		}
	}

	public function load_settings_if_required() {
		if ( ! $this->settings ) {
			$this->settings = apply_filters( 'wpml_setting', null, 'translation-management' );
		}
	}

	public function wpml_config_action( $args ) {
		if ( current_user_can( 'manage_options' ) ) {
			$this->update_section_translation_setting( $args );
		}
	}

	public function settings_factory() {
		$this->settings_factory = $this->settings_factory
			? $this->settings_factory
			: new WPML_Custom_Field_Setting_Factory( $this );

		return $this->settings_factory;
	}

	private function init_translator_language_pairs( WP_User $current_user, WPML_Translator $current_translator ) {
		$languagePairRecords = make( WPML_Language_Pair_Records::class );

		$current_translator_language_pairs  = $languagePairRecords->get( $current_user->ID );
		$current_translator_language_pairs  = $languagePairRecords->convert_to_storage_format( $current_translator_language_pairs );

		$current_translator->language_pairs = $this->sanitize_language_pairs( $current_translator_language_pairs );

		if ( ! count( $current_translator->language_pairs ) ) {
			$current_translator->language_pairs = array();
		}

		return $current_translator;
	}

	private function is_valid_language_code_format( $code ) {
		return $code && is_string( $code ) && strlen( $code ) >= 2;
	}

	private function sanitize_language_pairs( $language_pairs ) {
		if ( ! $language_pairs || ! is_array( $language_pairs ) ) {
			$language_pairs = array();
		} else {
			$language_codes_from = array_keys( $language_pairs );
			foreach ( $language_codes_from as $code_from ) {
				$language_codes_to = array_keys( $language_pairs[ $code_from ] );

				foreach ( $language_codes_to as $code_to ) {
					if ( ! $this->is_valid_language_code_format( (string) $code_to ) ) {
						unset( $language_pairs[ $code_from ][ $code_to ] );
					}
				}

				if ( ! $this->is_valid_language_code_format( $code_from ) || ! count( $language_pairs[ $code_from ] ) ) {
					unset( $language_pairs[ $code_from ] );
				}
			}
		}
		return $language_pairs;
	}

	private function update_section_translation_setting( $args ) {
		$section   = $args['section'];
		$key       = $args['key'];
		$value     = $args['value'];
		$read_only = isset( $args['read_only'] ) ? $args['read_only'] : true;

		$section                        = preg_replace( '/-/', '_', $section );
		$config_section                 = $this->get_translation_setting_name( $section );
		$custom_config_readonly_section = $this->get_custom_readonly_translation_setting_name( $section );
		if ( isset( $this->settings[ $config_section ] ) ) {
			$this->settings[ $config_section ][ esc_sql( $key ) ] = esc_sql( $value );
			if ( ! isset( $this->settings[ $custom_config_readonly_section ] ) ) {
				$this->settings[ $custom_config_readonly_section ] = array();
			}
			if ( $read_only === true && ! in_array( $key, $this->settings[ $custom_config_readonly_section ] ) ) {
				$this->settings[ $custom_config_readonly_section ][] = esc_sql( $key );
			}
			$this->save_settings();
		}
	}

	public function init() {
		$this->init_comments_synchronization();
		$this->init_default_settings();

		WPML_Config::load_config();

		if ( is_admin() ) {
			if ( $GLOBALS['pagenow'] === 'edit.php' ) {
				add_action( 'admin_notices', array( $this, 'show_messages' ) );
			} else {
				add_action( 'icl_tm_messages', array( $this, 'show_messages' ) );
			}

			$this->wpml_add_duplicate_check_actions();

			if ( empty( $this->settings['doc_translation_method'] ) || ! defined( 'WPML_TM_VERSION' ) ) {
				$this->settings['doc_translation_method'] = ICL_TM_TMETHOD_MANUAL;
			}
		}
	}

	public function get_settings() {
		$this->load_settings_if_required();
		return $this->settings;
	}

	public function wpml_add_duplicate_check_actions() {
		global $pagenow;
		if (
			'post.php' === $pagenow
			||
			( isset( $_POST['action'] ) && 'check_duplicate' === $_POST['action'] && DOING_AJAX )
		) {
			return new WPML_Translate_Independently();
		}
	}

	public function wp_loaded() {
		if ( isset( $_POST['icl_tm_action'] ) ) {
			$this->process_request( $_POST );
		} elseif ( isset( $_GET['icl_tm_action'] ) ) {
			$this->process_request( $_GET );
		}
	}

	public function admin_enqueue_scripts() {
		if ( ! defined( 'WPML_TM_FOLDER' ) ) {
			return;
		}

		if ( UIPage::isTMBasket( $_GET ) ) {
			wp_register_style( 'translation-basket', WPML_TM_URL . '/res/css/translation-basket.css', array(), ICL_SITEPRESS_SCRIPT_VERSION );
			wp_enqueue_style( 'translation-basket' );
		} elseif ( UIPage::isTMTranslators( $_GET ) ) {
			wp_register_style( 'translation-translators', WPML_TM_URL . '/res/css/translation-translators.css', array( 'otgs-icons' ), ICL_SITEPRESS_SCRIPT_VERSION );
			wp_enqueue_style( 'translation-translators' );
		} elseif ( UIPage::isSettings( $_GET ) ) {
			wp_register_style( 'sitepress-translation-options', ICL_PLUGIN_URL . '/res/css/translation-options.css', array(), ICL_SITEPRESS_SCRIPT_VERSION );
			wp_enqueue_style( 'sitepress-translation-options' );
		} elseif ( UIPage::isTMDashboard( $_GET ) ) {
			wp_register_style( 'translation-dashboard', WPML_TM_URL . '/res/css/translation-dashboard.css', array(), ICL_SITEPRESS_SCRIPT_VERSION );
			wp_enqueue_style( 'translation-dashboard' );
			wp_register_style( 'translation-translators', WPML_TM_URL . '/res/css/translation-translators.css', array( 'otgs-icons' ), ICL_SITEPRESS_SCRIPT_VERSION );
			wp_enqueue_style( 'translation-translators' );
		}
	}

	public static function get_batch_name( $batch_id ) {
		$batch_data = self::get_batch_data( $batch_id );
		if ( ! $batch_data || ! isset( $batch_data->batch_name ) ) {
			$batch_name = __( 'No Batch', 'sitepress' );
		} else {
			$batch_name = $batch_data->batch_name;
		}

		return $batch_name;
	}

	public static function get_batch_url( $batch_id ) {
		$batch_data = self::get_batch_data( $batch_id );
		$batch_url  = '';
		if ( $batch_data && isset( $batch_data->tp_id ) && $batch_data->tp_id != 0 ) {
			$batch_url = OTG_TRANSLATION_PROXY_URL . "/projects/{$batch_data->tp_id}/external";
		}

		return $batch_url;
	}

	public static function get_batch_last_update( $batch_id ) {
		$batch_data = self::get_batch_data( $batch_id );

		return $batch_data ? $batch_data->last_update : false;
	}

	public static function get_batch_tp_id( $batch_id ) {
		$batch_data = self::get_batch_data( $batch_id );

		return $batch_data ? $batch_data->tp_id : false;
	}

	public static function get_batch_data( $batch_id ) {
		$cache_key   = $batch_id;
		$cache_group = 'get_batch_data';
		$cache_found = false;

		$batch_data = wp_cache_get( $cache_key, $cache_group, false, $cache_found );

		if ( $cache_found ) {
			return $batch_data;
		}

		global $wpdb;
		$batch_data_sql      = "SELECT * FROM {$wpdb->prefix}icl_translation_batches WHERE id=%d";
		$batch_data_prepared = $wpdb->prepare( $batch_data_sql, array( $batch_id ) );
		$batch_data          = $wpdb->get_row( $batch_data_prepared );

		wp_cache_set( $cache_key, $batch_data, $cache_group );

		return $batch_data;
	}

	function save_settings() {
		global $sitepress;

		$icl_settings['translation-management'] = $this->settings;
		$cpt_sync_option                        = $sitepress->get_setting( 'custom_posts_sync_option', array() );
		$cpt_sync_option                        = (bool) $cpt_sync_option === false ? $sitepress->get_setting( 'custom-types_sync_option', array() ) : $cpt_sync_option;
		$cpt_unlock_options                     = $sitepress->get_setting( 'custom_posts_unlocked_option', array() );

		if ( ! isset( $icl_settings['custom_posts_sync_option'] ) ) {
			$icl_settings['custom_posts_sync_option'] = array();
		}

		foreach ( $cpt_sync_option as $k => $v ) {
			$icl_settings['custom_posts_sync_option'][ $k ] = $v;
		}
		$icl_settings['translation-management']['custom-types_readonly_config'] = isset( $icl_settings['translation-management']['custom-types_readonly_config'] ) ? $icl_settings['translation-management']['custom-types_readonly_config'] : array();
		foreach ( $icl_settings['translation-management']['custom-types_readonly_config'] as $k => $v ) {
			if ( ! $this->is_unlocked_type( $k, $cpt_unlock_options ) ) {
				$icl_settings['custom_posts_sync_option'][ $k ] = $v;
			}
		}
		$sitepress->set_setting( 'translation-management', $icl_settings['translation-management'], true );
		$sitepress->set_setting( 'custom_posts_sync_option', $icl_settings['custom_posts_sync_option'], true );
		$this->settings = $sitepress->get_setting( 'translation-management' );
	}

	public function initial_custom_field_translate_states() {
		global $wpdb;

		$this->initial_term_custom_field_translate_states();

		return $this->initial_translation_states( $wpdb->postmeta );
	}

	public function initial_term_custom_field_translate_states() {
		global $wpdb;

		return ! empty( $wpdb->termmeta )
			? $this->initial_translation_states( $wpdb->termmeta )
			: array();
	}

	function process_request( $data ) {
		$nonce  = isset( $data['nonce'] ) ? sanitize_text_field( $data['nonce'] ) : '';
		$action = isset( $data['icl_tm_action'] ) ? sanitize_text_field( $data['icl_tm_action'] ) : '';
		$data   = stripslashes_deep( $data );
		switch ( $action ) {
			case 'edit':
				$this->selected_translator->ID = intval( $data['user_id'] );
				break;
			case 'ujobs_filter':
				$cookie_data                            = filter_var( http_build_query( $data['filter'] ), FILTER_SANITIZE_URL );
				$_COOKIE['wp-translation_ujobs_filter'] = $cookie_data;
				$cookie_data && $this->set_cookie( 'wp-translation_ujobs_filter', $cookie_data, time() + HOUR_IN_SECONDS );
				wp_safe_redirect( 'admin.php?page=' . WPML_TM_FOLDER . '/menu/translations-queue.php', 302, 'WPML' );
				break;
			case 'save_translation':
				if ( wp_verify_nonce( $nonce, 'save_translation' ) ) {
					if ( ! empty( $data['resign'] ) ) {
						$this->resign_translator( $data['job_id'] );
						if ( wp_safe_redirect( admin_url( 'admin.php?page=' . WPML_TM_FOLDER . '/menu/translations-queue.php&resigned=' . $data['job_id'] ), 302, 'WPML' ) ) {
							exit;
						}
					} else {
						do_action( 'wpml_save_translation_data', $data );
					}
				}
				break;
			case 'save_notification_settings':
				$this->icl_tm_save_notification_settings( $data );
				break;
		}
	}

	private function set_cookie( $name, $value, $expiration ) {
		$this->wpml_cookie->set_cookie( $name, $value, $expiration, COOKIEPATH, COOKIE_DOMAIN );
	}

	private function get_cookie( $name ) {
		$result = [];

		$cookie = new WPML_Cookie();
		$value  = $cookie->get_cookie( $name );

		parse_str( $value, $result );

		return $result;
	}

	function ajax_calls( $call, $data ) {
		global $wpdb, $sitepress;
		switch ( $call ) {
			case 'icl_cf_translation':
			case 'icl_tcf_translation':
				foreach (
					array(
						'cf'          => $call === 'icl_tcf_translation' ? WPML_TERM_META_SETTING_INDEX_PLURAL : WPML_POST_META_SETTING_INDEX_PLURAL,
						'cf_unlocked' => $call === 'icl_tcf_translation' ? WPML_TERM_META_UNLOCKED_SETTING_INDEX : WPML_POST_META_UNLOCKED_SETTING_INDEX,
					) as $field => $setting
				) {
					if ( ! empty( $data[ $field ] ) ) {
						$cft = array();
						foreach ( $data[ $field ] as $k => $v ) {
							$cft[ base64_decode( $k ) ] = $v;
						}
						if ( ! empty( $cft ) ) {
							if ( ! isset( $this->settings[ $setting ] ) ) {
								$this->settings[ $setting ] = array();
							}
							$this->settings[ $setting ] = array_merge( $this->settings[ $setting ], $cft );
							$this->save_settings();
							do_action( 'wpml_custom_fields_sync_option_updated', $cft );
						}
					}
				}
				echo '1|';
				break;
			case 'icl_doc_translation_method':
				$new_editor = Obj::prop( 't_method', $data );
				if ( $new_editor ) {
					$previous_editor = isset( $this->settings['doc_translation_method'] )
						? $this->settings['doc_translation_method']
						: ICL_TM_TMETHOD_ATE;

					$this->settings['doc_translation_method'] = $new_editor;
					$sitepress->set_setting( 'doc_translation_method', $this->settings['doc_translation_method'] );

					if ( (string) $previous_editor !== (string) $new_editor ) {
						$this->capture_translation_editor_switched_event( $previous_editor, $new_editor );
						$this->triggerCdtStatsResend();
					}
				}

				if ( isset( $data['translation_memory'] ) ) {
					$sitepress->set_setting( 'translation_memory', $data['translation_memory'] );
				}

				$this->save_settings();
				echo '1|';
				break;
			case 'reset_duplication':
				$this->reset_duplicate_flag( $_POST['post_id'] );
				break;
			case 'set_duplication':
				$new_id = $this->set_duplicate( $_POST['wpml_original_post_id'], $_POST['post_lang'] );
				wp_send_json_success( array( 'id' => $new_id ) );
				break;
		}
	}

	public function get_element_prefix( $element_type_full ) {
		$element_type_parts = explode( '_', $element_type_full );
		$element_type       = $element_type_parts[0];

		return $element_type;
	}

	public function get_element_type_prefix_from_job_id( $job_id ) {
		$job = $this->get_translation_job( $job_id );

		if ( isset( $job->element_type_prefix ) ) {
			return $job->element_type_prefix;
		}

		return $job ? $this->get_element_type_prefix_from_job( $job ) : false;
	}

	public function get_element_type_prefix_from_job( $job ) {
		if ( is_object( $job ) ) {
			$element_type        = $this->get_element_type( $job->trid );
			$element_type_prefix = $this->get_element_prefix( $element_type );
		} else {
			$element_type_prefix = false;
		}

		return $element_type_prefix;
	}

	public function show_messages() {
		foreach ( $this->message_ids as $message_suffix ) {
			$message_id = 'icl_tm_message_' . $message_suffix;
			$message    = ICL_AdminNotifier::get_message( $message_id );
			if ( isset( $message['type'], $message['text'] ) ) {
				echo '<div class="' . esc_attr( $message['type'] ) . ' below-h2"><p>' . esc_html( $message['text'] ) . '</p></div>';
				ICL_AdminNotifier::remove_message( $message_id );
			}
		}
	}


	public function has_translators() {
		if ( function_exists( 'wpml_tm_load_blog_translators' ) ) {
			return wpml_tm_load_blog_translators()->has_translators();
		}

		return false;
	}

	public static function get_blog_translators( $args = array() ) {
		$translators = array();

		if ( function_exists( 'wpml_tm_load_blog_translators' ) ) {
			$translators = wpml_tm_load_blog_translators()->get_blog_translators( $args );
		}

		return $translators;
	}

	function get_selected_translator() {
		global $wpdb;
		if ( $this->selected_translator && $this->selected_translator->ID ) {
			$user                                      = new WP_User( $this->selected_translator->ID );
			$this->selected_translator->display_name   = $user->data->display_name;
			$this->selected_translator->user_login     = $user->data->user_login;
			$this->selected_translator->language_pairs = get_user_meta( $this->selected_translator->ID, $wpdb->prefix . 'language_pairs', true );
		} else {
			$this->selected_translator->ID = 0;
		}

		return $this->selected_translator;
	}

	function get_current_translator() {
		$current_translator        = $this->current_translator;
		$current_translator_is_set = $current_translator && $current_translator->ID > 0 && $current_translator->language_pairs;

		if ( ! $current_translator_is_set ) {
			$this->init_current_translator();
		}

		return $this->current_translator;
	}

	public static function get_translator_edit_url( $translator_id ) {
		$url = '';
		if ( ! empty( $translator_id ) ) {
			$url = 'admin.php?page=' . WPML_TM_FOLDER . '/menu/main.php&amp;sm=translators&icl_tm_action=edit&amp;user_id=' . $translator_id;
		}

		return $url;
	}


	function make_duplicates( $data ) {
		foreach ( $data['iclpost'] as $master_post_id ) {
			foreach ( $data['duplicate_to'] as $lang => $one ) {
				$this->make_duplicate( $master_post_id, $lang );
			}
		}
	}

	function make_duplicate( $master_post_id, $lang ) {
		global $sitepress;

		return $sitepress->make_duplicate( $master_post_id, $lang );
	}

	function make_duplicates_all( $master_post_id ) {
		global $sitepress;

		$master_post = get_post( $master_post_id );
		if ( $master_post->post_status == 'auto-draft' || $master_post->post_type == 'revision' ) {
			return;
		}

		$language_details_original = $sitepress->get_element_language_details( $master_post_id, 'post_' . $master_post->post_type );

		if ( ! $language_details_original ) {
			return;
		}

		$data['iclpost'] = array( $master_post_id );
		foreach ( $sitepress->get_active_languages() as $lang => $details ) {
			if ( $lang != $language_details_original->language_code ) {
				$data['duplicate_to'][ $lang ] = 1;
			}
		}

		$this->make_duplicates( $data );
	}

	function reset_duplicate_flag( $post_id ) {
		global $sitepress;

		$post = get_post( $post_id );

		$trid         = $sitepress->get_element_trid( $post_id, 'post_' . $post->post_type );
		$translations = $sitepress->get_element_translations( $trid, 'post_' . $post->post_type );

		foreach ( $translations as $tr ) {
			if ( $tr->element_id == $post_id ) {
				$this->update_translation_status(
					array(
						'translation_id' => $tr->translation_id,
						'status'         => ICL_TM_COMPLETE,
					)
				);
			}
		}

		delete_post_meta( $post_id, '_icl_lang_duplicate_of' );
	}

	function set_duplicate( $master_post_id, $post_lang ) {
		$new_id = 0;
		if ( $master_post_id && $post_lang ) {
			$new_id = $this->make_duplicate( $master_post_id, $post_lang );
		}

		return $new_id;
	}

	function duplication_delete_comment( $comment_id ) {
		global $wpdb;

		$original_comment = (bool) get_comment_meta( $comment_id, '_icl_duplicate_of', true ) === false;
		if ( $original_comment ) {
			$duplicates = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT comment_id
														   FROM {$wpdb->commentmeta}
														   WHERE meta_key='_icl_duplicate_of'
														   AND meta_value=%d",
					$comment_id
				)
			);
			foreach ( $duplicates as $dup ) {
				wp_delete_comment( $dup, true );
			}
		}
	}

	function duplication_edit_comment( $comment_id ) {
		global $wpdb;

		$comment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID=%d", $comment_id ), ARRAY_A );
		unset( $comment['comment_ID'], $comment['comment_post_ID'] );

		$comment_meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id=%d AND meta_key <> '_icl_duplicate_of'", $comment_id ) );

		$original_comment = get_comment_meta( $comment_id, '_icl_duplicate_of', true );
		if ( $original_comment ) {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $original_comment ) );
			$duplicates = array( $original_comment ) + array_diff( $duplicates, array( $comment_id ) );
		} else {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $comment_id ) );
		}

		if ( ! empty( $duplicates ) ) {
			foreach ( $duplicates as $dup ) {

				$wpdb->update( $wpdb->comments, $comment, array( 'comment_ID' => $dup ) );

				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id=%d AND meta_key <> '_icl_duplicate_of'", $dup ) );

				if ( $comment_meta ) {
					foreach ( $comment_meta as $key => $value ) {
						wp_cache_delete( $dup, 'comment_meta' );
						update_comment_meta( $dup, $value->meta_key, $value->meta_value );
					}
				}
			}
		}
	}

	function duplication_status_comment( $comment_id, $comment_status ) {
		global $wpdb;

		static $_avoid_8_loop;

		if ( isset( $_avoid_8_loop ) ) {
			return;
		}
		$_avoid_8_loop = true;

		$original_comment = get_comment_meta( $comment_id, '_icl_duplicate_of', true );
		if ( $original_comment ) {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $original_comment ) );
			$duplicates = array( $original_comment ) + array_diff( $duplicates, array( $comment_id ) );
		} else {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $comment_id ) );
		}

		if ( ! empty( $duplicates ) ) {
			foreach ( $duplicates as $duplicate ) {
				wp_set_comment_status( $duplicate, $comment_status );
			}
		}

		unset( $_avoid_8_loop );
	}

	function duplication_insert_comment( $comment_id ) {
		global $wpdb, $sitepress;

		$duplicator = $this->get_comment_duplicator();

		$comment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID=%d", $comment_id ), ARRAY_A );

		$skip_duplication = apply_filters( 'wpml_skip_comment_duplication', false, $comment_id, $comment );

		if ( $skip_duplication ) {
			return;
		}

		$post_id = $comment['comment_post_ID'];

		$duplicate_of = get_post_meta( $post_id, '_icl_lang_duplicate_of', true );
		if ( $duplicate_of ) {
			$post_duplicates = $sitepress->get_duplicates( $duplicate_of );
			$duplicator->move_to_original( $duplicate_of, $post_duplicates, $comment );
			$this->duplication_insert_comment( $comment_id );

			return;
		} else {
			$post_duplicates = $sitepress->get_duplicates( $post_id );
		}
		unset( $comment['comment_ID'], $comment['comment_post_ID'] );
		foreach ( $post_duplicates as $lang => $dup_id ) {
			$comment['comment_post_ID'] = $dup_id;

			if ( $comment['comment_parent'] ) {
				$translated_parent = $duplicator->get_correct_parent( $comment, $dup_id );
				if ( ! $translated_parent ) {
					$this->duplication_insert_comment( $comment['comment_parent'] );
					$translated_parent = $duplicator->get_correct_parent( $comment, $dup_id );
				}
				$comment['comment_parent'] = $translated_parent;
			}

			$duplicator->insert_duplicated_comment( $comment, $dup_id, $comment_id );
		}
	}

	private function get_comment_duplicator() {

		if ( ! $this->comment_duplicator ) {
			$this->comment_duplicator = new WPML_Comment_Duplication();
		}

		return $this->comment_duplicator;
	}

	public function delete_post_actions( $post_id ) {
		global $wpdb;

		$post_type = $wpdb->get_var( $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $post_id ) );

		if ( ! empty( $post_type ) ) {
			$trid_subquery = $wpdb->prepare(
				"SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s AND source_language_code IS NULL",
				$post_id,
				'post_' . $post_type
			);

			$translation_ids = $wpdb->get_col(
				"SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE trid = (" . $trid_subquery . ')'
			);

			if ( $translation_ids ) {
				$rids = $wpdb->get_col(
					"SELECT rid FROM {$wpdb->prefix}icl_translation_status WHERE translation_id IN (" . wpml_prepare_in( $translation_ids, '%d' ) . ')'
				);
				$wpdb->query(
					"DELETE FROM {$wpdb->prefix}icl_translation_status WHERE translation_id IN (" . wpml_prepare_in( $translation_ids, '%d' ) . ')'
				);

				if ( $rids ) {
					$job_ids = $wpdb->get_col(
						"SELECT job_id FROM {$wpdb->prefix}icl_translate_job WHERE rid IN (" . wpml_prepare_in( $rids, '%d' ) . ')'
					);
					$wpdb->query(
						"DELETE FROM {$wpdb->prefix}icl_translate_job WHERE rid IN (" . wpml_prepare_in( $rids, '%d' ) . ')'
					);
					if ( $job_ids ) {
						$wpdb->query(
							"DELETE FROM {$wpdb->prefix}icl_translate WHERE job_id IN (" . wpml_prepare_in( $job_ids, '%d' ) . ')'
						);
					}
				}
			}
		}
	}


	function post_md5( $post ) {

		return apply_filters( 'wpml_tm_element_md5', $post );
	}

	function get_element_translation( $element_id, $language, $element_type = 'post_post' ) {
		global $wpdb, $sitepress;
		$trid        = $sitepress->get_element_trid( $element_id, $element_type );
		$translation = array();
		if ( $trid ) {
			$translation = $wpdb->get_row(
				$wpdb->prepare(
					"
				SELECT *
				FROM {$wpdb->prefix}icl_translations tr
				JOIN {$wpdb->prefix}icl_translation_status ts ON tr.translation_id = ts.translation_id
				WHERE tr.trid=%d AND tr.language_code= %s
			",
					$trid,
					$language
				)
			);
		}

		return $translation;
	}

	function get_element_translations( $element_id, $element_type = 'post_post', $service = false ) {
		global $wpdb, $sitepress;
		$trid         = $sitepress->get_element_trid( $element_id, $element_type );
		$translations = array();
		if ( $trid ) {
			$service      = $service ? $wpdb->prepare( ' AND translation_service = %s ', $service ) : '';
			$translations = $wpdb->get_results(
				$wpdb->prepare(
					"
				SELECT *
				FROM {$wpdb->prefix}icl_translations tr
				JOIN {$wpdb->prefix}icl_translation_status ts ON tr.translation_id = ts.translation_id
				WHERE tr.trid=%d {$service}
			",
					$trid
				)
			);
			foreach ( $translations as $k => $v ) {
				$translations[ $v->language_code ] = $v;
				unset( $translations[ $k ] );
			}
		}

		return $translations;
	}

	public function status2icon_class( $status, $needs_update = 0, $needs_review = false ) {
		if ( $needs_update ) {
			$icon_class = 'otgs-ico-needs-update';
		} elseif ( $needs_review ) {
			$icon_class = 'otgs-ico-needs-review';
		} else {
			switch ( $status ) {
				case ICL_TM_NOT_TRANSLATED:
					$icon_class = 'otgs-ico-not-translated';
					break;
				case ICL_TM_WAITING_FOR_TRANSLATOR:
					$icon_class = 'otgs-ico-waiting';
					break;
				case ICL_TM_IN_PROGRESS:
				case ICL_TM_TRANSLATION_READY_TO_DOWNLOAD:
				case ICL_TM_ATE_NEEDS_RETRY:
					$icon_class = 'otgs-ico-in-progress';
					break;
				case ICL_TM_IN_BASKET:
					$icon_class = 'otgs-ico-basket';
					break;
				case ICL_TM_NEEDS_UPDATE:
					$icon_class = 'otgs-ico-needs-update';
					break;
				case ICL_TM_DUPLICATE:
					$icon_class = 'otgs-ico-duplicate';
					break;
				case ICL_TM_COMPLETE:
					$icon_class = 'otgs-ico-translated';
					break;
				default:
					$icon_class = 'otgs-ico-not-translated';
			}
		}

		return $icon_class;
	}

	public static function status2text( $status ) {
		switch ( $status ) {
			case ICL_TM_NOT_TRANSLATED:
				$text = __( 'Not translated', 'sitepress' );
				break;
			case ICL_TM_WAITING_FOR_TRANSLATOR:
				$text = __( 'Waiting for translator', 'sitepress' );
				break;
			case ICL_TM_IN_PROGRESS:
				$text = __( 'In progress', 'sitepress' );
				break;
			case ICL_TM_NEEDS_UPDATE:
				$text = __( 'Needs update', 'sitepress' );
				break;
			case ICL_TM_DUPLICATE:
				$text = __( 'Duplicate', 'sitepress' );
				break;
			case ICL_TM_COMPLETE:
				$text = __( 'Complete', 'sitepress' );
				break;
			case ICL_TM_TRANSLATION_READY_TO_DOWNLOAD:
				$text = __( 'Translation ready to download', 'sitepress' );
				break;
			case ICL_TM_ATE_NEEDS_RETRY:
				$text = __( 'In progress - needs retry', 'sitepress' );
				break;
			default:
				$text = '';
		}

		return $text;
	}

	public function decode_field_data( $data, $format ) {
		if ( $format == 'base64' ) {
			$data = base64_decode( $data );
		} elseif ( $format == 'csv_base64' ) {
			$exp = explode( ',', $data );
			foreach ( $exp as $k => $e ) {
				$exp[ $k ] = base64_decode( trim( $e, '"' ) );
			}
			$data = $exp;
		}

		return $data;
	}

	function create_translation_package( $post ) {
		$wpmlElementTranslationPackage = make( WPML_Element_Translation_Package::class );
		return $wpmlElementTranslationPackage->create_translation_package( $post, true ) ?: false;
	}

	function messages_by_type( $type ) {
		$messages = $this->messages;

		$result = [];
		foreach ( $messages as $message ) {
			if ( $type === false || ( ! empty( $message['type'] ) && $message['type'] == $type ) ) {
				$result[] = $message;
			}
		}

		return $result ?: false;
	}

	public function get_sent_job_ids(): array {
		return array_reduce(
			$this->sent_job_ids_per_type,
			function ( $carry, $item ) {
				return array_merge( $carry, $item );
			},
			[]
		);
	}

	public function add_basket_message( $type, $message, $id = null ) {
		$message = array(
			'type' => $type,
			'text' => $message,
		);
		if ( $id ) {
			$message['id'] = $id;
		}

		$this->add_message( $message );
	}

	function add_message( $message ) {
		$this->messages[] = $message;
		$this->messages   = array_unique( $this->messages, SORT_REGULAR );
	}

	function update_translation_status( $data, $rid = null ) {
		global $wpdb;
		if ( ! isset( $data['translation_id'] ) ) {
			JobLog::add( 'Cannot find translation_id in update_translation_status' );
			return array( false, false );
		}

		if ( ! $rid ) {
			$rid = $this->get_rid_from_translation_id( $data['translation_id'] );
			JobLog::add( 'update_translation_status: found rid from translation_id `' . $data['translation_id'] . '`' );
		} else {
			JobLog::add( 'update_translation_status: using existing rid `' . $rid . '`' );
		}

		$valid_columns = [
			'translation_id',
			'status',
			'translator_id',
			'needs_update',
			'md5',
			'translation_service',
			'batch_id',
			'translation_package',
			'timestamp',
			'links_fixed',
			'_prevstate',
			'uuid',
			'tp_id',
			'tp_revision',
			'ts_status',
			'review_status',
			'ate_comm_retry_count'
		];

		$filtered_data = array_intersect_key( $data, array_flip( $valid_columns ) );

		$update = (bool) $rid;
		if ( true === $update ) {
			$data_where = array( 'rid' => $rid );
			$wpdb->update( $wpdb->prefix . 'icl_translation_status', $filtered_data, $data_where );
			JobLog::add(
				'update_translation_status: updated existing translation status with rid `' . $rid . '`',
				$filtered_data
			);
		} else {
			$wpdb->insert( $wpdb->prefix . 'icl_translation_status', $filtered_data );
			$rid = $wpdb->insert_id;
			JobLog::add(
				'update_translation_status: created new translation status record with rid `' . $rid . '`',
				$filtered_data
			);
		}
		$data['rid'] = $rid;

		do_action( 'wpml_updated_translation_status', $data );

		return array( $rid, $update );
	}

	private function get_rid_from_translation_id( $translation_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rid
				FROM {$wpdb->prefix}icl_translation_status
				WHERE translation_id = %d",
				$translation_id
			)
		);
	}


	public function action_send_jobs( \WPML_TM_Translation_Batch $batch, $type = 'post', $sendFrom = null ) {
		$this->send_jobs( $batch, $type, $sendFrom );
	}

	function send_jobs( \WPML_TM_Translation_Batch $batch, $type = 'post', $sendFrom = null ) {
		global $sitepress;

		JobLog::maybeInitRequest();
		JobLog::createNewGroup(
			JobLog::GROUP_ID_SEND_JOBS,
			'Sending jobs to translations for type `' . $type . '`',
			[
				'type'     => $type,
				'sendFrom' => $sendFrom,
				'batch'    => $batch->toArray(),
			]
		);
		JobLog::addExtraLogData( 'type', $type );

		$this->sent_job_ids_per_type[ $type ] = [];
		$job_ids                              = array();
		$added_jobs                           = array();
		$batch_id                             = TranslationProxy_Batch::update_translation_batch( $batch->get_basket_name() );

		$batch = apply_filters( 'wpml_send_jobs_batch', $batch );

		foreach ( $batch->get_elements_by_type( $type ) as $element ) {
			$elementId = JobLog::safeCall( $element, 'get_element_id' );
			$post      = $elementId !== null ? $this->get_post( $elementId, $type ) : null;
			if ( ! $post ) {
				JobLog::add( 'Translatable element not found for id `' . (string) $elementId . '`' );
				continue;
			}

			JobLog::addExtraLogData( 'element_id', $elementId );
			JobLog::add( 'Translatable element found' );

			$wpmlElementTranslationPackage = make( WPML_Element_Translation_Package::class );
			$wpmlElementTranslationPackage->do_action_before_creating_translation_package( $post );

			$element_type        = $type . '_' . $post->post_type;
			$post_trid           = $sitepress->get_element_trid( $element->get_element_id(), $element_type );
			$post_translations   = $sitepress->get_element_translations( $post_trid, $element_type );

			foreach ( (array) $post_translations as $rowLang => $row ) {
				if ( is_object( $row ) && empty( $row->element_id ) ) {
					JobLog::add( 'broken_translation_row_missing_element_id', [
						'trid'            => $post_trid,
						'broken_language' => $rowLang,
						'translation_id'  => isset( $row->translation_id ) ? (int) $row->translation_id : null,
						'row_language'    => isset( $row->language_code ) ? $row->language_code : null,
					] );
				}
			}

			$md5                 = $this->post_md5( $post );

			JobLog::addExtraLogData( 'element_type', $element_type );
			JobLog::add(
				'Selected translatable element data',
				[
					'trid'         => $post_trid,
					'md5'          => $md5,
					'translations' => $post_translations,
				]
			);

			$translation_package = $wpmlElementTranslationPackage->create_translation_package( $post, true ) ?: false;
			JobLog::add(
				'Translation package created',
				$translation_package
			);

			foreach ( $element->get_target_langs() as $lang => $action ) {
				JobLog::addExtraLogData( 'target_lang', $lang );

				if ( $action == self::DUPLICATE_ELEMENT_ACTION ) {
					JobLog::add( 'Duplicating post');
					$current_translation_status = $this->get_element_translation( $element->get_element_id(), $lang, $element_type );
					if ( $current_translation_status && $current_translation_status->status == ICL_TM_IN_PROGRESS ) {
						JobLog::add( 'Cannot duplicate post because job is in progress' );
						JobLog::removeExtraLogData( 'target_lang' );
						continue;
					}

					$job_ids[] = $this->make_duplicate( $element->get_element_id(), $lang );
					JobLog::add(
						'Post duplicated successfully',
						[
							'job_ids' => $job_ids,
						]
					);
				} elseif ( $action == self::TRANSLATE_ELEMENT_ACTION ) {
					JobLog::add( 'Translating post' );

					if ( empty( $post_translations[ $lang ] ) ) {
						$translation_id = $sitepress->set_element_language_details( null, $element_type, $post_trid, $lang, $element->get_source_lang() );
						JobLog::add(
							'Created entry with id `' . $translation_id . '` in icl_translations table',
							[
								'translation_id' => $translation_id,
								'element_type'   => $element_type,
								'trid'           => $post_trid,
								'lang'           => $lang,
								'source_lang'    => $element->get_source_lang(),
							]
						);
					} else {
						$translation_id = $post_translations[ $lang ]->translation_id;
						$sitepress->set_element_language_details( $post_translations[ $lang ]->element_id, $element_type, $post_trid, $lang, $element->get_source_lang() );
						JobLog::add(
							'Updated entry with id `' . $translation_id . '` in icl_translations table',
							[
								'translation_id' => $translation_id,
								'element_type'   => $element_type,
								'trid'           => $post_trid,
								'lang'           => $lang,
								'source_lang'    => $element->get_source_lang(),
							]
						);
					}

					$current_translation_status = $this->get_element_translation( $element->get_element_id(), $lang, $element_type );
					JobLog::add(
						'Current translation status',
						[
							'current_translation_status' => $current_translation_status,
						]
					);

					if (
						$current_translation_status && (
							$current_translation_status->status == ICL_TM_IN_PROGRESS ||
							$current_translation_status->status == ICL_TM_WAITING_FOR_TRANSLATOR
						)
					) {
						$this->cancel_translation_request( $translation_id, false );
						JobLog::add(
							'Translation request canceled',
							[
								'current_translation_status' => $current_translation_status,
							]
						);
					}

					$_status = ICL_TM_WAITING_FOR_TRANSLATOR;

					$translator       = $batch->get_translator( $lang );
					$translation_data = TranslationProxy_Service::get_translator_data_from_wpml( $translator );
					$translator_id    = $sendFrom === Jobs::SENT_AUTOMATICALLY ? 0 : $translation_data['translator_id'];

					$translation_service = $translation_data['translation_service'];

					$data = array(
						'translation_id'      => $translation_id,
						'status'              => $_status,
						'translator_id'       => $translator_id,
						'needs_update'        => 0,
						'md5'                 => $md5,
						'translation_service' => $translation_service,
						'batch_id'            => $batch_id,
						'uuid'                => $this->get_uuid( $current_translation_status, $post ),
						'ts_status'           => null,
						'timestamp'           => date( 'Y-m-d H:i:s', time() ),
						'links_fixed'         => true,
					);

					$backup_translation_status = $this->get_translation_status_data( $translation_id );
					$rid = isset( $backup_translation_status['rid'] )
						? $backup_translation_status['rid'] : null;
					if ( $rid ) {
						JobLog::add( 'Found backup translation status `' . $rid . '`' );
					}

					list( $rid ) = $this->update_translation_status( $data, $rid );
					$this->maybe_update_prev_state( $translation_id, $backup_translation_status );

					if ( $translation_package ) {
						$translation_package = $wpmlElementTranslationPackage->filter_translation_package_for_lang(
							$translation_package,
							$post,
							$lang
						);

						$priorInFlightJob = $rid ? $this->findPriorInFlightJobForRid( $rid ) : null;

						$job_id = wpml_tm_add_translation_job( $rid, $translator_id, $translation_package, $batch->get_batch_options(), $sendFrom, true );
						wpml_tm_load_job_factory()->update_job_data( $job_id, array( 'editor' => WPML_TM_Editors::NONE ) );

						if ( $priorInFlightJob && (int) $priorInFlightJob['job_id'] !== (int) $job_id ) {
							JobLog::addError( 'translation_job_superseded', [
								'new_job_id'              => (int) $job_id,
								'old_job_id'              => (int) $priorInFlightJob['job_id'],
								'rid'                     => (int) $rid,
								'target_lang'             => $lang,
								'old_status'              => isset( $priorInFlightJob['status'] ) ? (int) $priorInFlightJob['status'] : null,
								'old_translated_flag'     => isset( $priorInFlightJob['translated'] ) ? (int) $priorInFlightJob['translated'] : null,
								'old_job_age_seconds'     => isset( $priorInFlightJob['age_seconds'] ) ? (int) $priorInFlightJob['age_seconds'] : null,
							] );
						}

						if ( $translation_service !== 'local' ) {
							global $ICL_Pro_Translation;

							$tp_job_id = $ICL_Pro_Translation->send_post( $post, array( $lang ), $translator_id, $job_id, $batch->getTpBatchInfo() );

							if ( $tp_job_id ) {
								$this->update_translation_status(
									array(
										'translation_id' => $translation_id,
										'tp_id'          => $tp_job_id,
									),
									$rid
								);
								JobLog::add(
									'Updated translation status for `' . $rid . '`',
									[
										'tp_id'          => $tp_job_id,
										'translation_id' => $translation_id,
									]
								);

								$job_ids[]                            = $job_id;
								$added_jobs[ $translation_service ][] = $job_id;
							} else {
								$this->revert_job_when_tp_job_could_not_be_created( $job_id, $rid, $data['translation_id'], $backup_translation_status );
							}
						} else {
							$job_ids[]                            = $job_id;
							$added_jobs[ $translation_service ][] = $job_id;
						}
					}
				}

				do_action( 'wpml_tm_added_translation_element', $element, $post );

				JobLog::removeExtraLogData( 'target_lang' );
			}

			JobLog::removeExtraLogData( 'element_id' );
			JobLog::removeExtraLogData( 'element_type' );
		}

		do_action( 'wpml_added_translation_jobs', $added_jobs, $sendFrom, $batch );

		icl_cache_clear();
		do_action( 'wpml_tm_empty_mail_queue' );

		$this->sent_job_ids_per_type[ $type ] = $job_ids;

		JobLog::removeExtraLogData( 'type' );
		JobLog::finishCurrentGroup();

		return $job_ids;
	}

	private function revert_job_when_tp_job_could_not_be_created(
		$jobId,
		$rid,
		$translationId,
		$backup_translation_status
	) {
		global $wpdb, $ICL_Pro_Translation;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $jobId ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}icl_translate_job SET revision = NULL WHERE rid=%d ORDER BY job_id DESC LIMIT 1", $rid ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translate WHERE job_id=%d", $jobId ) );
		if ( $backup_translation_status ) {
			$wpdb->update( "{$wpdb->prefix}icl_translation_status", $backup_translation_status, [ 'translation_id' => $translationId ] );
			JobLog::add(
				'Reverted job with backup translation status when translation proxy job could not be created for job id `' . $jobId . '` and rid `' . $rid . '`',
				[
					'translation_id'            => $translationId,
					'backup_translation_status' => $backup_translation_status,
				]
			);
		} else {
			$wpdb->delete( "{$wpdb->prefix}icl_translation_status", [ 'translation_id' => $translationId ] );
			JobLog::add(
				'Reverted job with removing translation status when translation proxy job could not be created for job id `' . $jobId . '` and rid `' . $rid . '`',
				[
					'translation_id' => $translationId,
				]
			);
		}
		foreach ( $ICL_Pro_Translation->errors as $error ) {
			if ( $error instanceof Exception ) {
				$message = [
					'type' => 'error',
					'text' => $error->getMessage(),
				];
				$this->add_message( $message );
			}
		}
	}

	private function get_uuid( $current_translation_status, $post ) {
		if ( ! empty( $current_translation_status->uuid ) ) {
			return $current_translation_status->uuid;
		} else {
			return wpml_uuid( $post->ID, $post->post_type );
		}
	}

	private function get_translation_status_data( $translation_id ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT *
													FROM {$wpdb->prefix}icl_translation_status
													WHERE translation_id = %d",
			$translation_id
		);

		$data = $wpdb->get_results( $sql, ARRAY_A );

		return isset( $data[0] ) ? $data[0] : array();
	}

	function get_translation_jobs( $args = array() ) {

		return apply_filters( 'wpml_translation_jobs', array(), $args );
	}

	function add_translation_job( $rid, $translator_id, $translation_package, $batch_options = array(), $sendFrom = null ) {
		return wpml_tm_add_translation_job( $rid, $translator_id, $translation_package, $batch_options, $sendFrom );
	}

	function cleanup_translation_jobs_cart_posts( $posts ) {
		if ( empty( $posts ) ) {
			return;
		}

		foreach ( $posts as $post_id => $post_data ) {
			if ( ! get_post( $post_id ) ) {
				TranslationProxy_Basket::delete_item_from_basket( $post_id );
			}
		}
	}

	function get_translation_jobs_basket_posts( $posts ) {
		if ( empty( $posts ) ) {
			return false;
		}

		$this->cleanup_translation_jobs_cart_posts( $posts );

		global $sitepress;

		$posts_ids = array_keys( $posts );

		$args = array(
			'posts_per_page' => -1,
			'include'        => $posts_ids,
			'post_type'      => get_post_types(),
			'post_status'    => get_post_stati(),
		);

		$new_posts = get_posts( $args );

		$final_posts = array();

		foreach ( $new_posts as $post_data ) {
			$final_posts[ $post_data->ID ] = [];
			$final_posts[ $post_data->ID ]['post_title'] = $post_data->post_title;
			$final_posts[ $post_data->ID ]['post_date'] = $post_data->post_date;
			$final_posts[ $post_data->ID ]['post_notes'] = get_post_meta( $post_data->ID, '_icl_translator_note', true );

			$final_posts[ $post_data->ID ]['post_type'] = $post_data->post_type;
			$final_posts[ $post_data->ID ]['post_status'] = $post_data->post_status;
			$final_posts[ $post_data->ID ]['from_lang']        = $posts[ $post_data->ID ]['from_lang'];
			$final_posts[ $post_data->ID ]['from_lang_string'] = ucfirst( $sitepress->get_display_language_name( $posts[ $post_data->ID ]['from_lang'], $sitepress->get_admin_language() ) );
			$final_posts[ $post_data->ID ]['to_langs'] = $posts[ $post_data->ID ]['to_langs'];
			$language_names = array();
			foreach ( $final_posts[ $post_data->ID ]['to_langs'] as $language_code => $value ) {
				$language_names[] = ucfirst( $sitepress->get_display_language_name( $language_code, $sitepress->get_admin_language() ) );
			}
			$final_posts[ $post_data->ID ]['to_langs_string'] = implode( ', ', $language_names );
			$final_posts[ $post_data->ID ]['auto_added']      = isset( $posts[ $post_data->ID ]['auto_added'] ) && $posts[ $post_data->ID ]['auto_added'];
		}

		return $final_posts;
	}

	function get_translation_jobs_basket_strings( $strings, $source_language = false ) {
		$final_strings = array();
		if ( class_exists( 'WPML_String_Translation' ) ) {
			global $sitepress;

			$source_language = $source_language ? $source_language : TranslationProxy_Basket::get_source_language();
			foreach ( $strings as $string_id => $data ) {
				if ( $source_language ) {
					$final_strings[ $string_id ] = [];
					$final_strings[ $string_id ]['post_title'] = icl_get_string_by_id( $string_id ) ?: '';
					$final_strings[ $string_id ]['post_type'] = 'string';
					$final_strings[ $string_id ]['from_lang']        = $source_language;
					$final_strings[ $string_id ]['from_lang_string'] = ucfirst( $sitepress->get_display_language_name( $source_language, $sitepress->get_admin_language() ) );
					$final_strings[ $string_id ]['to_langs'] = $data['to_langs'];
					$language_names = array();
					foreach ( $final_strings[ $string_id ]['to_langs'] as $language_code => $value ) {
						$language_names[] = ucfirst( $sitepress->get_display_language_name( $language_code, $sitepress->get_admin_language() ) );
					}
					$final_strings[ $string_id ]['to_langs_string'] = implode( ', ', $language_names );
				}
			}
		}

		return $final_strings;
	}

	function get_translation_job( $job_id, $include_non_translatable_elements = false, $auto_assign = false, $revisions = 0 ) {
		return apply_filters( 'wpml_get_translation_job', $job_id, $include_non_translatable_elements, $revisions );
	}

	function get_translation_job_id_filter( $empty, $args ) {
		if ( ! is_array( $args ) || ! isset( $args['trid'], $args['language_code'] ) ) {
			return $empty;
		}

		$trid          = $args['trid'];
		$language_code = $args['language_code'];

		return $this->get_translation_job_id( $trid, $language_code );
	}

	private function get_translation_job_info( $trid ) {
		global $wpdb;

		if ( ! $trid ) {
			return [];
		}

		$found    = false;
		$cache    = $this->cache_factory->get( 'TranslationManagement::get_translation_job_id' );
		$job_info = $cache->get( $trid, $found );
		if ( ! $found ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tj.job_id, tj.editor, t.language_code FROM {$wpdb->prefix}icl_translate_job tj
					JOIN {$wpdb->prefix}icl_translation_status ts ON tj.rid = ts.rid
					JOIN {$wpdb->prefix}icl_translations t ON ts.translation_id = t.translation_id
					WHERE t.trid = %d
					ORDER BY tj.job_id DESC",
					$trid
				)
			);

			$job_info = array();
			foreach ( $results as $result ) {
				if ( ! isset( $job_info[ $result->language_code ] ) ) {
					$job_info[ $result->language_code ] = [
						'job_id' => $result->job_id,
						'editor' => $result->editor,
					];
				}
			}
			$cache->set( $trid, $job_info );
		}

		return $job_info;
	}

	public function get_translation_job_id( $trid, $language_code ) {
		$job_info = $this->get_translation_job_info( $trid );

		return ( null !== $language_code && isset( $job_info[ $language_code ] ) ) ? $job_info[ $language_code ]['job_id'] : null;
	}

	public function get_translation_job_editor( $trid, $language_code ) {
		$job_info = $this->get_translation_job_info( $trid );

		return isset( $job_info[ $language_code ] ) ? $job_info[ $language_code ]['editor'] : null;
	}

	function save_translation( $data ) {
		do_action( 'wpml_save_translation_data', $data );
	}

	function save_job_fields_from_post( $job_id ) {
		do_action( 'wpml_save_job_fields_from_post', $job_id );
	}

	function mark_job_done( $job_id ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'icl_translate_job', array( 'translated' => 1 ), array( 'job_id' => $job_id ) );
		$wpdb->update( $wpdb->prefix . 'icl_translate', array( 'field_finished' => 1 ), array( 'job_id' => $job_id ) );
		do_action( 'wpml_tm_empty_mail_queue' );
	}

	function resign_translator( $job_id, $skip_notification = false ) {
		global $wpdb;
		list( $translator_id, $rid ) = $wpdb->get_row( $wpdb->prepare( "SELECT translator_id, rid FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $job_id ), ARRAY_N );
		if ( ! $skip_notification && ! empty( $translator_id ) && $this->settings['notification']['resigned'] != ICL_TM_NOTIFICATION_NONE && $job_id ) {
			do_action( 'wpml_tm_resign_job_notification', $translator_id, $job_id );
		}
		$wpdb->update( $wpdb->prefix . 'icl_translate_job', array( 'translator_id' => 0 ), array( 'job_id' => $job_id ) );
		$wpdb->update(
			$wpdb->prefix . 'icl_translation_status',
			array(
				'translator_id' => 0,
				'status'        => ICL_TM_WAITING_FOR_TRANSLATOR,
			),
			array( 'rid' => $rid )
		);
	}

	public function resign_translator_from_unfinished_jobs( WP_User $translator ) {
		global $wpdb;

		$unfinished_job_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT job_id
				FROM {$wpdb->prefix}icl_translate_job
				WHERE translator_id = %d AND translated = 0",
				$translator->ID
			)
		);

		$remove_job_without_notification = partialRight( [ $this, 'resign_translator' ], true );

		array_map( $remove_job_without_notification, $unfinished_job_ids );
	}

	function remove_translation_job( $job_id, $new_translation_status = ICL_TM_WAITING_FOR_TRANSLATOR, $new_translator_id = 0 ) {
		global $wpdb;

		$error = false;

		list( $prev_translator_id, $rid ) = $wpdb->get_row( $wpdb->prepare( "SELECT translator_id, rid FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $job_id ), ARRAY_N );

		$wpdb->update( $wpdb->prefix . 'icl_translate_job', array( 'translator_id' => $new_translator_id ), array( 'job_id' => $job_id ) );
		$wpdb->update(
			$wpdb->prefix . 'icl_translate',
			array(
				'field_data_translated' => '',
				'field_finished'        => 0,
			),
			array( 'job_id' => $job_id )
		);

		if ( $rid ) {
			$data       = array(
				'status'        => $new_translation_status,
				'translator_id' => $new_translator_id,
			);
			$data_where = array( 'rid' => $rid );
			$wpdb->update( $wpdb->prefix . 'icl_translation_status', $data, $data_where );

			if ( $this->settings['notification']['resigned'] == ICL_TM_NOTIFICATION_IMMEDIATELY && ! empty( $prev_translator_id ) ) {
				do_action( 'wpml_tm_remove_job_notification', $prev_translator_id, $job_id );
			}
		} else {
			$error = sprintf( __( 'Translation entry not found for: %d', 'sitepress' ), $job_id );
		}

		return $error;
	}

	function cancel_translation_request( $translation_id, $remove_translation_record = true ) {
		global $wpdb;

		if ( is_array( $translation_id ) ) {
			foreach ( $translation_id as $id ) {
				$this->cancel_translation_request( $id );
			}
		} else {
			list( $rid, $translator_id, $status_before ) = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT rid, translator_id, status
                     FROM {$wpdb->prefix}icl_translation_status
                     WHERE translation_id=%d
                       AND ( status = %d OR status = %d )",
					$translation_id,
					ICL_TM_WAITING_FOR_TRANSLATOR,
					ICL_TM_IN_PROGRESS
				),
				ARRAY_N
			);
			if ( ! $rid ) {
				JobLog::add( 'cancel_request_no_active_status', [
					'translation_id' => $translation_id,
				] );
				return;
			}
			$job_id = $wpdb->get_var( $wpdb->prepare( "SELECT job_id FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d AND revision IS NULL ", $rid ) );

			JobLog::add( 'cancel_request_started', [
				'translation_id'            => $translation_id,
				'rid'                       => $rid,
				'job_id'                    => $job_id,
				'translator_id'             => $translator_id,
				'status_before'             => isset( $status_before ) ? (int) $status_before : null,
				'remove_translation_record' => $remove_translation_record,
			] );

			if ( isset( $this->settings['notification']['resigned'] )
			     && $this->settings['notification']['resigned'] == ICL_TM_NOTIFICATION_IMMEDIATELY && ! empty( $translator_id ) ) {
				do_action( 'wpml_tm_remove_job_notification', $translator_id, $job_id );
			}

			$jobDeletedRows       = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $job_id ) );
			$translateDeletedRows = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translate WHERE job_id=%d", $job_id ) );

			$max_job_id = \WPML\TM\API\Job\Map::fromRid( $rid );
			if ( $max_job_id ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}icl_translate_job SET revision = NULL WHERE job_id=%d", $max_job_id ) );
				\WPML\Translation\PreviousStateServiceFactory::create()->revertToPreviousState( $translation_id );

				JobLog::add( 'cancel_request_reverted_to_previous_job', [
					'rid'                          => $rid,
					'job_id'                       => $job_id,
					'max_job_id'                   => $max_job_id,
					'job_table_deleted_rows'       => is_int( $jobDeletedRows ) ? $jobDeletedRows : null,
					'translate_table_deleted_rows' => is_int( $translateDeletedRows ) ? $translateDeletedRows : null,
				] );
			} else {
				$statusDeletedRows = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translation_status WHERE translation_id=%d", $translation_id ) );

				JobLog::add( 'cancel_request_deleted_status', [
					'translation_id'                => $translation_id,
					'rid'                           => $rid,
					'job_id'                        => $job_id,
					'status_table_deleted_rows'     => is_int( $statusDeletedRows ) ? $statusDeletedRows : null,
					'job_table_deleted_rows'        => is_int( $jobDeletedRows ) ? $jobDeletedRows : null,
					'translate_table_deleted_rows'  => is_int( $translateDeletedRows ) ? $translateDeletedRows : null,
				] );
			}

			if ( $remove_translation_record ) {
				$translationsDeleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}icl_translations WHERE translation_id=%d AND element_id IS NULL", $translation_id ) );
				JobLog::add( 'cancel_request_removed_translation_record', [
					'translation_id'              => $translation_id,
					'icl_translations_deleted'    => is_int( $translationsDeleted ) ? $translationsDeleted : null,
				] );
			}

			icl_cache_clear();
		}
	}

	function render_option_writes( $name, $value, $key = '' ) {
		if ( ! defined( 'WPML_ST_FOLDER' ) ) {
			return;
		}
		static $option = false;

		if ( ! $key ) {
			$option = maybe_unserialize( get_option( $name ) );
			if ( is_object( $option ) ) {
				$option = (array) $option;
			}
		}

		$admin_option_names = get_option( '_icl_admin_option_names' );

		$es_context = '';

		$context = '';
		$slug    = '';
		foreach ( $admin_option_names as $context => $element ) {
			$found = false;
			foreach ( (array) $element as $slug => $options ) {
				$found = false;
				foreach ( (array) $options as $option_key => $option_value ) {
					$found      = false;
					$es_context = '';
					if ( $option_key == $name ) {
						if ( is_scalar( $option_value ) ) {
							$es_context = 'admin_texts_' . $context . '_' . $slug;
							$found      = true;
						} elseif ( is_array( $option_value ) && is_array( $value ) && ( $option_value == $value ) ) {
							$es_context = 'admin_texts_' . $context . '_' . $slug;
							$found      = true;
						}
					}
					if ( $found ) {
						break;
					}
				}
				if ( $found ) {
					break;
				}
			}
			if ( $found ) {
				break;
			}
		}

		echo '<ul class="icl_tm_admin_options">';
		echo '<li>';

		$context_html = '';
		if ( ! $key ) {
			$context_html = '[' . esc_html( $context ) . ': ' . esc_html( $slug ) . '] ';
		}

		if ( is_scalar( $value ) ) {
			preg_match_all( '#\[([^\]]+)\]#', $key, $matches );

			if ( count( $matches[1] ) > 1 ) {
				$o_value = $option;
				for ( $i = 1; $i < count( $matches[1] ); $i++ ) {
					$o_value = $o_value[ $matches[1][ $i ] ];
				}
				$o_value   = $o_value[ $name ];
				$edit_link = '';
			} else {
				if ( is_scalar( $option ) ) {
					$o_value = $option;
				} elseif ( isset( $option[ $name ] ) ) {
					$o_value = $option[ $name ];
				} else {
					$o_value = '';
				}

				if ( ! $key ) {
					if ( icl_st_is_registered_string( $es_context, $name ) ) {
						$edit_link = '[<a href="' . admin_url( 'admin.php?page=' . WPML_ST_FOLDER . '/menu/string-translation.php&context=' . esc_html( $es_context ) ) . '">' . esc_html__( 'translate', 'sitepress' ) . '</a>]';
					} else {
						$edit_link = '<div class="updated below-h2">' . esc_html__( 'string not registered', 'sitepress' ) . '</div>';
					}
				} else {
					$edit_link = '';
				}
			}

			if ( false !== strpos( $name, '*' ) ) {
				$o_value = '<span style="color:#bbb">{{ ' . esc_html__( 'Multiple options', 'sitepress' ) . ' }}</span>';
			} else {
				$o_value = esc_html( $o_value );
				if ( strlen( $o_value ) > 200 ) {
					$o_value = substr( $o_value, 0, 200 ) . ' ...';
				}
			}
			echo $context_html . esc_html( $name ) . ': <i>' . $o_value . '</i> ' . $edit_link;
		} else {
			$edit_link = '[<a href="' . admin_url( 'admin.php?page=' . WPML_ST_FOLDER . '/menu/string-translation.php&context=' . esc_html( $es_context ) ) . '">' . esc_html__( 'translate', 'sitepress' ) . '</a>]';
			echo '<strong>' . $context_html . $name . '</strong> ' . $edit_link;
			if ( ! icl_st_is_registered_string( $es_context, $name ) ) {
				$notice = '<div class="updated below-h2">' . esc_html__( 'some strings might be not registered', 'sitepress' ) . '</div>';
				echo $notice;
			}

			foreach ( (array) $value as $o_key => $o_value ) {
				$this->render_option_writes( $o_key, $o_value, $o_key . '[' . $name . ']' );
			}

			$option = false;
		}
		echo '</li>';
		echo '</ul>';
	}

	public static function current_service_info( $info = array() ) {
		return TranslationProxy::get_current_service_info( $info );
	}

	static function set_page_url( $post_id ) {

		global $wpdb;

		if ( wpml_get_setting_filter( false, 'translated_document_page_url' ) === 'copy-encoded' ) {

			$post            = $wpdb->get_row( $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $post_id ) );
			$translation_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s", $post_id, 'post_' . $post->post_type ) );

			$encode_url = $wpdb->get_var( $wpdb->prepare( "SELECT encode_url FROM {$wpdb->prefix}icl_languages WHERE code=%s", $translation_row->language_code ) );

			if ( $encode_url ) {

				$trid             = $translation_row->trid;
				$original_post_id = $wpdb->get_var( $wpdb->prepare( "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND source_language_code IS NULL", $trid ) );
				$post_name_original      = get_post_field( 'post_name', $original_post_id );
				$post_parent_original    = get_post_field( 'post_parent', $original_post_id );
				$post_parent_translation = $post_parent_original
					? apply_filters( 'wpml_object_id', $post_parent_original, $post->post_type, false, $translation_row->language_code )
					: 0;

				$post_name_to_be = $post_name_original;
				$incr            = 1;
				do {
					$taken = $wpdb->get_var(
						$wpdb->prepare(
							"
						SELECT ID FROM {$wpdb->posts} p
						JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
						WHERE ID <> %d AND t.element_type = %s AND t.language_code = %s AND p.post_name = %s AND p.post_parent = %d
						",
							$post_id,
							'post_' . $post->post_type,
							$translation_row->language_code,
							$post_name_to_be,
							$post_parent_translation
						)
					);
					if ( $taken ) {
						$incr ++;
						$post_name_to_be = $post_name_original . '-' . $incr;
					} else {
						$taken = false;
					}
				} while ( $taken == true );
				$post_to_update = new WPML_WP_Post( $wpdb, $post_id );
				$post_to_update->update( array( 'post_name' => $post_name_to_be ), true );
			}
		}
	}

	public function icl_insert_post( $postarr, $lang ) {
		$create_post_helper = wpml_get_create_post_helper();

		return $create_post_helper->insert_post( $postarr, $lang );
	}

	private function add_missing_language_to_posts( $post_types ) {
		global $wpdb;

		$posts_prepared = "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_type IN ('" . implode( "', '", esc_sql( $post_types ) ) . "')";
		$posts          = $wpdb->get_results( $posts_prepared );
		if ( $posts ) {
			foreach ( $posts as $post ) {
				$this->add_missing_language_to_post( $post );
			}
		}
	}

	private function add_missing_language_to_post( $post ) {
		global $sitepress, $wpdb;

		$query_prepared = $wpdb->prepare( "SELECT translation_id, language_code FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d", array( 'post_' . $post->post_type, $post->ID ) );
		$query_results  = $wpdb->get_row( $query_prepared );

		if ( ! is_null( $query_results ) ) {
			$translation_id = $query_results->translation_id;
			$language_code  = $query_results->language_code;
		} else {
			$translation_id = null;
			$language_code  = null;
		}

		$urls             = $sitepress->get_setting( 'urls' );
		$is_root_page     = $urls && isset( $urls['root_page'] ) && $urls['root_page'] == $post->ID;
		$default_language = $sitepress->get_default_language();

		if ( ! $translation_id && ! $is_root_page && ! in_array( $post->post_status, array( 'auto-draft' ) ) ) {
			$sitepress->set_element_language_details( $post->ID, 'post_' . $post->post_type, null, $default_language, null, true, true );
		} elseif ( $translation_id && $is_root_page ) {
			$trid = $sitepress->get_element_trid( $post->ID, 'post_' . $post->post_type );
			if ( $trid ) {
				$sitepress->delete_element_translation( $trid, 'post_' . $post->post_type );
			}
		} elseif ( $translation_id && ! $language_code && $default_language ) {
			$where = array( 'translation_id' => $translation_id );
			$data  = array( 'language_code' => $default_language );
			$wpdb->update( $wpdb->prefix . 'icl_translations', $data, $where );

			do_action(
				'wpml_translation_update',
				array(
					'type'           => 'update',
					'element_id'     => $post->ID,
					'element_type'   => 'post_' . $post->post_type,
					'translation_id' => $translation_id,
					'context'        => 'post',
				)
			);
		}
	}

	private function add_missing_language_to_taxonomies( $post_types ) {
		global $sitepress, $wpdb;
		$taxonomy_types = array();
		foreach ( $post_types as $post_type ) {
			$taxonomy_types = array_merge( $sitepress->get_translatable_taxonomies( true, $post_type ), $taxonomy_types );
		}
		$taxonomy_types = array_unique( $taxonomy_types );
		$taxonomies     = $wpdb->get_results( "SELECT taxonomy, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN (" . wpml_prepare_in( $taxonomy_types ) . ')' );
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomy ) {
				$this->add_missing_language_to_taxonomy( $taxonomy );
			}
		}
	}

	private function add_missing_language_to_taxonomy( $taxonomy ) {
		global $sitepress, $wpdb;
		$tid_prepared = $wpdb->prepare( "SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d", 'tax_' . $taxonomy->taxonomy, $taxonomy->term_taxonomy_id );
		$tid          = $wpdb->get_var( $tid_prepared );
		if ( ! $tid ) {
			$sitepress->set_element_language_details( $taxonomy->term_taxonomy_id, 'tax_' . $taxonomy->taxonomy, null, $sitepress->get_default_language(), null, true, true );
		}
	}

	public function add_missing_language_information() {
		global $sitepress, $wpdb;
		$translatable_documents = array_keys( $sitepress->get_translatable_documents( false ) );
		$lock_name              = 'add_missing_language_information_lock';

		if ( $translatable_documents ) {
			$lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) );

			if ( $lock ) {
				$this->add_missing_language_to_posts( $translatable_documents );
				$this->add_missing_language_to_taxonomies( $translatable_documents );

				$wpdb->query( $wpdb->prepare( 'DO RELEASE_LOCK(%s)', $lock_name ) );

				return true;
			}

			return false;
		}

		return true;
	}

	public static function include_underscore_templates( $name ) {
		$dir_str = WPML_TM_PATH . '/res/js/' . $name . '/templates/';
		$dir     = opendir( $dir_str );
		if ( ! $dir ) {
			return;
		}
		while ( ( $currentFile = readdir( $dir ) ) !== false ) {
			if ( $currentFile == '.' || $currentFile == '..' || $currentFile[0] == '.' ) {
				continue;
			}

			include $dir_str . $currentFile;
		}
		closedir( $dir );
	}

	public static function get_job_status_string( $status_id, $needs_update = false ) {
		$job_status_text = self::status2text( $status_id );
		if ( $needs_update ) {
			$job_status_text .= __( ' - (needs update)', 'sitepress' );
		}

		return $job_status_text;
	}

	function display_basket_notification( $position ) {
		if ( class_exists( 'ICL_AdminNotifier' ) && class_exists( 'TranslationProxy_Basket' ) ) {
			$positions = TranslationProxy_Basket::get_basket_notification_positions();
			if ( isset( $positions[ $position ] ) ) {
				ICL_AdminNotifier::display_messages( 'translation-basket-notification' );
			}
		}
	}

	public function get_element_type( $trid ) {
		global $wpdb;
		$element_type_query   = "SELECT element_type FROM {$wpdb->prefix}icl_translations WHERE trid=%d LIMIT 0,1";
		$element_type_prepare = $wpdb->prepare( $element_type_query, $trid );

		return $wpdb->get_var( $element_type_prepare );
	}

	public function is_external_type( $type ) {
		return apply_filters( 'wpml_is_external', false, $type );
	}

	public function get_post( $post_id, $element_type_prefix ) {
		$item = null;
		if ( $this->is_external_type( $element_type_prefix ) ) {
			$item = apply_filters( 'wpml_get_translatable_item', null, $post_id, $element_type_prefix );
		}

		if ( ! $item ) {
			$item = get_post( $post_id );
		}

		return $item;
	}

	private function init_comments_synchronization() {
		if ( wpml_get_setting_filter( null, 'sync_comments_on_duplicates' ) ) {
			add_action( 'delete_comment', array( $this, 'duplication_delete_comment' ) );
			add_action( 'edit_comment', array( $this, 'duplication_edit_comment' ) );
			add_action( 'wp_set_comment_status', array( $this, 'duplication_status_comment' ), 10, 2 );
			add_action( 'wp_insert_comment', array( $this, 'duplication_insert_comment' ), 100 );
		}
	}

	private function init_default_settings() {
		if ( ! isset( $this->settings[ $this->get_translation_setting_name( 'custom-fields' ) ] ) ) {
			$this->settings[ $this->get_translation_setting_name( 'custom-fields' ) ] = array();
		}

		if ( ! isset( $this->settings[ $this->get_readonly_translation_setting_name( 'custom-fields' ) ] ) ) {
			$this->settings[ $this->get_readonly_translation_setting_name( 'custom-fields' ) ] = array();
		}

		if ( ! isset( $this->settings[ $this->get_custom_translation_setting_name( 'custom-fields' ) ] ) ) {
			$this->settings[ $this->get_custom_translation_setting_name( 'custom-fields' ) ] = array();
		}

		if ( ! isset( $this->settings[ $this->get_custom_readonly_translation_setting_name( 'custom-fields' ) ] ) ) {
			$this->settings[ $this->get_custom_readonly_translation_setting_name( 'custom-fields' ) ] = array();
		}

		if ( ! isset( $this->settings['doc_translation_method'] ) ) {
			$this->settings['doc_translation_method'] = ICL_TM_TMETHOD_MANUAL;
		}
	}

	public function init_current_translator() {
		if ( did_action( 'init' ) ) {
			global $current_user;
			$current_translator = null;
			$user               = false;
			if ( isset( $current_user->ID ) ) {
				$user = new WP_User( $current_user->ID );
			}

			if ( $user && isset( $user->data ) && $user->data ) {
				$current_translator               = new WPML_Translator();
				$current_translator->ID           = $current_user->ID;
				$current_translator->user_login   = isset( $user->data->user_login ) ? $user->data->user_login : false;
				$current_translator->display_name = isset( $user->data->display_name ) ? $user->data->display_name : $current_translator->user_login;
				$current_translator               = $this->init_translator_language_pairs( $current_user, $current_translator );
			}

			$this->current_translator = $current_translator;
		}
	}

	public function get_translation_setting_name( $section ) {
		return $this->get_sanitized_translation_setting_section( $section ) . '_translation';
	}

	public function get_custom_translation_setting_name( $section ) {
		return $this->get_translation_setting_name( $section ) . '_custom';
	}

	public function get_custom_readonly_translation_setting_name( $section ) {
		return $this->get_custom_translation_setting_name( $section ) . '_readonly';
	}

	public function get_readonly_translation_setting_name( $section ) {
		return $this->get_sanitized_translation_setting_section( $section ) . '_readonly_config';
	}

	private function get_sanitized_translation_setting_section( $section ) {
		$section = preg_replace( '/-/', '_', $section );
		return $section;
	}

	private function assign_translation_job( $job_id, $translator_id, $service = 'local', $type = 'post' ) {
		do_action( 'wpml_tm_assign_translation_job', $job_id, $translator_id, $service, $type );

		return true;
	}

	private function initial_translation_states( $table ) {
		global $wpdb;

		$custom_keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$table}" );

		return $custom_keys;
	}

	public function icl_tm_save_notification_settings( $data ) {
		if ( wp_verify_nonce(
			$data['save_notification_settings_nonce'],
			'save_notification_settings_nonce'
		)
		) {
			foreach (
				array(
					'new-job',
					'include_xliff',
					'job_limits',
					'resigned',
					'completed',
					'completed_frequency',
					'overdue',
					'overdue_offset',
				) as $setting
			) {
				if ( ! Obj::hasPath( [ 'notification', $setting ], $data ) ) {
					$data['notification'][ $setting ] = ICL_TM_NOTIFICATION_NONE;
				}
			}

			$this->settings['notification'] = $data['notification'];
			$this->save_settings();
			$message = array(
				'id'   => 'icl_tm_message_save_notification_settings',
				'type' => 'updated',
				'text' => __( 'Preferences saved.', 'sitepress' ),
			);
			ICL_AdminNotifier::add_message( $message );
			do_action( 'wpml_tm_notification_settings_saved', $this->settings['notification'] );
		}
	}

	public function get_init_priority() {
		return self::INIT_PRIORITY;
	}

	private function maybe_update_prev_state( int $translationId, array $translation_status_data ) {
		if ( $translation_status_data) {
			$updated = \WPML\Translation\PreviousStateServiceFactory::create()->update( $translationId, $translation_status_data );
			JobLog::add( 'Previous translation status was ' . ( $updated ? 'updated' : 'not updated' ) );
		}
	}

	private function is_unlocked_type( $type, $unlocked_options ) {
		return isset( $unlocked_options[ $type ] ) && $unlocked_options[ $type ];
	}

	private function capture_translation_editor_switched_event( $previous_editor, $new_editor ) {
		if ( ! \WPML\PostHog\State\PostHogState::isEnabled() ) {
			return;
		}

		$get_editor_name = function( $editor_value ) {
			if ( (string) $editor_value === ICL_TM_TMETHOD_ATE ) {
				return 'ATE';
			}
			return 'CTE';
		};

		$event_props = array(
			'previous_editor' => $get_editor_name( $previous_editor ),
			'new_editor'      => $get_editor_name( $new_editor ),
		);

		\WPML\PostHog\Event\CaptureEvent::capture(
			( new EventInstanceService() )->getTranslationEditorSwitchedEvent( $event_props )
		);
	}


	private function triggerCdtStatsResend() {
		$editorSwitchService = \WPML\ContentStats\EditorSwitchServiceFactory::create();
		$editorSwitchService->handleEditorSwitch();
	}

	private function findPriorInFlightJobForRid( $rid ) {
		if ( ! class_exists( JobLog::class ) || ! JobLog::canLog() ) {
			return null;
		}

		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT j.job_id,
			        j.translated,
			        s.status,
			        UNIX_TIMESTAMP() - UNIX_TIMESTAMP(s.timestamp) AS age_seconds
			 FROM {$wpdb->prefix}icl_translate_job j
			 LEFT JOIN {$wpdb->prefix}icl_translation_status s ON s.rid = j.rid
			 WHERE j.rid = %d
			   AND j.translated = 0
			 ORDER BY j.job_id DESC
			 LIMIT 1",
			(int) $rid
		), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'job_id'      => (int) $row['job_id'],
			'status'      => isset( $row['status'] ) ? (int) $row['status'] : null,
			'translated'  => (int) $row['translated'],
			'age_seconds' => isset( $row['age_seconds'] ) ? (int) $row['age_seconds'] : 0,
		];
	}

}
