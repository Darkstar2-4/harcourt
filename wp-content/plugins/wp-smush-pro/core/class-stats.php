<?php
/**
 * Class that is responsible for all stats calculations.
 *
 * @since 3.4.0
 * @package Smush\Core
 */

namespace Smush\Core;

use Smush\Core\Media\Media_Item;
use Smush\Core\Media\Media_Item_Query;
use Smush\Core\Png2Jpg\Png2Jpg_Optimization;
use Smush\Core\Resize\Resize_Optimization;
use Smush\Core\Smush\Smush_Optimization;
use Smush\Core\Smush\Smush_Optimization_Global_Stats;
use Smush\Core\Stats\Global_Stats;
use stdClass;
use WP_Query;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Stats
 */
class Stats {

	/**
	 * Stores the stats for all the images.
	 *
	 * @var array $stats
	 */
	public $stats;

	/**
	 * Compressed attachments from selected directories.
	 *
	 * @var array $dir_stats
	 */
	public $dir_stats;

	/**
	 * Set a limit of MySQL query. Default: 3000.
	 *
	 * @var int $query_limit
	 */
	private $query_limit;

	/**
	 * Set a limit to max number of rows in MySQL query. Default: 5000.
	 *
	 * @var int $max_rows
	 */
	private $max_rows;

	/**
	 * Attachment IDs.
	 *
	 * @var array $attachments
	 */
	public $attachments = array();

	/**
	 * Image ids that needs to be resmushed.
	 *
	 * @var array $resmush_ids
	 */
	public $resmush_ids = array();

	/**
	 * Percentage of the smushed images.
	 *
	 * @var float
	 */
	public $percent_optimized;

	/**
	 * Percentage metric.
	 *
	 * @var float
	 */
	public $percent_metric;

	/**
	 * Class name of grade type.
	 *
	 * @var string
	 */
	public $percent_grade;

	/**
	 * Protected init class, used in child methods instead of constructor.
	 *
	 * @since 3.4.0
	 */
	protected function init() {}

	public function __call( $method_name, $arguments ) {
		_deprecated_function( esc_html( $method_name ), '4.2.0' );
	}

	/**
	 * Stats constructor.
	 */
	public function __construct() {
		$this->init();

		$this->query_limit = apply_filters( 'wp_smush_query_limit', 3000 );
		$this->max_rows    = apply_filters( 'wp_smush_max_rows', 5000 );

		// Recalculate resize savings.
		add_action(
			'wp_smush_image_resized',
			function() {
				return $this->get_savings( 'resize' );
			}
		);

		// Update Conversion savings.
		add_action(
			'wp_smush_png_jpg_converted',
			function() {
				return $this->get_savings( 'pngjpg' );
			}
		);

		// Update the media_attachments list.
		add_action( 'add_attachment', array( $this, 'add_to_media_attachments_list' ) );
		add_action( 'delete_attachment', array( $this, 'update_lists' ), 12 );
	}

	/**
	 * Get the savings from image resizing or PNG -> JPG conversion savings.
	 *
	 * @param string $type          Savings type. Accepts: resize, pngjpg.
	 * @param bool   $force_update  Force update to re-calculate all stats. Default: false.
	 * @param bool   $format        Format the bytes in readable format. Default: false.
	 * @param bool   $return_count  Return the resized image count. Default: false.
	 *
	 * @return int|array
	 */
	public function get_savings( $type, $force_update = true, $format = false, $return_count = false ) {
		$key       = 'wp-smush-' . $type . '_savings';
		$key_count = 'wp-smush-resize_count';

		if ( ! $force_update ) {
			$savings = wp_cache_get( $key, 'wp-smush' );
			if ( ! $return_count && $savings ) {
				return $savings;
			}

			$count = wp_cache_get( $key_count, 'wp-smush' );
			if ( $return_count && false !== $count ) {
				return $count;
			}
		}

		// If savings or resize image count is not stored in db, recalculate.
		$count      = 0;
		$offset     = 0;
		$query_next = true;

		$savings = array(
			'resize' => array(
				'bytes'       => 0,
				'size_before' => 0,
				'size_after'  => 0,
			),
			'pngjpg' => array(
				'bytes'       => 0,
				'size_before' => 0,
				'size_after'  => 0,
			),
		);

		global $wpdb;

		while ( $query_next ) {
			$query_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s LIMIT %d, %d",
					$key,
					$offset,
					$this->query_limit
				)
			); // Db call ok.

			// No results - break out of loop.
			if ( empty( $query_data ) ) {
				break;
			}

