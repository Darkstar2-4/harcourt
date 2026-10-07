<?php

namespace WPML\TM\ATE;

use WPML\FP\Obj;
use WPML\TM\Jobs\JobLog;
use function WPML\Container\make;

class ReturnedJobs {

	private $ateIdToWpmlId;

	public static function removeJobTranslationDuplicateStatus( $ateJobId, callable $ateIdToWpmlId ) {
		$wpmlJobId = $ateIdToWpmlId( $ateJobId );

		if ( $wpmlJobId ) {
			$tm_records        = make( \WPML_TM_Records::class );
			$jobTranslation    = $tm_records->icl_translate_job_by_job_id( $wpmlJobId );
			$translationStatus = $tm_records->icl_translation_status_by_rid( $jobTranslation->rid() );
			if ( ICL_TM_DUPLICATE === $translationStatus->status() ) {
				$translationStatus->update( [ 'status' => ICL_TM_IN_PROGRESS ] );
				if ( class_exists( JobLog::class ) ) {
					JobLog::add( 'returned_job_duplicate_cleared', [
						'job_id'     => (int) $wpmlJobId,
						'ate_job_id' => (int) $ateJobId,
						'old_status' => (int) ICL_TM_DUPLICATE,
						'new_status' => (int) ICL_TM_IN_PROGRESS,
					] );
				}
			}
		}
	}
}
