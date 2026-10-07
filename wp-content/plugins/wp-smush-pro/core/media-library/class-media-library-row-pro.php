<?php

namespace Smush\Core\Media_Library;

use Smush\Core\Avif\Avif_Optimization;
use Smush\Core\CDN\CDN_Helper;
use Smush\Core\Helper;
use Smush\Core\Media\Media_Item_Optimization;
use Smush\Core\Membership\Membership;
use Smush\Core\Next_Gen\Next_Gen_Manager;
use Smush\Core\Png2Jpg\Png2Jpg_Optimization;
use Smush\Core\Webp\Webp_Optimization;
use WP_Smush;

class Media_Library_Row_Pro extends Media_Library_Row {
	protected function get_ordered_optimization_keys() {
		$optimization_keys   = parent::get_ordered_optimization_keys();
		$optimization_keys[] = Png2Jpg_Optimization::get_key();
		return $optimization_keys;
	}

	protected function get_animated_html_utm_link() {
		if ( Membership::get_instance()->is_pro() ) {
			return $this->get_animated_cdn_notice_with_config_link();
		}

		return parent::get_animated_html_utm_link();
	}

	private function get_animated_cdn_notice_with_config_link() {
		if ( CDN_Helper::get_instance()->is_cdn_active() ) {
			return '<span class="smush-cdn-notice">' . esc_html__( 'GIFs are serving from global CDN', 'wp-smushit' ) . '</span>';
		}
		$cdn_link = Helper::get_page_url( 'smush-cdn' );

		return '<span class="smush-cdn-notice">' . sprintf(
		/* translators: %1$s : Open a link %2$s Close the link */
			esc_html__( '%1$sEnable CDN%2$s to serve GIFs closer and faster to visitors', 'wp-smushit' ),
			'<a href="' . esc_url( $cdn_link ) . '" target="_blank">',
			'</a>'
		) . '</span>';
	}

	protected function get_filesize_limit_utm_link() {
		if ( Membership::get_instance()->is_pro() ) {
			return '';
		}

		return parent::get_filesize_limit_utm_link();
	}

	/**
	 * @return Media_Item_Optimization|null
	 */
	protected function get_active_nextgen_optimization() {
		if ( ! $this->is_nextgen_active() ) {
			return null;
		}

		if ( $this->settings->is_avif_module_active() ) {
			return $this->optimizer->get_optimization( Avif_Optimization::get_key() );
		} elseif ( $this->settings->is_webp_module_active() ) {
			return $this->optimizer->get_optimization( Webp_Optimization::get_key() );
		}

		return null;
	}

	/**
	 * @return bool
	 */
	private function is_nextgen_active() {
		if ( $this->settings->is_cdn_active() ) {
			return false;
		}

		return Next_Gen_Manager::get_instance()->is_active();
	}
}