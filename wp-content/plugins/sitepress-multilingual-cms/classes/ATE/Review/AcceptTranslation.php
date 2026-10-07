<?php

namespace WPML\TM\ATE\Review;

use WPML\Ajax\IHandler;
use WPML\Collect\Support\Collection;
use WPML\FP\Either;
use WPML\FP\Obj;
use WPML\LIB\WP\Post;
use WPML\LIB\WP\User;
use WPML\TM\API\Jobs;

class AcceptTranslation implements IHandler {

	public function run( Collection $data ) {
		$jobId                     = (int) $data->get( 'jobId' );
		$suppliedPostId            = $data->get( 'postId' );
		$suppliedElementTypePrefix = $data->get( 'element_type_prefix' );
		$job                       = Jobs::get( $jobId );

		if (
			! $job
			|| ICL_TM_COMPLETE !== (int) Obj::prop( 'status', $job )
			|| ReviewStatus::NEEDS_REVIEW !== Obj::prop( 'review_status', $job )
		) {
			return Either::left( $jobId );
		}

		$elementTypePrefix = (string) Obj::prop( 'element_type_prefix', $job );
		if (
			null !== $suppliedElementTypePrefix
			&& (string) $suppliedElementTypePrefix !== $elementTypePrefix
		) {
			return Either::left( $jobId );
		}

		if ( ! $this->isAuthorizedForJob( $job ) ) {
			return Either::left( $jobId );
		}

		if ( PackageJob::ELEMENT_TYPE_PREFIX === $elementTypePrefix ) {
			if ( null !== $suppliedPostId || ! $this->canTranslatePackage() ) {
				return Either::left( $jobId );
			}
		} elseif ( 'post' === $elementTypePrefix ) {
			$postId = (int) Obj::prop( 'element_id', $job );
			if (
				! $postId
				|| null === $suppliedPostId
				|| (int) $suppliedPostId !== $postId
				|| ! $this->canEditPost( $postId )
			) {
				return Either::left( $jobId );
			}

			Post::setStatusWithoutFilters( $postId, 'publish' );
		} else {
			return Either::left( $jobId );
		}

		Jobs::setStatus( $jobId, ICL_TM_COMPLETE );
		Jobs::setReviewStatus( $jobId, ReviewStatus::ACCEPTED );

		return Either::of( $jobId );
	}

	private function isAuthorizedForJob( $job ) {
		if ( User::canManageTranslations() ) {
			return true;
		}

		$translatorId = (int) Obj::prop( 'translator_id', $job );
		return ! $translatorId || get_current_user_id() === $translatorId;
	}

	private function canTranslatePackage() {
		return User::canManageTranslations() || current_user_can( User::CAP_TRANSLATE ) || current_user_can( User::CAP_MANAGE_OPTIONS );
	}

	private function canEditPost( $postId ) {
		return User::canManageTranslations() || current_user_can( 'edit_post', $postId );
	}
}
