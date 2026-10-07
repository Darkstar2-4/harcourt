<?php

namespace Smush\Core\Resize;

use Smush\Core\Array_Utils;
use Smush\Core\Controller;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Stats\Media_Item_Optimization_Global_Stats_Persistable;

class Resize_Controller extends Controller {
	private static $global_stats_option_id = 'wp-smush-resize-global-stats';
	private static $resize_optimization_order = 10;
	/**
	 * @var Global_Stats
	 */
	private $global_stats;
	/**
	 * @var Array_Utils
	 */
	private $array_utils;

	public function __construct() {
		$this->global_stats = Global_Stats::get();
		$this->array_utils  = new Array_Utils();

		$this->register_filter( 'wp_smush_optimizations', array(
			$this,
			'add_resize_optimization',
		), self::$resize_optimization_order, 2 );
		$this->register_filter( 'wp_smush_global_optimization_stats', array( $this, 'add_resize_global_stats' ) );
		$this->register_filter( 'wp_smush_global_stats_digest_keys', array( $this, 'add_digest_keys' ) );
		$this->register_filter( 'wp_smush_global_stats_digest_options', array( $this, 'add_digest_options' ) );
	}

	public function add_digest_keys( $keys ) {
		$keys[] = 'resize';

		return $keys;
	}

	public function add_digest_options( $options ) {
		$options[] = 'wp-smush-resize_sizes';

		return $options;
	}

	public function add_resize_optimization( $optimizations, $media_item ) {
		$resize_optimization                              = new Resize_Optimization( $media_item );
		$optimizations[ $resize_optimization->get_key() ] = $resize_optimization;

		return $optimizations;
	}

	public function add_resize_global_stats( $stats ) {
		$stats[ Resize_Optimization::get_key() ] = new Media_Item_Optimization_Global_Stats_Persistable( self::$global_stats_option_id );

		return $stats;
	}
}