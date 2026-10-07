<?php

namespace Smush\Core;

use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Media\Media_Item_Optimizer;
use Smush\Core\Media_Library\Media_Library_Row;
use Smush\Core\Membership\Membership;
use Smush\Core\Smush\Smush_Optimization;
use Smush\Core\Smush\Smusher;
use Smush\Core\Smush\Smusher_Options_Provider;
use Smush\Core\Stats\Global_Stats;

/**
 * // TODO: [WPMUDEV SMUSH UI] create tests
 */
class Optimization_Controller extends Controller {

	/**
	 * @var Optimization_Controller
	 */
	private static $instance;
	/**
	 * @var Global_Stats
	 */
	private $global_stats;

	private $membership;
	/**
	 * @var Settings
	 */
	private $settings;
	private $media_item_cache;
	private $optimizer;

	private function __construct() {
		$this->global_stats     = Global_Stats::get();
		$this->membership       = Membership::get_instance();
		$this->settings         = Settings::get_instance();
		$this->media_item_cache = Media_Item_Cache::get_instance();
		$this->optimizer        = Optimizer::get_instance();

		$this->register_action( 'wp_smush_image_sizes_changed', array( $this, 'mark_global_stats_as_outdated' ) );

		$this->register_action( 'wp_ajax_optimize_attachment', array( $this, 'optimize_attachment' ) );
		$client_side_media_processing_filter_priority = 100;
		$this->register_filter(
			'wp_client_side_media_processing_enabled',
			array( $this, 'disable_client_side_media_processing' ),
			$client_side_media_processing_filter_priority
		);
		$this->register_action( 'wp_async_wp_generate_attachment_metadata', array(
			$this,
			'auto_optimize_attachment_async',
		) );
		$this->register_filter(
			'wp_generate_attachment_metadata',
			array( $this, 'maybe_auto_optimize_attachment_sync' ), 15, 2
		);
		$this->register_action( 'wp_async_wp_save_image_editor_file', array( $this, 'handle_editor_upload_async' ), '', 2 );
		// Fix SSL CA certificates issue.
		$this->register_action( 'wp_smush_before_smush_file', array( $this, 'fix_ssl_ca_certificate_error' ) );
	}

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function disable_client_side_media_processing( $_enabled ) {
		return false;
	}

	public function mark_global_stats_as_outdated() {
		$this->global_stats->mark_as_outdated();
	}

	public function optimize_attachment() {
		if ( ! isset( $_REQUEST['attachment_id'] ) ) {
			wp_send_json_error(
				array( 'error_msg' => esc_html__( 'No attachment ID was provided.', 'wp-smushit' ) )
			);
		}

		if ( ! check_ajax_referer( 'wp-smush-ajax', '_nonce', false ) ) {
			wp_send_json_error(
				array( 'error_msg' => esc_html__( 'Nonce verification failed', 'wp-smushit' ) )
			);
		}

		if ( ! Helper::is_user_allowed( 'upload_files' ) ) {
			wp_send_json_error(
				array( 'error_msg' => esc_html__( "You don't have permission to work with uploaded files.", 'wp-smushit' ) )
			);
		}

		if ( $this->membership->is_api_hub_access_required() ) {
			wp_send_json_error(
				array( 'error_msg' => esc_html__( 'A WPMU DEV Hub connection is required to optimize images.', 'wp-smushit' ) )
			);
		}

		$attachment_id  = (int) $_REQUEST['attachment_id'];
		$optimizer      = $this->optimizer;
		$is_optimized   = $optimizer->optimize( $attachment_id );
		$media_lib_item = Media_Library_Row::get_instance( $attachment_id );
		$markup         = $media_lib_item->generate_markup();

		if ( $is_optimized ) {
			wp_send_json_success( $markup );
		} else {
			$errors = $optimizer->get_errors();

			wp_send_json_error( array(
				'error'        => $errors->get_error_code(),
				'error_msg'    => $errors->get_error_message(),
				'html_stats'   => $markup,
				'show_warning' => $this->membership->should_show_premium_status_warning( $attachment_id ),
			) );
		}
	}

