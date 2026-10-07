<?php
/**
 * Class Modules.
 *
 * Used in Core to type hint the $mod variable. For example, this way any calls to
 * \Smush\WP_Smush::get_instance()->core()->mod->settings will be typehinted as a call to Settings module.
 *
 * @package Smush\Core
 */

namespace Smush\Core;

use Smush\Core\Background\Background_Pre_Flight_Controller;
use Smush\Core\Backups\Backups_Controller;
use Smush\Core\Cache\Cache_Controller;
use Smush\Core\Frontend\Frontend_Controller;
use Smush\Core\Frontend\Multisite_Frontend_Controller;
use Smush\Core\Lazy_Load\Lazy_Load_Controller;
use Smush\Core\Lazy_Load\Video_Embed\Video_Thumbnail_Controller;
use Smush\Core\Media\Attachment_Url_Cache_Controller;
use Smush\Core\Media\Media_Item_Controller;
use Smush\Core\Media_Library\Ajax_Media_Library_Scanner;
use Smush\Core\Media_Library\Background_Media_Library_Scanner;
use Smush\Core\Media_Library\Media_Library_Last_Process;
use Smush\Core\Media_Library\Media_Library_Slice_Data_Fetcher;
use Smush\Core\Media_Library\Media_Library_Watcher;
use Smush\Core\Modules\CDN;
use Smush\Core\Photon\Photon_Controller;
use Smush\Core\Rating_Notification\Rating_Notification_Controller;
use Smush\Core\Resize\Resize_Controller;
use Smush\Core\Security\Security_Controller;
use Smush\Core\Smush\Smush_Controller;
use Smush\Core\Stats\Global_Stats_Controller;
use Smush\Core\Transform\Transformation_Controller;
use Smush\Core\Png2Jpg\Png2Jpg_Controller;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Modules
 */
class Modules {

	/**
	 * Directory Smush module.
	 *
	 * @var Modules\Dir
	 */
	public $dir;

	/**
	 * Main Smush module.
	 *
	 * @var Modules\Smush
	 */
	public $smush;

	/**
	 * Cache background optimization controller - Bulk_Smush_Controller
	 *
	 * @var \Smush\Core\Bulk\Background_Bulk_Smush_Controller
	 */
	public $bg_optimization;

	/**
	 * @var Product_Analytics\Product_Analytics_Controller
	 */
	public $product_analytics;

	public $backward_compatibility;

	public static function get_instance() {
		return new Modules_Pro();
	}

	/**
	 * Modules constructor.
	 */
	public function __construct() {
		new Deprecated_Hooks();// Handle deprecated hooks.

		new Api\Hub(); // Init hub endpoints.

		new Rest();

		if ( is_admin() ) {
			$this->dir = new Modules\Dir();
		}

		$this->smush = $this->get_smush_module();

		$transformation_controller = new Transformation_Controller();
		$transformation_controller->init();

		$this->product_analytics = Product_Analytics\Product_Analytics_Controller::get_instance();

		$png2jpg_controller = Png2Jpg_Controller::get_instance();
		$png2jpg_controller->init();

		$this->bg_optimization = Bulk\Background_Bulk_Smush_Controller::get_instance();

		Bulk\Bulk_Smush_Session_Savings::get_instance()->init();

		$smush_controller = Smush_Controller::get_instance();
		$smush_controller->init();

		$resize_controller = new Resize_Controller();
		$resize_controller->init();

		$backups_controller = new Backups_Controller();
		$backups_controller->init();

		$library_scanner = new Ajax_Media_Library_Scanner();
		$library_scanner->init();

		$background_lib_scanner = Background_Media_Library_Scanner::get_instance();
		$background_lib_scanner->init();

		$media_library_watcher = new Media_Library_Watcher();
		$media_library_watcher->init();

		$global_stats_controller = Global_Stats_Controller::get_instance();
		$global_stats_controller->init();

		$plugin_settings_watcher = new Plugin_Settings_Watcher();
		$plugin_settings_watcher->init();

		$animated_status_controller = new Animated_Status_Controller();
		$animated_status_controller->init();

		$media_library_slice_data_fetcher = new Media_Library_Slice_Data_Fetcher( is_multisite(), get_current_blog_id() );
		$media_library_slice_data_fetcher->init();

		$media_item_controller = new Media_Item_Controller();
		$media_item_controller->init();

		$optimization_controller = Optimization_Controller::get_instance();
		$optimization_controller->init();

		$photon_controller = new Photon_Controller();
		$photon_controller->init();

		$cache_controller = new Cache_Controller();
		$cache_controller->init();

		$lazy_load_controller = Lazy_Load_Controller::get_instance();
		$lazy_load_controller->init();

		( new Video_Thumbnail_Controller() )->init();

		$background_health = Background_Pre_Flight_Controller::get_instance();
		$background_health->init();

		$media_lib_last_process = Media_Library_Last_Process::get_instance();
		$media_lib_last_process->init();

		$cron_controller = Cron_Controller::get_instance();
		$cron_controller->init();

		$security_controller = Security_Controller::get_instance();
		$security_controller->init();

		$attachment_url_cache_controller = new Attachment_Url_Cache_Controller();
		$attachment_url_cache_controller->init();

		$hub_connector = new Hub_Connector();
		$hub_connector->init();

		$frontend_controller = is_multisite()
			? Multisite_Frontend_Controller::get_instance()
			: Frontend_Controller::get_instance();
		$frontend_controller->init();

		$settings_controller = new Settings_Controller();
		$settings_controller->init();

		$rating_notification_controller = new Rating_Notification_Controller();
		$rating_notification_controller->init();

		$activity_log_controller = Activity_Log_Controller::get_instance();
		$activity_log_controller->init();

		$configs_controller = Configs_Controller::get_instance();
		$configs_controller->init();
	}

	protected function get_smush_module() {
		return new Modules\Smush();
	}
}