<?php

namespace Gravity_Forms\Gravity_Forms_RECAPTCHA;

use GFCache;
use GFCommon;

/**
 * Class GF_Field_RECAPTCHA_Checkbox
 *
 * @since 2.2.0
 *
 * @package Gravity_Forms\Gravity_Forms_RECAPTCHA
 */
class GF_Field_RECAPTCHA_Checkbox extends \GF_Field {

	/**
	 * Recaptcha field type.
	 *
	 * @since 2.2.0
	 *
	 * @var string
	 */
	public $type = 'recaptcha_checkbox';

	/**
	 * Whether there can be more than one of this field type per form with GF 3.0+.
	 *
	 * @since 2.2.0
	 *
	 * @var bool
	 */
	public $duplicatable = false;

	/**
	 * Whether the field can be used in a repeater with GF 3.0+.
	 *
	 * @since 2.2.0
	 *
	 * @var bool
	 */
	public $repeatable = false;

	/**
	 * Whether the field is for front-end display only use.
	 *
	 * @since 2.2.0
	 *
	 * @var bool
	 */
	public $displayOnly = true;

	/**
	 * Instantiates the field and adds the necessary hooks.
	 *
	 * @since 2.2.0
	 *
	 * @param array $data The field properties and their values.
	 */
	public function __construct( $data = array() ) {
		parent::__construct( $data );
		if ( $this->id && $this->formId ) {
			add_action( 'gform_entry_created', array( $this, 'action_entry_created' ) );
			add_filter( 'gform_ajax_submission_result' , array( $this, 'filter_ajax_submission_result' ) );
		}
	}

