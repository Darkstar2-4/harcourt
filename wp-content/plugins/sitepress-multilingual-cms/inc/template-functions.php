<?php

function icl_sitepress_get_capabilities() {
	return wpml_get_capabilities_names();
}

function wpml_get_capabilities_names() {
	return wpml_get_capability_keys();
}

function wpml_get_capabilities_labels() {
	$capabilities = wpml_get_capabilities();

	return array_values( $capabilities );
}

function wpml_get_capabilities() {
	return apply_filters( 'wpml_capabilities', \WPML\DefaultCapabilities::get() );
}

function wpml_get_capability_keys() {
	return apply_filters( 'wpml_capabilities', \WPML\DefaultCapabilities::getKeys() );
}

function wpml_get_read_only_capabilities_filter( $empty ) {
	return wpml_get_capabilities();
}

add_filter( 'wpml_capabilities_read_only', 'wpml_get_read_only_capabilities_filter', 10, 1 );

function wpml_get_roles() {
	$wp_roles['label']        = __( 'WPML capabilities', 'sitepress' );
	$wp_roles['capabilities'] = wpml_get_capabilities();

	return apply_filters( 'wpml_roles', $wp_roles );
}

function wpml_roles_read_only_filter( $empty ) {
	return wpml_get_roles();
}

add_filter( 'wpml_roles_read_only', 'wpml_roles_read_only_filter', 10, 1 );

function icl_get_home_url() {
	global $sitepress;
	$current_language = $sitepress->get_current_language();

	return $sitepress->language_url( $current_language );
}

function wpml_get_home_url_filter() {
	global $sitepress;
	$current_language = $sitepress->get_current_language();

	return $sitepress->language_url( $current_language );
}

function icl_get_languages( $a = '' ) {
	if ( $a ) {
		parse_str( $a, $args );
	} else {
		$args = '';
	}
	global $sitepress;
	$langs = $sitepress->get_ls_languages( $args );

	return $langs;
}

function wpml_get_active_languages_filter( $empty_value, $args = '' ) {
	global $sitepress;

	$args = wp_parse_args( $args );
	return $sitepress->get_ls_languages( $args );
}

function icl_disp_language( $native_name, $translated_name = false, $lang_native_hidden = false, $lang_translated_hidden = false ) {
	$language_switcher = new SitePressLanguageSwitcher();

	return $language_switcher->language_display( $native_name, $translated_name, ! $lang_native_hidden, ! $lang_translated_hidden );
}

function wpml_display_language_names_filter( $empty_value, $native_name, $translated_name = false, $lang_native_hidden = false, $lang_translated_hidden = false ) {
	$language_switcher = new SitePressLanguageSwitcher();

	return $language_switcher->language_display( $native_name, $translated_name, ! $lang_native_hidden, ! $lang_translated_hidden );
}

function icl_link_to_element(
	$element_id,
	$element_type = 'post',
	$link_text = '',
	$optional_parameters = array(),
	$anchor = '',
	$echo = true,
	$return_original_if_missing = true
) {
	return wpml_link_to_element_filter(
		$element_id,
		$element_type,
		$link_text,
		$optional_parameters,
		$anchor,
		$echo,
		$return_original_if_missing
	);
}

