<?php

namespace WPML\TM\Menu\Dashboard;

use WPML\FP\Lst;
use WPML\TM\ATE\Review\ReviewStatus;

class PostJobsRepository {

	public function getJobsGroupedByLang( $original_element_id, $element_type ) {
		return $this->getJobsFor( $original_element_id, $element_type )
		            ->map( [ $this, 'mapJob' ] )
		            ->keyBy( 'targetLanguage' )
		            ->toArray();
	}

	private function getJobsFor( $original_element_id, $element_type ) {
		return \wpml_collect(
			wpml_tm_get_jobs_repository()->get( $this->buildSearchParams( $original_element_id, $element_type ) )
		);
	}

	private function buildSearchParams( $original_element_id, $element_type ) {
		$params = new \WPML_TM_Jobs_Search_Params();
		$params->set_original_element_id( $original_element_id );
		$params->set_job_types( $element_type );

		return $params;
	}

	public function mapJob( \WPML_TM_Post_Job_Entity $job ) {
		return [
			'entity_id'      => $job->get_id(),
			'job_id'         => $job->get_translate_job_id(),
			'type'           => $job->get_type(),
			'status'         => $this->getJobStatus( $job ),
			'targetLanguage' => $job->get_target_language(),
			'isLocal'        => 'local' === $job->get_translation_service(),
			'needsReview'    => Lst::includes( $job->get_review_status(), [ ReviewStatus::NEEDS_REVIEW, ReviewStatus::EDITING ] ),
			'automatic'      => $job->is_automatic(),
			'editor'         => $job->get_editor(),
		];
	}

	private function getJobStatus( \WPML_TM_Job_Entity $job ) {
		if ( $job->does_need_update() ) {
			return ICL_TM_NEEDS_UPDATE;
		}

		if ( $this->postHasTranslationButLatestJobCancelled( $job ) ) {
			return ICL_TM_COMPLETE;
		}

		return $job->get_status();
	}

	private function postHasTranslationButLatestJobCancelled( \WPML_TM_Job_Entity $job ) {
		return $job->get_status() === ICL_TM_NOT_TRANSLATED && $job->has_completed_translation();
	}

}
