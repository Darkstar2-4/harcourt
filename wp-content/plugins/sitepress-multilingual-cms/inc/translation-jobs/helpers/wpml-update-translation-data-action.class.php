<?php

use WPML\FP\Obj;
use WPML\TM\API\Job\Map;
use WPML\TM\API\Jobs;
use WPML\TM\Jobs\JobLog;

abstract class WPML_TM_Update_Translation_Data_Action extends WPML_Translation_Job_Helper_With_API {

	function get_prev_job_data( $rid ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT job_id, translated
							 FROM {$wpdb->prefix}icl_translate_job
							 WHERE rid=%d
							 	AND revision IS NULL
						     LIMIT 1",
				$rid
			),
			ARRAY_N
		);
	}

	function add_translation_job( $rid, $translator_id, array $translation_package, array $batch_options, $sendFrom = null, $addJobLogs = false ) {
		global $wpdb, $current_user;

		$jobId = $this->maybeRestoreATECancelledJobDueToInsufficientBalance( $rid );
		if ( $jobId ) {
			if ( $addJobLogs ) {
				JobLog::add( 'icl_translate_job record restored from the previous state with jobId `' . $jobId . '` from rid `' . $rid . '`' );
			}
			return $jobId;
		}

		$translation_status = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}icl_translation_status WHERE rid=%d", $rid ) );
		$prev_translation = $this->get_translated_field_values( $rid, $translation_package );
		if ( ! $current_user->ID ) {
			$manager_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT manager_id FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d ORDER BY job_id DESC LIMIT 1",
					$rid
				)
			);
		} else {
			$manager_id = $current_user->ID;
		}

		$translate_job_insert_data = array(
			'rid'           => $rid,
			'translator_id' => $translator_id,
			'translated'    => 0,
			'manager_id'    => (int) $manager_id,
		);

		if ( isset( $batch_options['deadline_date'] ) ) {
			$translate_job_insert_data['deadline_date'] = $batch_options['deadline_date'];
		}

		if ( isset( $translation_package['title'] ) ) {
			$translate_job_insert_data['title'] = WPML\FP\Str::truncate_bytes( $translation_package['title'], 160 );
		}

		$wpdb->insert( $wpdb->prefix . 'icl_translate_job', $translate_job_insert_data );
		$job_id = $wpdb->insert_id;

		if ( $addJobLogs ) {
			JobLog::add(
				'New icl_translate_job record created with jobId `' . $job_id . '` from rid `' . $rid . '`',
				$translate_job_insert_data
			);
		}

		$this->package_helper->save_package_to_job( $translation_package, $job_id, $prev_translation, $addJobLogs );
		$this->maybeRemovedElementsBelongingToOldCompletedJobsToKeepTheTableClean( $rid );
		if ( (int) $translation_status->status !== ICL_TM_DUPLICATE ) {
			$this->fire_notification_actions( $job_id, $translation_status, $translator_id, $sendFrom );
		}

		return $job_id;
	}

	private function maybeRemovedElementsBelongingToOldCompletedJobsToKeepTheTableClean( int $rid ) {
		global $wpdb;

		$sql = "
			DELETE t
			FROM {$wpdb->prefix}icl_translate t
			INNER JOIN (
			    SELECT job_id
			    FROM {$wpdb->prefix}icl_translate_job
			    WHERE rid = %d
			      AND job_id < (
			          SELECT MAX(job_id)
			          FROM {$wpdb->prefix}icl_translate_job
			          WHERE rid = %d AND translated = 1
			      )
			) to_delete ON t.job_id = to_delete.job_id;
		";

		$sql = $wpdb->prepare( $sql, $rid, $rid );
		$wpdb->query( $sql );
	}

	private function maybeRestoreATECancelledJobDueToInsufficientBalance( $rid ) {
		$previousStatus = \WPML\Translation\PreviousStateServiceFactory::create()->getByRid( $rid );
		if ( $previousStatus && (int) $previousStatus['status'] === ICL_TM_ATE_CANCELLED ) {
			return Map::fromRid( $rid );
		}

		return null;
	}

	abstract protected function populate_prev_translation( $prev_id, array $package );

	protected function get_translated_field_values( $rid, array $package ) {
		global $wpdb;

		$prev_translations = $this->populate_prev_translation( $rid, $package );

		if ( ! $prev_translations ) {
			return array();
		}

		list( $prev_job_id, $prev_job_translated ) = $this->get_prev_job_data( $rid );

		if ( ! is_null( $prev_job_id ) ) {
			$last_rev_prepare = $wpdb->prepare(
				"
				SELECT MAX(revision)
				FROM {$wpdb->prefix}icl_translate_job
				WHERE rid=%d
					AND ( revision IS NOT NULL OR translated = 1 )
			",
				$rid
			);
			$last_rev         = $wpdb->get_var( $last_rev_prepare );
			$wpdb->update( $wpdb->prefix . 'icl_translate_job', array( 'revision' => $last_rev + 1 ), array( 'job_id' => $prev_job_id ) );
		}

		return $prev_translations;
	}

	protected function fire_notification_actions( $job_id, $translation_status, $translator_id, $sendFrom = null ) {
		if ( ! $job_id ) {
			return;
		}
		if ( 'local' !== $translation_status->translation_service ) {
			return;
		}

		$job = wpml_tm_load_job_factory()->get_translation_job( $job_id, false, 0, true );
		if ( ! $job ) {
			return;
		}

		if ( ICL_TM_NOTIFICATION_IMMEDIATELY === (int) $this->get_tm_setting( array( 'notification', 'new-job' ) ) ) {
			$shouldDelay = (bool) Jobs::SENT_VIA_DASHBOARD === $sendFrom;
			if ( empty( $translator_id ) ) {
				$actionHandle = $shouldDelay ? 'wpml_tm_new_job_notification_with_delay' : 'wpml_tm_new_job_notification';
				do_action( $actionHandle, $job );
			} else {
				$actionHandle = $shouldDelay ? 'wpml_tm_assign_job_notification_with_delay' : 'wpml_tm_assign_job_notification';
				do_action( $actionHandle, $job, $translator_id );
			}
		}

		do_action( 'wpml_added_local_translation_job', $job_id );
	}
}