	public function auto_optimize_attachment_async( $id ) {
		// If we don't have image id or auto Smush is disabled, return.
		if ( empty( $id ) || ! $this->optimizer->should_auto_optimize( $id ) ) {
			return;
		}

		$this->optimizer->optimize( $id );
	}

	public function maybe_auto_optimize_attachment_sync( $meta, $id ) {
		if ( defined( 'WP_SMUSH_ASYNC' ) && WP_SMUSH_ASYNC ) {
			return $meta;
		}

		// We need to check if this call originated from Gutenberg and allow only media.
		$is_rest_non_media_request = Helper::is_non_rest_media();
		if ( $is_rest_non_media_request ) {
			// If not - return image metadata.
			return $meta;
		}

		$generating_metadata = doing_filter( 'wp_generate_attachment_metadata' );
		if ( $generating_metadata && ! $this->optimizer->should_auto_optimize( $id ) ) {
			return $meta;
		}

		$this->optimizer->optimize( $id );

		return $meta;
	}

	/**
	 * This method runs when a media item is edited.
	 *
	 * TODO: this method has been replicated from another method but there are unanswered questions:
	 * - Can't we optimize the full media item instead of just the full size?
	 * - We should probably not do anything if the media item was not previously optimized, because in that case we can just treat it as the other unoptimized items and take care of it during bulk smush.
	 */
	public function handle_editor_upload_async( $id, $post_data ) {
		if ( ! $this->optimizer->should_auto_optimize( $id ) ) {
			return;
		}

		$filepath = empty( $post_data['filepath'] ) ? '' : $post_data['filepath'];
		if ( ! $filepath || ! file_exists( $filepath ) ) {
			return;
		}

		// Get before stats
		$before_file_size = filesize( $filepath );

		$smusher_options = ( new Smusher_Options_Provider() )->get_options();
		$smusher         = new Smusher( $smusher_options );
		$smusher->smush( array( $filepath ) );
		if ( $smusher->has_errors() ) {
			return;
		}

		$media_item    = Media_Item_Cache::get_instance()->get( $id );
		$attached_file = $media_item->get_attached_file();
		if ( $attached_file !== $filepath ) {
			return;
		}

		$after_file_size                    = filesize( $filepath );
		$media_item_optimizer               = new Media_Item_Optimizer( $media_item );
		$smush_optimization                 = $media_item_optimizer->get_optimization( Smush_Optimization::get_key() );
		$smush_optimization_total_stats     = $media_item_optimizer->get_stats( Smush_Optimization::get_key() );
		$smush_optimization_full_size_stats = $media_item_optimizer->get_size_stats(
			Smush_Optimization::get_key(),
			$media_item->get_main_size()->get_key()
		);

		if ( $smush_optimization->is_optimized() ) {
			$smush_optimization_total_stats->set_size_before(
				$smush_optimization_total_stats->get_size_before()
				- $smush_optimization_full_size_stats->get_size_before()
				+ $before_file_size
			);
			$smush_optimization_total_stats->set_size_after(
				$smush_optimization_total_stats->get_size_after()
				- $smush_optimization_full_size_stats->get_size_after()
				+ $after_file_size
			);
			$smush_optimization_full_size_stats->set_size_before( $before_file_size );
			$smush_optimization_full_size_stats->set_size_after( $after_file_size );
			$smush_optimization->save();
		}
	}

	/**
	 * Fix SSL CA Certificate issue.
	 *
	 * @since 3.9.6
	 *
	 * Check for use of http url (Hostgator mostly) - got it from smush_image.
	 */
	public function fix_ssl_ca_certificate_error() {
		// Return if the member defined it.
		if ( defined( 'WP_SMUSH_API_HTTP' ) ) {
			return;
		}
		static $use_http;
		/**
		 * Fix for Hostgator.
		 * Check for use of http url (Hostgator mostly).
		 */
		if ( is_null( $use_http ) ) {
			$use_http = $this->settings->get_setting( 'wp-smush-use_http' );
		}

		if ( $use_http ) {
			define( 'WP_SMUSH_API_HTTP', 'http://smushpro.wpmudev.com/1.0/' );
		}
	}
}