function wpml_link_to_element_filter(
	$element_id, $element_type = 'post', $link_text = '', $optional_parameters = array(), $anchor = '', $echo = true, $return_original_if_missing = true
) {
	global $sitepress, $wpdb, $wp_post_types, $wp_taxonomies;

	if ( $element_type == 'tag' ) {
		$element_type = 'post_tag';
	}
	if ( $element_type == 'page' ) {
		$element_type = 'post';
	}

	$post_types = array_keys( (array) $wp_post_types );
	$taxonomies = array_keys( (array) $wp_taxonomies );

	if ( in_array( $element_type, $taxonomies ) ) {
		$element_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id= %d AND taxonomy=%s", $element_id, $element_type ) );
	} elseif ( in_array( $element_type, $post_types ) ) {
		$element_type = 'post';
	}

	if ( ! $element_id ) {
		return '';
	}

	if ( in_array( $element_type, $taxonomies ) ) {
		$icl_element_type = 'tax_' . $element_type;
	} elseif ( in_array( $element_type, $post_types ) ) {
		$icl_element_type = 'post_' . $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_type
                                                                     FROM {$wpdb->posts}
                                                                     WHERE ID = %d",
				$element_id
			)
		);
	} else {
		return '';
	}

	$trid         = $sitepress->get_element_trid( $element_id, $icl_element_type );
	$translations = $sitepress->get_element_translations( $trid, $icl_element_type );

	if ( isset( $translations[ ICL_LANGUAGE_CODE ] ) ) {
		if ( $element_type == 'post' ) {
			$url   = get_permalink( $translations[ ICL_LANGUAGE_CODE ]->element_id );
			$title = $translations[ ICL_LANGUAGE_CODE ]->post_title;
		} elseif ( $element_type == 'post_tag' ) {
			list( $term_id, $title ) = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->term_taxonomy} tx JOIN {$wpdb->terms} t ON t.term_id = tx.term_id WHERE tx.term_taxonomy_id = %d AND tx.taxonomy='post_tag'", $translations[ ICL_LANGUAGE_CODE ]->element_id ), ARRAY_N );
			$url                     = get_tag_link( $term_id );
			$title                   = apply_filters( 'single_cat_title', $title );
		} elseif ( $element_type == 'category' ) {
			list( $term_id, $title ) = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->term_taxonomy} tx JOIN {$wpdb->terms} t ON t.term_id = tx.term_id WHERE tx.term_taxonomy_id = %d AND tx.taxonomy='category'", $translations[ ICL_LANGUAGE_CODE ]->element_id ), ARRAY_N );
			$url                     = get_category_link( $term_id );
			$title                   = apply_filters( 'single_cat_title', $title );
		} else {
			list( $term_id, $title ) = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->term_taxonomy} tx JOIN {$wpdb->terms} t ON t.term_id = tx.term_id WHERE tx.term_taxonomy_id = %d AND tx.taxonomy=%s", $translations[ ICL_LANGUAGE_CODE ]->element_id, $element_type ), ARRAY_N );
			$url                     = get_term_link( (int) $term_id, $element_type );
			$title                   = apply_filters( 'single_cat_title', $title );
		}
	} else {
		if ( ! $return_original_if_missing ) {
			if ( $echo ) {
				echo '';
			}

			return '';
		}

		if ( $element_type == 'post' ) {
			$url   = get_permalink( $element_id );
			$title = get_the_title( $element_id );
		} elseif ( $element_type == 'post_tag' ) {
			$url    = get_tag_link( $element_id );
			$my_tag = &get_term( $element_id, 'post_tag', OBJECT, 'display' );
			$title  = apply_filters( 'single_tag_title', $my_tag->name );
		} elseif ( $element_type == 'category' ) {
			$url    = get_category_link( $element_id );
			$my_cat = &get_term( $element_id, 'category', OBJECT, 'display' );
			$title  = apply_filters( 'single_cat_title', $my_cat->name );
		} else {
			$url    = get_term_link( (int) $element_id, $element_type );
			$my_cat = &get_term( $element_id, $element_type, OBJECT, 'display' );
			$title  = apply_filters( 'single_cat_title', $my_cat->name );
		}
	}

	if ( ! $url || is_wp_error( $url ) ) {
		return '';
	}

	if ( ! empty( $optional_parameters ) ) {
		$url_glue = false === strpos( $url, '?' ) ? '?' : '&';
		$url     .= $url_glue . http_build_query( $optional_parameters );
	}

	if ( $anchor ) {
		$url .= '#' . $anchor;
	}

	$link = '<a href="' . esc_url( $url ) . '">';
	if ( $link_text ) {
		$link .= esc_html( $link_text );
	} else {
		$link .= esc_html( $title );
	}
	$link .= '</a>';

	if ( $echo ) {
		echo $link;
	}

	return $link;
}