			foreach ( $query_data as $data ) {
				// Skip resmush IDs.
				if ( ! empty( $this->resmush_ids ) && in_array( $data->post_id, $this->resmush_ids, true ) ) {
					continue;
				}

				$count++;

				if ( empty( $data ) ) {
					continue;
				}

				$meta = maybe_unserialize( $data->meta_value );

				// Resize mete already contains all the stats.
				if ( ! empty( $meta ) && ! empty( $meta['bytes'] ) ) {
					$savings['resize']['bytes']       += $meta['bytes'];
					$savings['resize']['size_before'] += $meta['size_before'];
					$savings['resize']['size_after']  += $meta['size_after'];
				}

				// PNG - JPG conversion meta contains stats by attachment size.
				if ( is_array( $meta ) ) {
					foreach ( $meta as $size ) {
						$savings['pngjpg']['bytes']       += isset( $size['bytes'] ) ? $size['bytes'] : 0;
						$savings['pngjpg']['size_before'] += isset( $size['size_before'] ) ? $size['size_before'] : 0;
						$savings['pngjpg']['size_after']  += isset( $size['size_after'] ) ? $size['size_after'] : 0;
					}
				}
			}

			// Update the offset.
			$offset += $this->query_limit;

			// Compare the offset value to total images.
			$query_next = $this->total_count > $offset;
		}

		if ( $format ) {
			$savings[ $type ]['bytes'] = size_format( $savings[ $type ]['bytes'], 1 );
		}

		wp_cache_set( 'wp-smush-resize_savings', $savings['resize'], 'wp-smush' );
		wp_cache_set( 'wp-smush-pngjpg_savings', $savings['pngjpg'], 'wp-smush' );
		wp_cache_set( $key_count, $count, 'wp-smush' );

		return $return_count ? $count : $savings[ $type ];
	}

	/**
	 * Adds the ID of the smushed image to the media_attachments list.
	 *
	 * @since 3.7.1
	 *
	 * @param int $id Attachment's ID.
	 */
	public function add_to_media_attachments_list( $id ) {
		$posts = wp_cache_get( 'media_attachments', 'wp-smush' );

		// Return if there's no list to update.
		if ( ! $posts ) {
			return;
		}

		$mime_type = get_post_mime_type( $id );
		$id_string = (string) $id;

		// Add the ID if the mime type is allowed and the ID isn't in the list already.
		if ( $mime_type && in_array( $mime_type, Core::$mime_types, true ) && ! in_array( $id_string, $posts, true ) ) {
			$posts[] = $id_string;
			wp_cache_set( 'media_attachments', $posts, 'wp-smush' );
		}
	}

	/**
	 * Updates the IDs lists when an attachment is deleted.
	 *
	 * @since 3.7.2
	 *
	 * @param integer $id Deleted attachment ID.
	 */
	public function update_lists( $id ) {
		$this->remove_from_media_attachments_list( $id );
		self::remove_from_smushed_list( $id );
	}

	/**
	 * Removes the ID of the deleted image from the media_attachments list.
	 *
	 * @since 3.7.1
	 *
	 * @param int $id Attachment's ID.
	 */
	private function remove_from_media_attachments_list( $id ) {
		$posts = wp_cache_get( 'media_attachments', 'wp-smush' );

		// Return if there's no list to update.
		if ( ! $posts ) {
			return;
		}

		$index = array_search( (string) $id, $posts, true );
		if ( false !== $index ) {
			unset( $posts[ $index ] );
			wp_cache_set( 'media_attachments', $posts, 'wp-smush' );
		}
	}

	/**
	 * Removes an ID from the smushed IDs list from the object cache.
	 *
	 * @since 3.7.2
	 *
	 * @param integer $attachment_id ID of the smushed attachment.
	 */
	public static function remove_from_smushed_list( $attachment_id ) {
		$smushed_ids = wp_cache_get( 'wp-smush-smushed_ids', 'wp-smush' );

		if ( ! empty( $smushed_ids ) ) {
			$index = array_search( strval( $attachment_id ), $smushed_ids, true );
			if ( false !== $index ) {
				unset( $smushed_ids[ $index ] );
				wp_cache_set( 'wp-smush-smushed_ids', $smushed_ids, 'wp-smush' );
			}
		}
	}

	/**
	 * Temporary remove Smush metadata.
	 *
	 * We use this in order to temporary remove the stats metadata,
	 * e.g While generating thumbnail or wp_generate_ when disabled auto smush.
	 *
	 * Note, if member's site allows compression of the original file,
	 * when we remove stats, we might lose a large amount of storage (stats) that we saved for the member's site.
	 * => TODO: Delete stats or just update new stats with re-smush?
	 *
	 * @since 3.9.6
	 *
	 * @param int $attachment_id    Attachment ID.
	 */
	public function remove_stats( $attachment_id ) {
		// Main stats.
		delete_post_meta( $attachment_id, Modules\Smush::$smushed_meta_key );
		// Lossy flag.
		delete_post_meta( $attachment_id, 'wp-smush-lossy' );
		// Finally, remove the attachment ID from cache.
		self::remove_from_smushed_list( $attachment_id );
	}

	/**
	 * Get unsmushed meta query.
	 *
	 * @return array
	 */
	public static function get_unsmushed_meta_query() {
		$unsmushed_query = array(
			'relation' => 'AND',
			array(
				'key'     => Smush_Optimization::get_smush_meta_key(),
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Media_Item::get_ignored_meta_key(),
				'compare' => 'NOT EXISTS',
			),
		);

		return $unsmushed_query;
	}

	/**
	 * Smush and Resizing Stats Combined together.
	 *
	 * @param array $smush_stats     Smush stats.
	 * @param array $resize_savings  Resize savings.
	 *
	 * @return array Array of all the stats
	 */
	public function combined_stats( $smush_stats, $resize_savings ) {
		if ( empty( $smush_stats ) || empty( $resize_savings ) ) {
			return $smush_stats;
		}

		// Initialize key full if not there already.
		if ( ! isset( $smush_stats['sizes']['full'] ) ) {
			$smush_stats['sizes']['full']              = new stdClass();
			$smush_stats['sizes']['full']->bytes       = 0;
			$smush_stats['sizes']['full']->size_before = 0;
			$smush_stats['sizes']['full']->size_after  = 0;
			$smush_stats['sizes']['full']->percent     = 0;
		}

		// Full Image.
		if ( ! empty( $smush_stats['sizes']['full'] ) ) {
			$smush_stats['sizes']['full']->bytes       = ! empty( $resize_savings['bytes'] ) ? $smush_stats['sizes']['full']->bytes + $resize_savings['bytes'] : $smush_stats['sizes']['full']->bytes;
			$smush_stats['sizes']['full']->size_before = ! empty( $resize_savings['size_before'] ) && ( $resize_savings['size_before'] > $smush_stats['sizes']['full']->size_before ) ? $resize_savings['size_before'] : $smush_stats['sizes']['full']->size_before;
			$smush_stats['sizes']['full']->percent     = ! empty( $smush_stats['sizes']['full']->bytes ) && $smush_stats['sizes']['full']->size_before > 0 ? ( $smush_stats['sizes']['full']->bytes / $smush_stats['sizes']['full']->size_before ) * 100 : $smush_stats['sizes']['full']->percent;

			$smush_stats['sizes']['full']->size_after = $smush_stats['sizes']['full']->size_before - $smush_stats['sizes']['full']->bytes;

			$smush_stats['sizes']['full']->percent = round( $smush_stats['sizes']['full']->percent, 1 );
		}

		return $this->total_compression( $smush_stats );
	}

	/**
	 * Combine Savings from PNG to JPG conversion with smush stats
	 *
	 * @param array $stats               Savings from Smushing the image.
	 * @param array $conversion_savings  Savings from converting the PNG to JPG.
	 *
	 * @return Object|array Total Savings
	 */
	public function combine_conversion_stats( $stats, $conversion_savings ) {
		if ( empty( $stats ) || empty( $conversion_savings ) ) {
			return $stats;
		}

		foreach ( $conversion_savings as $size_k => $savings ) {
			// Initialize Object for size.
			if ( empty( $stats['sizes'][ $size_k ] ) ) {
				$stats['sizes'][ $size_k ]              = new stdClass();
				$stats['sizes'][ $size_k ]->bytes       = 0;
				$stats['sizes'][ $size_k ]->size_before = 0;
				$stats['sizes'][ $size_k ]->size_after  = 0;
				$stats['sizes'][ $size_k ]->percent     = 0;
			}

			if ( ! empty( $stats['sizes'][ $size_k ] ) && ! empty( $savings ) ) {
				$stats['sizes'][ $size_k ]->bytes       = $stats['sizes'][ $size_k ]->bytes + $savings['bytes'];
				$stats['sizes'][ $size_k ]->size_before = $stats['sizes'][ $size_k ]->size_before > $savings['size_before'] ? $stats['sizes'][ $size_k ]->size_before : $savings['size_before'];
				$stats['sizes'][ $size_k ]->percent     = ! empty( $stats['sizes'][ $size_k ]->bytes ) && $stats['sizes'][ $size_k ]->size_before > 0 ? ( $stats['sizes'][ $size_k ]->bytes / $stats['sizes'][ $size_k ]->size_before ) * 100 : $stats['sizes'][ $size_k ]->percent;
				$stats['sizes'][ $size_k ]->percent     = round( $stats['sizes'][ $size_k ]->percent, 1 );
			}
		}

		return $this->total_compression( $stats );
	}

	/**
	 * Iterate over all the size stats and calculate the total stats
	 *
	 * @param array $stats  Stats array.
	 *
	 * @return mixed
	 */
	public function total_compression( $stats ) {
		$stats['stats']['size_before'] = 0;
		$stats['stats']['size_after']  = 0;
		$stats['stats']['time']        = 0;

		foreach ( $stats['sizes'] as $size_stats ) {
			$stats['stats']['size_before'] += ! empty( $size_stats->size_before ) ? $size_stats->size_before : 0;
			$stats['stats']['size_after']  += ! empty( $size_stats->size_after ) ? $size_stats->size_after : 0;
			$stats['stats']['time']        += ! empty( $size_stats->time ) ? $size_stats->time : 0;
		}

		$stats['stats']['bytes'] = ! empty( $stats['stats']['size_before'] ) && $stats['stats']['size_before'] > $stats['stats']['size_after'] ? $stats['stats']['size_before'] - $stats['stats']['size_after'] : 0;

		if ( ! empty( $stats['stats']['bytes'] ) && ! empty( $stats['stats']['size_before'] ) ) {
			$stats['stats']['percent'] = ( $stats['stats']['bytes'] / $stats['stats']['size_before'] ) * 100;
		}

		return $stats;
	}

	/**
	 * Returns an array that can be consumed by the JS
	 *
	 * TODO: When we have rewritten the frontend of the plugin we can directly use {@see Global_Stats::to_array()} instead
	 *
	 * @return array
	 */
	public function get_global_stats() {
		$global_stats = Global_Stats::get();
		$total_stats = $global_stats->get_sum_of_optimization_global_stats();
		/**
		 * @var $smush_stats Smush_Optimization_Global_Stats
		 */
		$smush_stats   = $global_stats->get_persistable_stats_for_optimization( Smush_Optimization::get_key() )
		                              ->get_stats();
		$resize_stats  = $global_stats->get_persistable_stats_for_optimization( Resize_Optimization::get_key() )
		                              ->get_stats();
		$png2jpg_stats = $global_stats->get_persistable_stats_for_optimization( Png2Jpg_Optimization::get_key() )
		                              ->get_stats();

		return array(
			'stats_updated_timestamp'  => $global_stats->get_stats_updated_timestamp(),
			'is_outdated'              => $global_stats->is_outdated(),
			'count_supersmushed'       => $smush_stats->get_lossy_count(),
			'count_smushed'            => $smush_stats->get_count(),
			'count_total'              => $global_stats->get_total_optimizable_items_count(),
			'count_images'             => $global_stats->get_optimized_images_count(),
			'count_resize'             => $resize_stats->get_count(),
			'count_skipped'            => $global_stats->get_skipped_count(),
			'unsmushed'                => $global_stats->get_optimize_list()->get_ids(),
			'count_unsmushed'          => $global_stats->get_optimize_list()->get_count(),
			'resmush'                  => $global_stats->get_redo_ids(),
			'count_resmush'            => $global_stats->get_redo_count(),
			'size_before'              => $total_stats->get_size_before(),
			'size_after'               => $total_stats->get_size_after(),
			'savings_bytes'            => $total_stats->get_bytes(),
			'human_bytes'              => $total_stats->get_human_bytes(),
			'savings_resize'           => $resize_stats->get_bytes(),
			'savings_resize_human'     => $resize_stats->get_human_bytes(),
			'savings_conversion'       => $png2jpg_stats->get_bytes(),
			'savings_conversion_human' => $png2jpg_stats->get_human_bytes(),
			'savings_dir_smush'        => $this->dir_stats,
			'savings_percent'          => $total_stats->get_percent() > 0 ? number_format_i18n( $total_stats->get_percent(), 1 ) : 0,
			'percent_grade'            => $global_stats->get_grade_class(),
			'percent_metric'           => $global_stats->get_percent_metric(),
			'percent_optimized'        => $global_stats->get_percent_optimized(),
			'remaining_count'          => $global_stats->get_remaining_count(),
		);
	}
}