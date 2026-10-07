<?php
namespace WPML\Infrastructure\WordPress\Component\ATE\Domain\Credits\Repository;

use WPML\Core\Component\ATE\Domain\Credits\Repository\CreditsInProgressRepositoryInterface;

class CreditsInProgressRepository implements CreditsInProgressRepositoryInterface {
  const OPTION_LOWEST_RELEVANT_JOB_ID = '_wpml_credits_in_progress_lowest_job_id';


  public function getCreditsInProgressCount( $statusesInProgress ) {
    $wpdb = $GLOBALS['wpdb'];

    $lowestRelevantId = get_option( self::OPTION_LOWEST_RELEVANT_JOB_ID, 0 );
    $maxJobId = $wpdb->get_var( "SELECT MAX(job_id) FROM {$wpdb->prefix}icl_translate_job;" );

    if ( ! is_numeric( $maxJobId ) || $lowestRelevantId > (int) $maxJobId ) {
      return 0;
    }

    $statusesPlaceholders = implode(
      ', ',
      array_fill( 0, count( $statusesInProgress ), '%d' )
    );


    $q = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT
          SUM(j.wpml_automatic_translation_costs) as creditsInProgress,
          MIN(j.job_id) as newLowestRelevantId
        FROM {$wpdb->prefix}icl_translate_job j
        INNER JOIN {$wpdb->prefix}icl_translation_status ts
               ON ts.rid = j.rid
        INNER JOIN (
            SELECT rid, MAX(job_id) AS max_job_id
            FROM {$wpdb->prefix}icl_translate_job
            WHERE job_id >= %d
            GROUP BY rid
        ) latest ON j.rid = latest.rid
                AND j.job_id = latest.max_job_id
        WHERE j.job_id >= %d
          AND j.automatic = 1
          AND j.editor = 'ate'
          AND ts.status IN ($statusesPlaceholders)",
        array_merge(
          [
            $lowestRelevantId,
            $lowestRelevantId
          ],
          $statusesInProgress
        )
      )
    );

    if ( $wpdb->last_error ) {
      return 0;
    }

    if ( isset( $q->newLowestRelevantId ) && is_numeric( $q->newLowestRelevantId ) ) {
      $newLowestJobId = (int) $q->newLowestRelevantId;
      if ( $newLowestJobId > $lowestRelevantId ) {
        update_option( self::OPTION_LOWEST_RELEVANT_JOB_ID, $newLowestJobId, false );
      }

    } else if ( (int) $maxJobId > $lowestRelevantId ) {
      update_option( self::OPTION_LOWEST_RELEVANT_JOB_ID, (int) $maxJobId + 1, false );
    }

    return isset( $q->creditsInProgress )
      ? (int) $q->creditsInProgress
      : 0;
  }


}