function icl_object_id( $element_id, $element_type = 'post', $return_original_if_missing = false, $ulanguage_code = null ) {

	return wpml_object_id_filter( $element_id, $element_type, $return_original_if_missing, $ulanguage_code );
}

add_filter( 'translate_object_id', 'icl_object_id', 10, 4 );

function wpml_object_id_filter( $element_id, $element_type = 'post', $return_original_if_missing = false, $language_code = null ) {
	global $sitepress;
	return $sitepress->get_object_id( $element_id, $element_type, $return_original_if_missing, $language_code );
}

function icl_get_display_language_name( $lang_code, $display_code = false ) {
	global $sitepress;

	return $sitepress->get_display_language_name( $lang_code, $display_code );
}

function wpml_translated_language_name_filter( $empty_value, $lang_code, $display_code = false ) {
	global $sitepress;

	return $sitepress->get_display_language_name( $lang_code, $display_code );
}

function icl_get_current_language() {
	return apply_filters( 'wpml_current_language', '' );
}

function wpml_get_current_language_filter() {
	return wpml_get_current_language();
}

function icl_get_default_language() {
	global $sitepress;

	return $sitepress->get_default_language();
}

function wpml_get_default_language_filter( $empty_value ) {
	return wpml_get_default_language();
}

function wpml_get_default_language() {
	global $sitepress;

	return $sitepress->get_default_language();
}

function wpml_get_current_language() {
	return apply_filters( 'wpml_current_language', '' );
}

function icl_tf_determine_mo_folder( $folder ) {
	global $sitepress;
	$mo_file_search = new WPML_MO_File_Search( $sitepress );

	return $mo_file_search->determine_mo_folder( $folder );
}

