<?php
/**
 * Global Stats DTO
 *
 * Handles conversion between PHP snake_case and React camelCase for global stats data.
 *
 * @package Smush\Core\Stats
 * @since 3.25.0
 */

namespace Smush\Core\Stats;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Global_Stats_DTO
 *
 * Converts global statistics data from snake_case to camelCase.
 *
 * @since 3.25.0
 */
class Global_Stats_DTO {

	/**
	 * Convert global stats data to React props.
	 *
	 * @param Global_Stats $global_stats Global stats instance.
	 *
	 * @return array Transformed data for React.
	 * @since 3.25.0
	 *
	 */
	public static function to_react_props( $global_stats ) {
		if ( ! $global_stats instanceof Global_Stats ) {
			return array();
		}

		$total_optimization_stats = $global_stats->get_sum_of_optimization_global_stats();

		$react_props = array(
			'isOutdated'     => $global_stats->is_outdated(),
			'remainingCount' => $global_stats->get_remaining_count(),
			'countTotal'     => $global_stats->get_total_optimizable_items_count(),
			'countImages'    => $global_stats->get_optimized_images_count(),
			'savingsBytes'   => $total_optimization_stats->get_bytes(),
			'savingsPercent' => $total_optimization_stats->get_percent(),
		);

		return $react_props;
	}
}