	/**
	 * Get field button title.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_form_editor_field_title() {
		return esc_attr__( 'reCAPTCHA Checkbox', 'gravityformsrecaptcha' );
	}

	/**
	 * Returns the field's form editor icon.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_form_editor_field_icon() {
		return 'gform-icon--recaptcha';
	}

	/**
	 * Return empty array to prevent the field from showing up in the form editor.
	 *
	 * @since 2.2.0
	 *
	 * @return array
	 */
	public function get_form_editor_button() {
		if ( ! $this->has_enterprise_checkbox_key() ) {
			return array();
		}

		return array(
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}

	/**
	 * Removes the duplicate field link from the admin field buttons.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_admin_buttons() {
		add_filter( 'gform_duplicate_field_link', '__return_empty_string' );
		$admin_buttons = parent::get_admin_buttons();
		remove_filter( 'gform_duplicate_field_link', '__return_empty_string' );

		return $admin_buttons;
	}

	/**
	 * Returns the field's form editor description.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_form_editor_field_description() {
		return esc_attr__( 'Adds a reCAPTCHA Enterprise checkbox field to your form to help protect your website from spam and bot abuse.', 'gravityformsrecaptcha' );
	}

	/**
	 * Get field settings in the form editor.
	 *
	 * @since 2.2.0
	 *
	 * @return array
	 */
	public function get_form_editor_field_settings() {
		return array(
			'label_setting',
			'captcha_theme_setting',
			'label_placement_setting',
			'error_message_setting',
		);
	}

	/**
	 * Returns the scripts to be included for this field type in the form editor.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public function get_form_editor_inline_script_on_page_render() {
		$type = esc_js( $this->type );

		$cant_add_field_title   = json_encode( $this->get_form_editor_field_title() );
		$cant_add_field_message = json_encode( esc_html__( 'Only one reCAPTCHA checkbox field can be added to the form.', 'gravityformsrecaptcha' ) );

		$default_label = json_encode( __( 'reCAPTCHA', 'gravityformsrecaptcha' ) );

		$a11y_string1 = json_encode( esc_html__( 'This field has accessibility issues. We recommend using a score-based key instead of this field.', 'gravityformsrecaptcha' ) );
		$a11y_string2 = json_encode( esc_html__( '(opens in a new tab)', 'gravityformsrecaptcha' ) );

		return <<<EOD
gform.addFilter( 'gform_form_editor_can_field_be_added', ( canFieldBeAdded, type ) => {
	if ( type === '{$type}' && GetFieldsByType( [ '{$type}' ] ).length ) {
		canFieldBeAdded = false;
		gform.instances.dialogAlert( {$cant_add_field_title}, {$cant_add_field_message} );
	}

	return canFieldBeAdded;
} );
window.SetDefaultValues_{$type} = ( field ) => {
	field.label = {$default_label};
	field.captchaTheme = 'light';
}
gform.addAction( 'gform_post_load_field_settings', ( [ field, form ] ) => {
	if ( field.type !== '{$type}' ) {
		return;
	}

	const message = '<p class="gform-alert__message"><a href="https://docs.gravityforms.com/field-accessibility-warning" target="_blank">' + {$a11y_string1} + '<span class="screen-reader-text">' + {$a11y_string2} + '</span>&nbsp;<span class="gform-icon gform-icon--external-link" aria-hidden="true"></span></a></p>';

	SetFieldAccessibilityWarning( 'label_setting', 'above', message );

	const themeSetting = document.getElementById( 'field_captcha_theme' );
	if ( themeSetting.value !== field.captchaTheme ) {
		themeSetting.value = field.captchaTheme;
	}
} );
EOD;
	}

	/**
	 * Returns the warning message to be displayed in the form editor sidebar.
	 *
	 * @since 2.2.0
	 *
	 * @return string|array
	 */
	public function get_field_sidebar_messages() {
		if ( $this->has_enterprise_checkbox_key() ) {
			return '';
		}

		return array(
			'type'             => 'notice',
			'content'          => sprintf(
				'%s<div class="gform-spacing gform-spacing--top-1">%s</div>',
				esc_html__( 'Configuration Required', 'gravityformsrecaptcha' ),
				// Translators: 1. Opening <a> tag with link to the Forms > Settings > reCAPTCHA page. 2. closing <a> tag.
				sprintf(
					esc_html__( 'To use this field, configure the %1$sreCAPTCHA Add-On Settings%2$s using the Enterprise connection type, and select a checkbox type key.', 'gravityformsrecaptcha' ),
					'<a href="?page=gf_settings&subview=gravityformsrecaptcha" target="_blank">',
					'<span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'gravityformsrecaptcha' ) . '</span>&nbsp;<span class="gform-icon gform-icon--external-link" aria-hidden="true"></span></a>'
				)
			),
			'icon_helper_text' => esc_html__( 'This field requires additional configuration', 'gravityformsrecaptcha' ),
		);
	}

	/**
	 * Determine if the enterprise checkbox key is configured.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	private function has_enterprise_checkbox_key() {
		return gf_recaptcha()->has_enterprise_checkbox_key();
	}

	/**
	 * The field markup.
	 *
	 * @since 2.2.0
	 *
	 * @param array      $form  The form array.
	 * @param string     $value The field value.
	 * @param array|null $entry The entry array.
	 *
	 * @return string
	 */
	public function get_field_input( $form, $value = '', $entry = null ) {
		if ( $this->is_form_editor() ) {
			return $this->get_field_input_form_editor();
		}

		if ( ! $this->has_enterprise_checkbox_key() ) {
			gf_recaptcha()->log_debug( __METHOD__ . '(): A reCAPTCHA v3 Enterprise checkbox type key is not configured.' );

			return '';
		}

		if ( empty( $value ) || ! is_string( $value ) ) {
			$value = '';
		}

		return sprintf(
			"<div class='ginput_container ginput_container_recaptcha_checkbox'><div class='g-recaptcha' data-sitekey='%s' data-theme='%s' data-action='submit' data-tabindex='%d'></div>"
			. "<input class='gfield_recaptcha_response' type='hidden' name='input_%d' value='%s'/>"
			. "</div>",
			esc_attr( gf_recaptcha()->get_plugin_settings_instance()->get_recaptcha_key( 'site_key_v3_enterprise' ) ),
			GFCommon::whitelist( $this->captchaTheme, array( 'light', 'dark' ) ),
			esc_attr( GFCommon::$tab_index > 0 ? GFCommon::$tab_index ++ : 0 ),
			$this->id,
			esc_attr( $this->get_context_property( 'assessment_id_hash' ) ?: $value )
		);
	}

	/**
	 * Returns the field input markup for the form editor.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	private function get_field_input_form_editor() {
		if ( $this->has_enterprise_checkbox_key() ) {
			$theme   = GFCommon::whitelist( $this->captchaTheme, array( 'light', 'dark' ) );
			$img_url = GFCommon::get_base_url() . sprintf( '/images/captcha_%s.svg', $theme );

			return sprintf( '<div class="ginput_container"><img class="gfield_captcha" src="%s" alt="%s" width="304" height="78"></div>', esc_attr( $img_url ), esc_attr__( 'An example reCAPTCHA checkbox', 'gravityformsrecaptcha' ) );
		}

		return '<div class="ginput_container ginput_container_addon_message ginput_container_addon_message_recaptcha_checkbox">
							<div class="gform-alert gform-alert--info gform-alert--theme-cosmos gform-spacing gform-spacing--bottom-0 gform-theme__disable">
								<span
									class="gform-icon gform-icon--information-simple gform-icon--preset-active gform-icon-preset--status-info gform-alert__icon"
									aria-hidden="true"
								></span>
								<div class="gform-alert__message-wrap">
									<div class="gform-alert__message">
										' . esc_html__( 'Configuration Required', 'gravityformsrecaptcha' ) . '
										<div class="gform-spacing gform-spacing--top-1">' . sprintf(
				esc_html__( 'To use this field, configure the %1$sreCAPTCHA Add-On Settings%2$s using the Enterprise connection type, and select a checkbox type key.', 'gravityformsrecaptcha' ),
				'<a href="?page=gf_settings&subview=gravityformsrecaptcha" target="_blank">',
				'<span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'gravityformsrecaptcha' ) . '</span>&nbsp;<span class="gform-icon gform-icon--external-link" aria-hidden="true"></span></a>'
			) . '</div>
									</div>
								</div>
							</div>
						</div>';
	}

	/**
	 * Validates the submitted reCAPTCHA token or hash of an existing assessment ID.
	 *
	 * @since 2.2.0
	 *
	 * @param string $value The submitted value.
	 * @param array  $form  The current form.
	 *
	 * @return void
	 */
	public function validate( $value, $form ) {
		if ( ( wp_doing_ajax() && rgpost( 'action' ) === 'gfcf_validate_field' ) || ! $this->has_enterprise_checkbox_key() ) {
			return;
		}

		$cache_key_prefix = sprintf( 'gf_recaptcha_assessment_%s_%d_', \GFFormsModel::get_form_unique_id( $this->formId ), $this->id );
		$token_verifier   = gf_recaptcha()->get_token_verifier();

		$token = rgpost( 'g-recaptcha-response' );
		if ( empty( $token ) ) {
			$hash = ! empty( $value ) && is_string( $value ) ? sanitize_key( $value ) : null;
			if ( $hash ) {
				$assessment = GFCache::get( $cache_key_prefix . $hash, $found );
				if ( $found && ! empty( $assessment['name'] ) && $hash === wp_hash( $assessment['name'] ) ) {
					// Setting to the cached assessment so it is available when the entry spam check runs.
					$token_verifier->set_recaptcha_result( $assessment );
					$this->set_context_property( 'assessment_id_hash', $hash );
					gf_recaptcha()->log_debug( __METHOD__ . sprintf( '(): Using existing cached and validated assessment (ID: %s) for submitted hash (%s) for field #%d on form #%d.', $assessment['name'], $hash, $this->id, $this->formId  ) );

					return;
				}

				gf_recaptcha()->log_debug( __METHOD__ . sprintf( '(): Hash (%s) failed validation for field #%d on form #%d.', $hash, $this->id, $this->formId ) );
			}

			gf_recaptcha()->log_debug( __METHOD__ . sprintf( '(): No token submitted for field #%d on form #%d.', $this->id, $this->formId ) );
			$this->set_required_error( '' );

			return;
		}

		if ( ! gf_recaptcha()->initialize_api() ) {
			gf_recaptcha()->log_debug( __METHOD__ . sprintf( '(): Skipping validation for field #%d on form #%d.', $this->id, $this->formId ) );

			return;
		}

		gf_recaptcha()->log_debug( __METHOD__ . sprintf( '(): Validating token for field #%d on form #%d.', $this->id, $this->formId ) );

		if ( ! $token_verifier->verify_submission( $token ) ) {
			$this->failed_validation  = true;
			$this->validation_message = $this->errorMessage ?: __( 'The reCAPTCHA was invalid. Go back and try it again.', 'gravityformsrecaptcha' );

			return;
		}

		$assessment         = $token_verifier->get_recaptcha_result();
		$assessment_id_hash = wp_hash( rgar( $assessment, 'name' ) );
		GFCache::set( $cache_key_prefix . $assessment_id_hash, $assessment, true, DAY_IN_SECONDS );
		$this->set_context_property( 'assessment_id_hash', $assessment_id_hash );
		gf_recaptcha()->log_debug( __METHOD__ . sprintf( '(): Caching validated assessment (ID: %s) using hash (%s).', $assessment['name'], $assessment_id_hash ) );
	}

	/**
	 * Adds the assessment ID hash to the AJAX submission result when the response includes a page markup change.
	 *
	 * @since 2.2.0
	 *
	 * @param array $result The AJAX submission result.
	 *
	 * @return array
	 */
	public function filter_ajax_submission_result( $result ) {
		$hash = $this->get_context_property( 'assessment_id_hash' );
		if ( ! $hash || ! isset( $result['page_markup'] ) ) {
			return $result;
		}

		$result['recaptcha_checkbox_response'] = $hash;

		return $result;
	}

	/**
	 * Deletes the cached assessment data after the entry has been saved.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function action_entry_created() {
		$hash = $this->get_context_property( 'assessment_id_hash' );
		if ( ! $hash ) {
			return;
		}

		GFCache::delete( sprintf( 'gf_recaptcha_assessment_%s_%d_', \GFFormsModel::get_form_unique_id( $this->formId ), $this->id ) . $hash );
		gf_recaptcha()->log_debug( __METHOD__ . sprintf( '(): Deleted assessment cache for hash (%s).', $hash ) );
	}

}

\GF_Fields::register( new GF_Field_RECAPTCHA_Checkbox() );
