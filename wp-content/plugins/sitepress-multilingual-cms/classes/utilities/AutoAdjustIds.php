<?php

namespace WPML\Utils;

use SitePress;
use WPML_WP_API;

class AutoAdjustIds {
	const WITH    = true;
	const WITHOUT = false;

	private $sitepress;

	private $wp;

	public function __construct(
		SitePress $sitepress,
		?WPML_WP_API $wp = null
	) {
		$this->sitepress = $sitepress;
		$this->wp        = $wp ?: $sitepress->get_wp_api();
	}

	public function runWith( callable $function ) {
		return $this->runWithOrWithout( self::WITH, $function );
	}

	public function runWithout( callable $function ) {
		return $this->runWithOrWithout( self::WITHOUT, $function );
	}

	private function runWithOrWithout( $withOrWithout, callable $function ) {
		$adjust_id_original_state =
			$this->adjustSettingAutoAdjustId( $withOrWithout );
		$get_term_original_state  =
			$this->adjustGetTermFilter( $withOrWithout );
		$get_page_original_state  =
			$this->adjustGetPagesFilter( $withOrWithout );

		$result = $function();

		$this->adjustSettingAutoAdjustId( $adjust_id_original_state );
		$this->adjustGetTermFilter( $get_term_original_state );
		$this->adjustGetPagesFilter( $get_page_original_state );

		return $result;
	}

	private function adjustSettingAutoAdjustId( $enable = true ) {
		$is_setting_enabled =
			$this->sitepress->get_setting( 'auto_adjust_ids', false );

		if ( $enable !== $is_setting_enabled ) {
			$this->sitepress->set_setting( 'auto_adjust_ids', $enable );
		}

		return $is_setting_enabled;
	}


	private function adjustGetTermFilter( $add_filter = true ) {
		$is_filter_added =
			$this->wp->has_filter(
				'get_term',
				[ $this->sitepress, 'get_term_adjust_id' ]
			);

		if ( $add_filter !== $is_filter_added ) {
			$add_filter ? $this->wp->add_filter(
				'get_term',
				[ $this->sitepress, 'get_term_adjust_id' ],
				1
			) : $this->wp->remove_filter(
				'get_term',
				[ $this->sitepress, 'get_term_adjust_id' ],
				1
			);
		}

		return $is_filter_added;
	}

	private function adjustGetPagesFilter( $add_filter = true ) {
		$is_filter_added =
			$this->wp->has_filter(
				'get_pages',
				[ $this->sitepress, 'get_pages_adjust_ids' ]
			);

		if ( $add_filter !== $is_filter_added ) {
			$add_filter ? $this->wp->add_filter(
				'get_pages',
				[ $this->sitepress, 'get_pages_adjust_ids' ],
				1,
				2
			) : $this->wp->remove_filter(
				'get_pages',
				[ $this->sitepress, 'get_pages_adjust_ids' ],
				1
			);
		}

		return $is_filter_added;
	}
}

