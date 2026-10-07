<?php

class WPML_TP_Service extends WPML_TP_REST_Object implements Serializable {

	public $id;

	public $logo_url;

	public $logo_preview_url;

	public $name;

	public $description;

	public $doc_url;

	public $tms;

	public $partner;

	public $custom_fields;

	public $custom_fields_data;

	public $requires_authentication;

	public $rankings;

	public $has_language_pairs;

	public $url;

	public $project_details_url;
	public $add_language_pair_url;
	public $custom_text_url;
	public $select_translator_iframe_url;
	public $translator_contact_iframe_url;
	public $quote_iframe_url;
	public $has_translator_selection;
	public $project_name_length;
	public $suid;
	public $notification;
	public $preview_bundle;
	public $deadline;
	public $oauth;
	public $oauth_url;
	public $default_service;
	public $translation_feedback;
	public $feedback_forward_method;
	public $last_refresh;

	public $popup_message;

	public $how_to_get_credentials_desc;

	public $how_to_get_credentials_url;

	public $client_create_account_page_url;

	public $redirect_to_ts;

	public $countries = [];

	public $maximumJobsPerBatch;

	public $auto_refresh_project_options;

	public function __construct( ?stdClass $obj = null ) {
		parent::__construct( $obj );
		$this->set_custom_fields_data();
		$this->set_requires_authentication();
	}

	public function get_id() {
		return $this->id;
	}

	public function get_logo_url() {
		return $this->logo_url;
	}

	public function get_logo_preview_url() {
		return $this->logo_preview_url;
	}

	public function get_name() {
		return $this->name;
	}

	public function get_description() {
		return $this->description;
	}

	public function get_doc_url() {
		return $this->doc_url;
	}

	public function get_tms() {
		return $this->tms;
	}

	public function is_partner() {
		return $this->partner;
	}

	public function set_partner( $partner ) {
		$this->partner = $partner;
	}

	public function get_custom_fields() {
		$custom_fields = array();


		if ( is_object( $this->custom_fields ) && isset( $this->custom_fields->custom_fields ) ) {
			$custom_fields = $this->custom_fields->custom_fields;
		} elseif ( isset( $this->custom_fields ) ) {
			$custom_fields = $this->custom_fields;
		}

		return $custom_fields;
	}

	public function get_custom_fields_data() {
		return $this->custom_fields_data;
	}

	public function get_requires_authentication() {
		return $this->requires_authentication;
	}

	public function get_url() {
		return $this->url;
	}

	public function get_has_language_pairs() {
		return (bool) $this->has_language_pairs;
	}

	public function get_rankings() {
		return $this->rankings;
	}

	public function get_popup_message() {
		return $this->popup_message;
	}

	public function set_id( $id ) {
		$this->id = (int) $id;
	}

	public function set_logo_url( $logo_url ) {
		$this->logo_url = $logo_url;
	}

	public function set_logo_preview_url( $logo_preview_url ) {
		$this->logo_preview_url = $logo_preview_url;
	}

	public function set_url( $url ) {
		$this->url = $url;
	}

	public function set_name( $name ) {
		$this->name = $name;
	}

	public function set_description( $description ) {
		$this->description = $description;
	}

	public function set_doc_url( $doc_url ) {
		$this->doc_url = $doc_url;
	}

	public function set_tms( $tms ) {
		$this->tms = (bool) $tms;
	}

	public function set_rankings( $rankings ) {
		$this->rankings = $rankings;
	}

	public function set_custom_fields( $custom_fields ) {
		$this->custom_fields = $custom_fields;
	}

	public function set_popup_message( $popup_message ) {
		$this->popup_message = $popup_message;
	}

	public function set_custom_fields_data() {
		global $sitepress;

		$active_service = $sitepress->get_setting( 'translation_service' );
		if ( isset( $active_service->custom_fields_data, $active_service->id ) && $this->id === $active_service->id ) {
			$this->custom_fields_data = $active_service->custom_fields_data;
		}
	}

