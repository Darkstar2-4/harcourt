<?php

class WPML_TM_Update_Translation_Status {

	public static function by_job_id( $job_id, $new_status ) {
		$job = wpml_tm_load_job_factory()->get_translation_job( $job_id );

		if ( $job ) {
			$new_status = (int) $new_status;
			$old_status = (int) $job->status;
			if ( $new_status !== $old_status ) {
				wpml_tm_get_records()
					->icl_translation_status_by_translation_id( $job->translation_id )
					->update( array( 'status' => $new_status ) );
			}
		}
	}
}
