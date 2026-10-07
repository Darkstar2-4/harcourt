<?php
/**
 * WhiteLabel.
 *
 * @package Smush\Core
 */

namespace Smush\Core\Modules\Helpers;

use WPMUDEV_Dashboard;

defined( 'ABSPATH' ) || exit;
/**
 * Class WhiteLabel
 */
class WhiteLabel {
	const PLUGIN_ID = 912164;

	/**
	 * Whether to activate white label.
	 *
	 * @return bool
	 */
	public function enabled() {
		if (
			! class_exists( '\WPMUDEV_Dashboard' ) ||
			empty( WPMUDEV_Dashboard::$whitelabel ) ||
			! method_exists( WPMUDEV_Dashboard::$whitelabel, 'can_whitelabel' )
		) {
			return false;
		}

		return WPMUDEV_Dashboard::$whitelabel->can_whitelabel();
	}

	/**
	 * Whether white labeling is enabled.
	 *
	 * Alias of {@see enabled()} provided for clarity.
	 *
	 * @return bool
	 */
	public function is_whitelabel_enabled() {
		return $this->enabled();
	}

	/**
	 * Whether to hide branding or not.
	 *
	 * @return bool
	 */
	public function hide_branding() {
		return apply_filters( 'wpmudev_branding_hide_branding', false );
	}

	/**
	 * Whether branding should be hidden.
	 *
	 * Alias of {@see hide_branding()} provided for readability.
	 *
	 * @return bool
	 */
	public function should_hide_branding() {
		return $this->hide_branding();
	}

	/**
	 * Whether to hide doc link or not.
	 *
	 * @return bool
	 */
	public function hide_doc_link() {
		return apply_filters( 'wpmudev_branding_hide_doc_link', false );
	}

	/**
	 * Whether to hide pro tag or not.
	 *
	 * @return bool
	 */
	public function should_hide_pro_tag() {
		return apply_filters( 'wpmudev_branding_hide_pro_tag', $this->is_whitelabel_enabled() );
	}

	public function remove_brand_links( $text ) {
		if ( ! $this->should_hide_doc_link() ) {
			return $text;
		}

		return preg_replace(
			'#\s*<a\b[^>]*\bhref=(["\'])(?:https?:)?//(?:www\.)?wpmudev\.com/[^"\']*\1[^>]*>.*?</a>#is',
			'',
			$text
		);
	}

	/**
	 * Whether documentation links should be hidden.
	 *
	 * Alias of {@see hide_doc_link()} provided for readability.
	 *
	 * @return bool
	 */
	public function should_hide_doc_link() {
		return $this->hide_doc_link();
	}

	/**
	 * Whether custom labels are enabled/configured for this plugin.
	 *
	 * @return bool
	 */
	private function is_plugin_labeling_enabled() {
		if ( ! $this->enabled() ) {
			return false;
		}
		if (
			! method_exists( WPMUDEV_Dashboard::$whitelabel, 'get_settings' )
		) {
			return false;
		}
		$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();
		return ! empty( $whitelabel_settings['labels_enabled'] ) && ! empty( $whitelabel_settings['labels_config'][ self::PLUGIN_ID ] );
	}

	/**
	 * Get custom plugin label.
	 *
	 * @return bool|string
	 */
	public function get_plugin_name() {
		if ( ! $this->is_plugin_labeling_enabled() ) {
			return false;
		}
		$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();
		if ( empty( $whitelabel_settings['labels_config'][ self::PLUGIN_ID ]['name'] ) ) {
			return false;
		}
		return $whitelabel_settings['labels_config'][ self::PLUGIN_ID ]['name'];
	}

	/**
	 * Get the custom plugin label.
	 *
	 * Alias of {@see get_plugin_name()} provided for clarity.
	 *
	 * @return bool|string
	 */
	public function get_custom_plugin_name() {
		return $this->get_plugin_name();
	}