	public function set_requires_authentication() {
		$this->requires_authentication = (bool) $this->custom_fields;
	}

	public function set_has_language_pairs( $value ) {
		$this->has_language_pairs = (bool) $value;
	}

	public function get_project_details_url() {
		return $this->project_details_url;
	}

	public function set_project_details_url( $project_details_url ) {
		$this->project_details_url = $project_details_url;
	}

	public function get_add_language_pair_url() {
		return $this->add_language_pair_url;
	}

	public function set_add_language_pair_url( $add_language_pair_url ) {
		$this->add_language_pair_url = $add_language_pair_url;
	}

	public function get_custom_text_url() {
		return $this->custom_text_url;
	}

	public function set_custom_text_url( $custom_text_url ) {
		$this->custom_text_url = $custom_text_url;
	}

	public function get_select_translator_iframe_url() {
		return $this->select_translator_iframe_url;
	}

	public function set_select_translator_iframe_url( $select_translator_iframe_url ) {
		$this->select_translator_iframe_url = $select_translator_iframe_url;
	}

	public function get_translator_contact_iframe_url() {
		return $this->translator_contact_iframe_url;
	}

	public function set_translator_contact_iframe_url( $translator_contact_iframe_url ) {
		$this->translator_contact_iframe_url = $translator_contact_iframe_url;
	}

	public function get_quote_iframe_url() {
		return $this->quote_iframe_url;
	}

	public function set_quote_iframe_url( $quote_iframe_url ) {
		$this->quote_iframe_url = $quote_iframe_url;
	}

	public function get_has_translator_selection() {
		return $this->has_translator_selection;
	}

	public function set_has_translator_selection( $has_translator_selection ) {
		$this->has_translator_selection = (bool) $has_translator_selection;
	}

	public function get_project_name_length() {
		return $this->project_name_length;
	}

	public function set_project_name_length( $project_name_length ) {
		$this->project_name_length = (int) $project_name_length;
	}

	public function get_suid() {
		return $this->suid;
	}

	public function set_suid( $suid ) {
		$this->suid = $suid;
	}

	public function get_notification() {
		return $this->notification;
	}

	public function set_notification( $notification ) {
		$this->notification = (bool) $notification;
	}

	public function get_preview_bundle() {
		return $this->preview_bundle;
	}

	public function set_preview_bundle( $preview_bundle ) {
		$this->preview_bundle = (bool) $preview_bundle;
	}

	public function get_deadline() {
		return $this->deadline;
	}

	public function set_deadline( $deadline ) {
		$this->deadline = (bool) $deadline;
	}

	public function get_oauth() {
		return $this->oauth;
	}

	public function set_oauth( $oauth ) {
		$this->oauth = (bool) $oauth;
	}

	public function get_oauth_url() {
		return $this->oauth_url;
	}

	public function set_oauth_url( $oauth_url ) {
		$this->oauth_url = $oauth_url;
	}

	public function get_default_service() {
		return $this->default_service;
	}

	public function set_default_service( $default_service ) {
		$this->default_service = (int) $default_service;
	}

	public function get_translation_feedback() {
		return $this->translation_feedback;
	}

	public function set_translation_feedback( $translation_feedback ) {
		$this->translation_feedback = (bool) $translation_feedback;
	}

	public function get_feedback_forward_method() {
		return $this->feedback_forward_method;
	}

	public function set_feedback_forward_method( $feedback_forward_method ) {
		$this->feedback_forward_method = $feedback_forward_method;
	}

	public function get_last_refresh() {
		return $this->last_refresh;
	}

	public function set_last_refresh( $timestamp ) {
		$this->last_refresh = $timestamp;
	}

	public function get_how_to_get_credentials_desc() {
		return $this->how_to_get_credentials_desc;
	}

	public function set_how_to_get_credentials_desc( $desc ) {
		$this->how_to_get_credentials_desc = $desc;
	}

	public function get_how_to_get_credentials_url() {
		return $this->how_to_get_credentials_url;
	}

