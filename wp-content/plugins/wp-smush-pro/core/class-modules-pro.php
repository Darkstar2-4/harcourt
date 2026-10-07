<?php

namespace Smush\Core;

use Smush\Core\Avif\Avif_Controller;
use Smush\Core\CDN\CDN_Controller;
use Smush\Core\CDN\CDN_Settings_Ui_Controller;
use Smush\Core\CDN\CDN_Srcset_Controller;
use Smush\Core\Image_Dimensions\Image_Dimensions_Controller;
use Smush\Core\LCP\LCP_Admin_Controller;
use Smush\Core\LCP\LCP_Controller;
use Smush\Core\Membership\Membership_Controller;
use Smush\Core\Modules\CDN;
use Smush\Core\Next_Gen\Next_Gen_Controller;
use Smush\Core\Png2Jpg\Png2Jpg_Controller;
use Smush\Core\Resize\Auto_Resizing_Controller;
use Smush\Core\S3\S3_Controller;
use Smush\Core\Webp\Webp_Controller;
use Smush\Core\Webp\Webp_Retrospective_Stats_Generator;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Modules
 */
class Modules_Pro extends Modules {
	public function __construct() {
		parent::__construct();

		$webp_controller = new Webp_Controller();
		$webp_controller->init();

		$s3_controller = new S3_Controller();
		$s3_controller->init();

		// Auto-resizing.
		$auto_resizing_controller = new Auto_Resizing_Controller();
		$auto_resizing_controller->init();

		// CDN.
		$cdn_controller = new CDN_Controller();
		$cdn_controller->init();

		$cdn_settings_ui_controller = new CDN_Settings_Ui_Controller();
		$cdn_settings_ui_controller->init();

		$cdn_srcset_controller = CDN_Srcset_Controller::get_instance();
		$cdn_srcset_controller->init();

		$avif_controller = new Avif_Controller();
		$avif_controller->init();

		$webp_retrospective_stats = new Webp_Retrospective_Stats_Generator();
		$webp_retrospective_stats->init();

		$next_gen_controller = new Next_Gen_Controller();
		$next_gen_controller->init();

		$lcp_controller = new LCP_Controller();
		$lcp_controller->init();

		$lcp_admin_controller = new LCP_Admin_Controller();
		$lcp_admin_controller->init();

		$image_dimensions_controller = new Image_Dimensions_Controller();
		$image_dimensions_controller->init();

		$auto_resizing_controller = new Auto_Resizing_Controller();
		$auto_resizing_controller->init();

		$membership_controller = new Membership_Controller();
		$membership_controller->init();
	}

	protected function get_smush_module() {
		return new Modules\Smush_Pro();
	}
}