function wpml_input_field_helper( $attributes = array(), $checked = false, $disabled = false ) {
	if ( $disabled ) {
		$attributes['readonly'] = 'readonly';
		$attributes['disabled'] = 'disabled';
	}
	if ( $checked && array_key_exists( 'type', $attributes ) && in_array( $attributes['type'], array( 'checkbox', 'radio' ) ) ) {
		$attributes['checked'] = 'checked';
	}
	$html_attributes = array();
	if ( is_array( $attributes ) ) {
		foreach ( $attributes as $attribute => $attribute_value ) {
			if ( $attribute != 'custom' ) {
				$html_attributes[] = strip_tags( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
			} else {
				$html_attributes[] = esc_attr( $attribute_value );
			}
		}
	}
	$output  = '<!-- OK! -->';
	$output .= '<input';
	if ( $html_attributes ) {
		$output .= ' ' . join( ' ', $html_attributes );
	}
	$output .= '>';

	return $output;
}

function wpml_label_helper( $attributes, $caption ) {
	$html_attributes = array();
	if ( is_array( $attributes ) ) {
		foreach ( $attributes as $attribute => $attribute_value ) {
			if ( $attribute != 'custom' ) {
				$html_attributes[] = strip_tags( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
			} else {
				$html_attributes[] = esc_attr( $attribute_value );
			}
		}
	}

	$output  = '<!-- OK! -->';
	$output .= '<label';
	if ( $html_attributes ) {
		$output .= ' ' . join( ' ', $html_attributes );
	}

	$output .= '>' . $caption . '</label>';

	return $output;
}

function wpml_translation_preference_input_helper( $args, $id_prefix, $value, $caption ) {
	$output = '';

	$input_attributes = $args['input_attributes'];
	$label_attributes = $args['label_attributes'];
	$id               = $args['id'];
	$action           = $args['action'];

	$input_attributes['id']    = $id_prefix . $id;
	$input_attributes['value'] = $value;
	$label_attributes['for']   = $input_attributes['id'];

	$output .= wpml_input_field_helper( $input_attributes, ( $value === $action ), $args['disabled'] );
	$output .= wpml_label_helper( $label_attributes, $caption );

	return $output;
}

function wpml_cf_translation_preferences( $id, $custom_field = false, $class = 'wpml', $ajax = false, $default_value = 'ignore', $fieldset = false, $suppress_error = false ) {
	global $iclTranslationManagement;

	$output = '';

	if ( isset( $iclTranslationManagement ) ) {
		$section                 = 'custom_fields';
		$config_section          = $iclTranslationManagement->get_translation_setting_name( $section );
		$readonly_config_section = $iclTranslationManagement->get_readonly_translation_setting_name( $section );

		if ( $custom_field ) {
			$custom_field = @strval( $custom_field );
		}
		$class = @strval( $class );
		if ( $fieldset ) {
			$output .= '
<fieldset id="wpml_cf_translation_preferences_fieldset_' . $id . '" class="wpml_cf_translation_preferences_fieldset ' . $class . '-form-fieldset form-fieldset fieldset">' . '<legend>' . __( 'Translation preferences', 'sitepress' ) . '</legend>';
		}
		$actions  = array(
			'ignore'    => 0,
			'copy'      => 1,
			'translate' => 2,
			'copy-once' => 3,
		);
		$action   = isset( $actions[ @strval( $default_value ) ] ) ? $actions[ @strval( $default_value ) ] : 0;
		$disabled = false;
		if ( $custom_field ) {
			if ( defined( 'WPML_TM_VERSION' ) && ! empty( $iclTranslationManagement ) ) {
				$custom_fields_settings = $iclTranslationManagement->settings[ $config_section ];
				if ( isset( $custom_fields_settings[ $custom_field ] ) ) {
					$action = intval( $custom_fields_settings[ $custom_field ] );
				}
				$custom_fields_readonly_settings = $iclTranslationManagement->settings[ $readonly_config_section ];
				$custom_fields_readonly_settings = isset( $custom_fields_readonly_settings ) ? $custom_fields_readonly_settings : array();
				$xml_override                    = in_array( $custom_field, $custom_fields_readonly_settings );
				$disabled                        = $xml_override;
				if ( $xml_override ) {
					$output .=
						'<div style="color:Red;font-style:italic;margin: 10px 0 0 0;">'
						. __( 'The translation preference for this field are being controlled by a language configuration XML file. If you want to control it manually, remove the entry from the configuration file.', 'sitepress' )
						. '</div>';
				}
			} elseif ( ! $suppress_error ) {
				$output  .= '<span style="color:#FF0000;">' . __( "To synchronize values for translations, you need to enable WPML's Translation Management module.", 'sitepress' ) . '</span>';
				$disabled = true;
			}
		} elseif ( ! $suppress_error ) {
			$output  .= '<span style="color:#FF0000;">' . __( 'Error: Something is wrong with field value. Translation preferences can not be set.', 'sitepress' ) . '</span>';
			$disabled = true;
		}
		$output .= '<div class="description ' . $class . '-form-description ' . $class . '-form-description-fieldset description-fieldset">' . __( 'Choose what to do when translating content with this field:', 'sitepress' ) . '</div>';

		$input_attributes = array(
			'name'  => 'wpml_cf_translation_preferences[' . $id . ']',
			'class' => $class . '-form-radio form-radio radio',
			'type'  => 'radio',
		);
		$label_attributes = array(
			'class' => $class . '-form-label ' . $class . '-form-radio-label',
		);

		$args = array(
			'input_attributes' => $input_attributes,
			'label_attributes' => $label_attributes,
			'disabled'         => $disabled,
			'id'               => $id,
			'action'           => $action,
		);

		$output .= '<ul><li>';
		$output .= wpml_translation_preference_input_helper( $args, 'wpml_cf_translation_preferences_option_ignore_', WPML_IGNORE_CUSTOM_FIELD, __( "Don't translate", 'sitepress' ) );
		$output .= '</li><li>';
		$output .= wpml_translation_preference_input_helper( $args, 'wpml_cf_translation_preferences_option_copy_', WPML_COPY_CUSTOM_FIELD, __( 'Copy from original to translation', 'sitepress' ) );
		$output .= '</li><li>';
		$output .= wpml_translation_preference_input_helper( $args, 'wpml_cf_translation_preferences_option_copy_once_', WPML_COPY_ONCE_CUSTOM_FIELD, __( 'Copy once', 'sitepress' ) );
		$output .= '</li><li>';
		$output .= wpml_translation_preference_input_helper( $args, 'wpml_cf_translation_preferences_option_translate_', WPML_TRANSLATE_CUSTOM_FIELD, __( 'Translate', 'sitepress' ) );
		$output .= '</li></ul>';

		if ( $custom_field && $ajax ) {
			$output .= '
<div style=";margin: 5px 0 5px 0;" id="wpml_cf_translation_preferences_ajax_response_' . $id . '"></div>
<input type="button" onclick="icl_cf_translation_preferences_submit(\'' . $id . '\', jQuery(this));" style="margin-top:5px;" class="button-secondary" value="' . __( 'Apply' ) . '" name="wpml_cf_translation_preferences_submit_' . $id . '" />
<input type="hidden" name="wpml_cf_translation_preferences_data_' . $id . '" value="custom_field=' . $custom_field . '&amp;_icl_nonce=' . wp_create_nonce( 'wpml_cf_translation_preferences_nonce' ) . '" />';
		}
		if ( $fieldset ) {
			$output .= '
</fieldset>
';
		}
	}

	return $output;
}

function wpml_get_copied_fields_for_post_edit( $fields = array() ) {
	global $sitepress, $wpdb, $sitepress_settings, $pagenow;

	$copied_cf    = array( 'fields' => array() );
	$translations = null;

	if ( defined( 'WPML_TM_VERSION' ) ) {

		if ( ( $pagenow == 'post-new.php' || $pagenow == 'post.php' ) ) {
			$lang_details = null;
			$source_lang  = null;

			if ( isset( $_GET['trid'] ) ) {
				$post_type = isset( $_GET['post_type'] ) ? $_GET['post_type'] : 'post';

				$translations = $sitepress->get_element_translations( $_GET['trid'], 'post_' . $post_type );

				$source_lang  = isset( $_GET['source_lang'] ) ? $_GET['source_lang'] : $sitepress->get_default_language();
				$lang_details = $sitepress->get_language_details( $source_lang );
			} else {
				$post_id = filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT );
				if ( $post_id ) {
					$post_type   = $wpdb->get_var( $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
					$trid        = $sitepress->get_element_trid( $post_id, 'post_' . $post_type );
					$original_id = $wpdb->get_var( $wpdb->prepare( "SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE source_language_code IS NULL AND trid=%d", $trid ) );
					if ( $original_id != $post_id ) {
						$translations = $sitepress->get_element_translations( $trid, 'post_' . $post_type );
						$source_lang  = $wpdb->get_var( $wpdb->prepare( "SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE source_language_code IS NULL AND trid=%d", $trid ) );
						$lang_details = $sitepress->get_language_details( $source_lang );
					}
				}
			}

			if ( $lang_details && $translations && $source_lang ) {
				$original_custom = get_post_custom( $translations[ $source_lang ]->element_id );

				$copied_cf['_wpml_original_post_id'] = $translations[ $source_lang ]->element_id;
				$ccf_note                            = '<img src="' . ICL_PLUGIN_URL . '/res/img/alert.png" alt="Notice" width="16" height="16" style="margin-right:8px" />';
				$copied_cf['copy_message']           = $ccf_note . sprintf( __( 'WPML will copy this field from %s when you save this post.', 'sitepress' ), $lang_details['display_name'] );

				foreach ( (array) $sitepress_settings['translation-management']['custom_fields_translation'] as $key => $sync_opt ) {
					if ( $sync_opt == 1 && ( isset( $original_custom[ $key ] ) || in_array( $key, $fields ) ) ) {
						$copied_cf['fields'][] = $key;
					}
				}
			}
		}
	}

	return $copied_cf;
}

function wpml_get_language_information( $empty_value = null, $post_id = null ) {
	global $sitepress;

	if ( is_null( $post_id ) ) {
		$post_id = get_the_ID();
	}
	if ( empty( $post_id ) ) {
		return new WP_Error( 'missing_id', __( 'Missing post ID', 'sitepress' ) );
	}

	$post = get_post( $post_id );
	if ( empty( $post ) ) {
		// translators: Post id.
		return new WP_Error( 'missing_post', sprintf( __( 'No such post for ID = %d', 'sitepress' ), $post_id ) );
	}

	$language             = $sitepress->get_language_for_element( $post_id, 'post_' . $post->post_type );
	$language_information = $sitepress->get_language_details( $language );

	$current_language = $sitepress->get_current_language();
	$info             = [
		'language_code'      => $language,
		'locale'             => $sitepress->get_locale( $language ),
		'text_direction'     => $sitepress->is_rtl( $language ),
		'display_name'       => $sitepress->get_display_language_name( $language, $current_language ),
		'native_name'        => isset( $language_information['display_name'] ) ? $language_information['display_name'] : '',
		'different_language' => $language !== $current_language,
	];

	return $info;
}

add_filter( 'wpcf_meta_box_post_type', 'wpml_wpcf_meta_box_order_defaults' );

function wpml_wpcf_meta_box_order_defaults( $boxes ) {
	$boxes['wpml'] = array(
		'callback' => 'wpml_custom_post_translation_options',
		'title'    => __( 'Translation', 'sitepress' ),
		'default'  => 'normal',
		'priority' => 'low',
	);

	return $boxes;
}

function wpml_custom_post_translation_options() {
	global $sitepress;
	$type_id = isset( $_GET['wpcf-post-type'] ) ? $_GET['wpcf-post-type'] : '';

	$out = '';

	$type = get_post_type_object( $type_id );

	$translated = $sitepress->is_translated_post_type( $type_id );

	$link  = WPML_Admin_URL::multilingual_setup( 7 );
	$link2 = WPML_Admin_URL::multilingual_setup( 4 );

	if ( $translated ) {

		$out .= sprintf( __( '%1$s is translated via WPML. %2$sClick here to change translation options.%3$s', 'sitepress' ), '<strong>' . $type->labels->singular_name . '</strong>', '<a href="' . $link . '">', '</a>' );

		if (
			( true === $type->rewrite || ( is_array( $type->rewrite ) && $type->rewrite['enabled'] ) )
			&& class_exists( 'WPML_ST_Post_Slug_Translation_Settings' )
		) {

			$settings = new WPML_ST_Post_Slug_Translation_Settings( $sitepress );

			if ( $settings->is_enabled() ) {
				if ( ! $settings->is_translated( $type_id ) ) {
					$out .= '<ul><li>' . __( 'Slugs are currently not translated.', 'sitepress' ) . '<li></ul>';
				} else {
					$out .= '<ul><li>' . __( 'Slugs are currently translated. Click the link above to edit the translations.', 'sitepress' ) . '<li></ul>';
				}
			} else {
				$out .= '<ul><li>' . sprintf( __( 'Slug translation is currently disabled in WPML. %1$sClick here to enable.%2$s', 'sitepress' ), '<a href="' . $link2 . '">', '</a>' ) . '</li></ul>';
			}
		}
	} else {

		$out .= sprintf( __( '%1$s is not translated. %2$sClick here to make this post type translatable.%3$s', 'sitepress' ), '<strong>' . $type->labels->singular_name . '</strong>', '<a href="' . $link . '">', '</a>' );
	}

	return $out;
}

function icl_language_selector() {
	ob_start();
	do_action( 'wpml_add_language_selector' );
	$output = ob_get_contents();
	ob_end_clean();
	return $output;
}

function wpml_add_language_selector_action() {
	do_action( 'wpml_add_language_selector' );
}

function icl_language_selector_footer() {
	ob_start();
	do_action( 'wpml_footer_language_selector' );
	$output = ob_get_contents();
	ob_end_clean();
	return $output;
}

function wpml_footer_language_selector_action() {
	do_action( 'wpml_footer_language_selector' );
}

function wpml_get_language_input_field() {
	global $sitepress;
	if ( isset( $sitepress ) ) {
		return "<input type='hidden' name='lang' value='" . esc_attr( $sitepress->get_current_language() ) . "' />";
	}

	return null;
}

function wpml_the_language_input_field() {
	echo wpml_get_language_input_field();
}

function wpml_add_language_form_field_action() {
	echo wpml_get_language_form_field();
}

function wpml_language_form_field_shortcode() {
	return wpml_get_language_form_field();
}

function wpml_get_language_form_field() {
	$language_form_field = '';
	global $sitepress;
	if ( isset( $sitepress ) ) {
		$current_language    = $sitepress->get_current_language();
		$language_form_field = "<input type='hidden' name='lang' value='" . esc_attr( $current_language ) . "' />";
		$language_form_field = apply_filters( 'wpml_language_form_input_field', $language_form_field, $current_language );
	}

	return $language_form_field;
}

function wpml_get_translation_type( $id, $type = 'post' ) {
	$translation_type = WPML_ELEMENT_IS_NOT_TRANSLATED;

	if ( $type == 'post' ) {
		$translation_type = wpml_post_has_translations( $id );
	}


	return $translation_type;
}

function wpml_get_element_translation_type_filter( $empty_value, $element_id, $element_type ) {
	$translation_type = WPML_ELEMENT_IS_NOT_TRANSLATED;

	$element_has_translations = apply_filters( 'wpml_element_has_translations', null, $element_id, $element_type );
	$element_is_master        = apply_filters( 'wpml_master_post_from_duplicate', $element_id );
	$element_is_duplicate     = apply_filters( 'wpml_post_duplicates', $element_id );

	if ( $element_has_translations ) {
		$translation_type = WPML_ELEMENT_IS_TRANSLATED;
		if ( $element_is_master ) {
			$translation_type = WPML_ELEMENT_IS_A_DUPLICATE;
		} elseif ( $element_is_duplicate ) {
			$translation_type = WPML_ELEMENT_IS_DUPLICATED;
		}
	}

	return $translation_type;
}

function wpml_get_post_translation_type( $post_id ) {
	$translation_type = WPML_ELEMENT_IS_NOT_TRANSLATED;

	$post_type = get_post_type( $post_id );
	if ( $post_type && wpml_post_has_translations( $post_id, $post_type ) ) {
		$translation_type = WPML_ELEMENT_IS_TRANSLATED;
		if ( wpml_get_master_post_from_duplicate( $post_id ) ) {
			$translation_type = WPML_ELEMENT_IS_A_DUPLICATE;
		} elseif ( wpml_get_post_duplicates( $post_id ) ) {
			$translation_type = WPML_ELEMENT_IS_DUPLICATED;
		}
	}

	return $translation_type;
}

function wpml_post_has_translations( $post_id, $post_type = 'post' ) {
	$has_translations = false;
	global $sitepress;
	if ( isset( $sitepress ) ) {
		$trid         = $sitepress->get_element_trid( $post_id, 'post_' . $post_type );
		$translations = $sitepress->get_element_translations( $trid );
		if ( $translations && count( $translations ) > 1 ) {
			$has_translations = true;
		}
	}

	return $has_translations;
}

function wpml_element_has_translations_filter( $empty_value, $element_id, $element_type = 'post' ) {
	$has_translations = false;
	global $sitepress;
	if ( isset( $sitepress ) ) {
		$wpml_element_type = apply_filters( 'wpml_element_type', $element_type );

		if ( strpos( $wpml_element_type, 'tax_' ) === 0 ) {
			global $wpml_term_translations;
			$element_id = $wpml_term_translations->adjust_ttid_for_term_id( $element_id );
		}

		$trid = $sitepress->get_element_trid( $element_id, $wpml_element_type );

		$translations = apply_filters( 'wpml_get_element_translations_filter', '', $trid, $wpml_element_type );

		if ( $translations && count( $translations ) > 1 ) {
			$has_translations = true;
		}
	}

	return $has_translations;
}

function wpml_get_content_translations_filter( $empty, $post_id, $content_type = 'post' ) {
	global $sitepress;
	$translations = array();
	if ( isset( $sitepress ) ) {
		$wpml_element_type = apply_filters( 'wpml_element_type', $content_type );

		$trid = $sitepress->get_element_trid( $post_id, $wpml_element_type );

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $wpml_element_type );
	}

	return $translations;
}

function wpml_get_master_post_from_duplicate( $post_id ) {
	return get_post_meta( $post_id, '_icl_lang_duplicate_of', true );
}

function wpml_get_master_post_from_duplicate_filter( $post_id ) {
	return get_post_meta( $post_id, '_icl_lang_duplicate_of', true );
}

function wpml_get_post_duplicates( $master_post_id ) {
	global $sitepress;
	if ( isset( $sitepress ) ) {
		return $sitepress->get_duplicates( $master_post_id );
	}

	return array();
}

function wpml_get_post_duplicates_filter( $master_post_id ) {
	global $sitepress;
	if ( isset( $sitepress ) ) {
		return $sitepress->get_duplicates( $master_post_id );
	}

	return array();
}

function wpml_element_type_filter( $element_type ) {
	global $wp_post_types, $wp_taxonomies;

	$post_types = array_keys( (array) $wp_post_types );
	$taxonomies = array_keys( (array) $wp_taxonomies );

	if ( in_array( $element_type, $taxonomies ) ) {
		$wpml_element_type = 'tax_' . $element_type;
	} elseif ( in_array( $element_type, $post_types ) ) {
		$wpml_element_type = 'post_' . $element_type;
	} else {
		$wpml_element_type = $element_type;
	}

	return $wpml_element_type;
}

function wpml_element_language_details_filter( $element_object, $args ) {
	global $sitepress;
	if ( isset( $sitepress ) ) {
		$element_type   = apply_filters( 'wpml_element_type', $args['element_type'] );
		$element_object = $sitepress->get_element_language_details( $args['element_id'], $element_type );
	}

	return $element_object;
}

function wpml_element_language_code_filter( $language_code, $args ) {
	global $sitepress;
	if ( isset( $sitepress ) ) {
		$element_type = apply_filters( 'wpml_element_type', $args['element_type'] );

		$language_code = $sitepress->get_language_for_element( $args['element_id'], $element_type );
	}

	return $language_code;
}

function wpml_elements_without_translations_filter( $element_ids, $args ) {
	global $sitepress;
	if ( isset( $sitepress ) ) {
		$element_type = apply_filters( 'wpml_element_type', $args['element_type'] );
		$element_ids  = $sitepress->get_elements_without_translations( $element_type, $args['target_language'], $args['source_language'] );
	}
	return $element_ids;
}

function wpml_permalink_filter( $url, $language_code = null ) {
	return apply_filters( 'wpml_permalink', $url, $language_code );
}

function wpml_switch_language_action( $language_code = null ) {
	global $sitepress;

	$sitepress->switch_lang( $language_code, true );
}