	public function set_how_to_get_credentials_url( $url ) {
		$this->how_to_get_credentials_url = $url;
	}

	public function get_client_create_account_page_url() {
		return $this->client_create_account_page_url;
	}

	public function set_client_create_account_page_url( $url ) {
		$this->client_create_account_page_url = $url;
	}

	public function get_redirect_to_ts() {
		return $this->redirect_to_ts;
	}

	public function set_redirect_to_ts( $redirect_to_ts ) {
		$this->redirect_to_ts = $redirect_to_ts;
	}

	public function get_countries() {
		return $this->countries;
	}

	public function set_countries( array $countries ) {
		$this->countries = $countries;
	}


	public function serialize() {
		return serialize( $this->__serialize() );
	}

	public function unserialize( $serialized ) {
		$simple_array = unserialize( $serialized );

		$this->__unserialize( $simple_array );
	}

	public function __serialize() {
		return get_object_vars( $this );
	}

	public function __unserialize( $simple_array ) {
		foreach ( $simple_array as $property => $value ) {
			if ( property_exists( $this, $property ) ) {
				$this->$property = $value;
			}
		}
	}


	public function set_recommended_maximum_number_of_jobs_per_batch( $maximumJobsPerBatch ) {
		$this->maximumJobsPerBatch = $maximumJobsPerBatch;
	}


	public function get_recommended_maximum_number_of_jobs_per_batch() {
		return $this->maximumJobsPerBatch;
	}

	public function get_auto_refresh_project_options() {
		return $this->auto_refresh_project_options;
	}

	public function set_auto_refresh_project_options( $auto_refresh_project_options ) {
		$this->auto_refresh_project_options = (bool) $auto_refresh_project_options;
	}

	protected function get_properties() {
		return [
			'id'                                           => 'id',
			'logo_url'                                     => 'logo_url',
			'logo_preview_url'                             => 'logo_preview_url',
			'url'                                          => 'url',
			'name'                                         => 'name',
			'description'                                  => 'description',
			'doc_url'                                      => 'doc_url',
			'tms'                                          => 'tms',
			'partner'                                      => 'partner',
			'custom_fields'                                => 'custom_fields',
			'custom_fields_data'                           => 'custom_fields_data',
			'requires_authentication'                      => 'requires_authentication',
			'has_language_pairs'                           => 'has_language_pairs',
			'rankings'                                     => 'rankings',
			'popup_message'                                => 'popup_message',
			'project_details_url'                          => 'project_details_url',
			'add_language_pair_url'                        => 'add_language_pair_url',
			'custom_text_url'                              => 'custom_text_url',
			'select_translator_iframe_url'                 => 'select_translator_iframe_url',
			'translator_contact_iframe_url'                => 'translator_contact_iframe_url',
			'quote_iframe_url'                             => 'quote_iframe_url',
			'has_translator_selection'                     => 'has_translator_selection',
			'project_name_length'                          => 'project_name_length',
			'suid'                                         => 'suid',
			'notification'                                 => 'notification',
			'preview_bundle'                               => 'preview_bundle',
			'deadline'                                     => 'deadline',
			'oauth'                                        => 'oauth',
			'oauth_url'                                    => 'oauth_url',
			'default_service'                              => 'default_service',
			'translation_feedback'                         => 'translation_feedback',
			'feedback_forward_method'                      => 'feedback_forward_method',
			'last_refresh'                                 => 'last_refresh',
			'how_to_get_credentials_desc'                  => 'how_to_get_credentials_desc',
			'how_to_get_credentials_url'                   => 'how_to_get_credentials_url',
			'client_create_account_page_url'               => 'client_create_account_page_url',
			'redirect_to_ts?'                              => 'redirect_to_ts',
			'countries'                                    => 'countries',
			'recommended_maximum_number_of_jobs_per_batch' => 'recommended_maximum_number_of_jobs_per_batch',
			'auto_refresh_project_options'				   =>  'auto_refresh_project_options',
		];
	}
}