	/**
	 * Get custom plugin logo url.
	 *
	 * @return bool|string
	 */
	public function get_plugin_logo() {
		if ( ! $this->is_plugin_labeling_enabled() ) {
			return false;
		}
		$whitelabel_settings = WPMUDEV_Dashboard::$whitelabel->get_settings();
		$plugin_settings     = $whitelabel_settings['labels_config'][ self::PLUGIN_ID ];

		if ( empty( $plugin_settings['icon_type'] ) ) {
			return false;
		}
		if ( 'link' === $plugin_settings['icon_type'] && ! empty( $plugin_settings['icon_url'] ) ) {
			return $plugin_settings['icon_url'];
		}
		if ( 'upload' === $plugin_settings['icon_type'] && ! empty( $plugin_settings['thumb_id'] ) ) {
			return wp_get_attachment_image_url( $plugin_settings['thumb_id'], 'full' );
		}
		if( 'dashicon' === $plugin_settings['icon_type'] && ! empty( $plugin_settings['icon_class'] ) ) {
			// Dashicons don't have a URL, so we return the dashicon class instead.
			return $plugin_settings['icon_class'];
		}

		return false;
	}

	/**
	 * Get custom plugin logo URL.
	 *
	 * Alias of {@see get_plugin_logo()} provided for clarity.
	 *
	 * @return bool|string
	 */
	public function get_plugin_logo_url() {
		return $this->get_plugin_logo();
	}

	/**
	 * Removes branding strings from the given text if white-labeling is enabled.
	 *
	 * @param string $text The input string potentially containing branding.
	 * @return string The modified string with branding removed if applicable.
	 */
	public function whitelabel_string( $text ) {
		return $this->replace_branding_terms( $text );
	}

	/**
	 * Replace branding terms in a string when white-label is enabled.
	 *
	 * @param string $text Input text.
	 * @return string Output text.
	 */
	public function replace_branding_terms( $text, $replacement_terms = array() ) {
		if ( ! $this->is_whitelabel_enabled() ) {
			return $text;
		}

		$replacement_terms = array_merge(
			array(
				'Smush CDN'    => 'CDN',
				'WPMU DEV CDN' => 'CDN',
			),
			$replacement_terms
		);

		$custom_plugin_name = $this->get_custom_plugin_name();
		if ( $custom_plugin_name ) {
			$replacement_terms['Smush'] = $custom_plugin_name;
		}

		// NOTE: Filter name contains a legacy typo and should not be changed lightly.
		$replacement_terms = apply_filters( 'wp_smush_whiltelabel_replacement_terms', $replacement_terms );

		return strtr( $text, $replacement_terms );
	}

	/**
	 * Get the white-label text.
	 *
	 * @param mixed $original_text The original text.
	 * @param mixed $whitelabel_text The white-labeled text.
	 * @return mixed
	 */
	public function get_whitelabel_text( $original_text, $whitelabel_text ) {
		if ( ! $this->is_whitelabel_enabled() ) {
			return $original_text;
		}

		return $whitelabel_text;
	}

	/**
	 * Get white label data.
	 *
	 * @return array
	 */
	public function get_whitelabel_data() {
		$whitelabel_data = array(
			'whitelabelEnabled' => false,
		);

		if ( $this->is_whitelabel_enabled() ) {
			$icon_url   = '';
			$icon_class = '';

			if ( $this->is_plugin_labeling_enabled() ) {
				$settings        = WPMUDEV_Dashboard::$whitelabel->get_settings();
				$plugin_settings = $settings['labels_config'][ self::PLUGIN_ID ];
				$icon_type       = isset( $plugin_settings['icon_type'] ) ? (string) $plugin_settings['icon_type'] : '';

				if ( 'none' === $icon_type ) {
					// null tells the JS side to hide the icon entirely.
					$icon_url = null;
				} elseif ( 'dashicon' === $icon_type && ! empty( $plugin_settings['icon_class'] ) ) {
					$icon_class = $plugin_settings['icon_class'];
				} elseif ( 'link' === $icon_type && ! empty( $plugin_settings['icon_url'] ) ) {
					$icon_url = $plugin_settings['icon_url'];
				} elseif ( 'upload' === $icon_type && ! empty( $plugin_settings['thumb_id'] ) ) {
					$icon_url = wp_get_attachment_image_url( $plugin_settings['thumb_id'], 'full' );
				}
			}

			$whitelabel_data = array(
				'whitelabelEnabled'     => true,
				'customPluginIconUrl'   => $icon_url,
				'customPluginIconClass' => $icon_class,
				'customPluginName'      => $this->get_custom_plugin_name(),
				'customMadeBy'          => apply_filters( 'wp_smush_whitelabel_custom_made_by', '' ),
				'hideBranding'          => $this->should_hide_branding(),
				'hideDocLink'           => $this->should_hide_doc_link(),
				'hideProTag'          => $this->should_hide_pro_tag(),
			);
		}

		return $whitelabel_data;
	}
}