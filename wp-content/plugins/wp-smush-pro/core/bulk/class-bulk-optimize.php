<?php

namespace Smush\Core\Bulk;

use Smush\Core\Helper;
use Smush\Core\Media\Media_Item_Cache;
use Smush\Core\Media\Media_Item_Optimizer;
use Smush\Core\Optimizer;
use Smush\Core\Png2Jpg\Png2Jpg_Optimization;
use Smush\Core\Resize\Resize_Optimization;
use Smush\Core\Smush\Smush_Media_Item_Stats;
use Smush\Core\Smush\Smush_Optimization;
use WDEV_Logger;

// TODO: [WPMUDEV SMUSH UI] does this file make sense now that we pause in background itself
class Bulk_Optimize {
	/**
	 * @var WDEV_Logger
	 */
	private $logger;

	public function __construct() {
		$this->logger = Helper::logger();
	}

	public function start_bulk_optimization() {
		do_action( 'wp_smush_bulk_smush_start' );
	}

	/**
	 * @param $attachment_id
	 *
	 * @return true|\WP_Error
	 */
	public function optimize_attachment( $attachment_id ) {
		$optimizer = Optimizer::get_instance();
		$optimized = $optimizer->optimize( $attachment_id );

		if ( ! $optimized ) {
			$this->logger->error( "Error encountered while bulk Smushing attachment ID $attachment_id:" . $optimizer->get_errors()->get_error_message() );

			return $optimizer->get_errors();
		}

		do_action( 'image_smushed', $attachment_id, $this->compile_stats( $attachment_id ) );

		return true;
	}

	public function complete_bulk_optimization() {
		do_action( 'wp_smush_bulk_smush_completed' );
	}

	public function compile_stats( $attachment_id ) {
		$media_item         = Media_Item_Cache::get_instance()->get( $attachment_id );
		$optimizer          = new Media_Item_Optimizer( $media_item );
		$smush_optimization = $optimizer->get_optimization( Smush_Optimization::get_key() );
		/**
		 * @var Smush_Media_Item_Stats $smush_stats
		 */
		$smush_stats          = $smush_optimization->get_stats();
		$resize_optimization  = $optimizer->get_optimization( Resize_Optimization::get_key() );
		$png2jpg_optimization = $optimizer->get_optimization( Png2Jpg_Optimization::get_key() );

		return array(
			'count'              => $smush_optimization->get_optimized_sizes_count(),
			'size_before'        => $smush_stats->get_size_before(),
			'size_after'         => $smush_stats->get_size_after(),
			'savings_resize'     => $resize_optimization ? $resize_optimization->get_stats()->get_bytes() : 0,
			'savings_conversion' => $png2jpg_optimization ? $png2jpg_optimization->get_stats()->get_bytes() : 0,
			'is_lossy'           => $smush_stats->is_lossy(),
		);
	}
}