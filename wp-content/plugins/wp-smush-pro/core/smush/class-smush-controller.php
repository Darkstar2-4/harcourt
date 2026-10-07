<?php

namespace Smush\Core\Smush;

use Smush\Core\Array_Utils;
use Smush\Core\Backups\Backups;
use Smush\Core\Controller;
use Smush\Core\Media\Media_Item;
use Smush\Core\Security\Security_Utils;
use Smush\Core\Settings;
use Smush\Core\Stats\Global_Stats;
use Smush\Core\Stats\Media_Item_Optimization_Global_Stats_Persistable;
use Smush\Core\Webp\Webp_Converter;
use WP_Smush;

class Smush_Controller extends Controller {
	private static $global_stats_option_id = 'wp-smush-optimization-global-stats';
	private static $smush_optimization_order = 40;

	private $global_stats;
	/**
	 * Static instance
	 *
	 * @var self
	 */
	private static $instance;

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->global_stats   = Global_Stats::get();

		$this->register_filter(
			'wp_smush_optimizations',
			array(
				$this,
				'add_smush_optimization',
		), self::$smush_optimization_order, 2 );
		$this->register_filter( 'wp_smush_global_optimization_stats', array( $this, 'add_smush_global_stats' ) );
		$this->register_filter( 'wp_smush_optimization_global_stats_instance', array(
				$this,
				'create_global_stats_instance',
			),
			10,
			2
		);

		$this->register_action( 'wp_smush_image_sizes_deleted', array( $this->global_stats, 'mark_as_outdated' ) );
		$this->register_action( 'wp_smush_image_sizes_added', array( $this->global_stats, 'mark_as_outdated' ) );
	}

	/**
	 * @param $optimizations array
	 * @param $media_item Media_Item
	 *
	 * @return array
	 */
	public function add_smush_optimization( $optimizations, $media_item ) {
		$optimization                              = new Smush_Optimization( $media_item );
		$optimizations[ $optimization->get_key() ] = $optimization;

		return $optimizations;
	}

	public function add_smush_global_stats( $stats ) {
		$stats[ Smush_Optimization::get_key() ] = new Media_Item_Optimization_Global_Stats_Persistable(
			self::$global_stats_option_id,
			new Smush_Optimization_Global_Stats()
		);

		return $stats;
	}

	public function create_global_stats_instance( $original, $key ) {
		if ( $key === Smush_Optimization::get_key() ) {
			return new Smush_Optimization_Global_Stats();
		}

		return $original;
	}
}