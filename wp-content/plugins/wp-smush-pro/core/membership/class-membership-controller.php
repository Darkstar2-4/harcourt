<?php

namespace Smush\Core\Membership;

use Smush\Core\Controller;
use Smush\Core\Helper;

class Membership_Controller extends Controller {
	/**
	 * @var Membership
	 */
	private $membership;

	public function __construct() {
		$this->membership = Membership::get_instance();
		$this->register_action( 'wp_ajax_recheck_api_status', array( $this, 'recheck_api_status' ) );
	}

	public function recheck_api_status() {
		check_ajax_referer( 'wp-smush-ajax' );

		if ( ! Helper::is_user_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wp-smushit' ) ), 403 );
		}

		$membership = Membership::get_instance();
		$membership->validate_install( true );

		$is_pro = $membership->is_pro();

		wp_send_json_success(
			array(
				'isPro'  => $is_pro,
				'status' => $is_pro ? 'valid' : 'invalid',
			)
		);
	}
}