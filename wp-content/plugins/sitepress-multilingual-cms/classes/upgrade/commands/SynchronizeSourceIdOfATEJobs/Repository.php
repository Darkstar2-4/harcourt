<?php

namespace WPML\TM\Upgrade\Commands\SynchronizeSourceIdOfATEJobs;


class Repository {
	private $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}


	public function getPairs() {
		$sql = "
			SELECT MAX(editor_job_id) as editor_job_id, rid
			FROM {$this->wpdb->prefix}icl_translate_job
			WHERE editor = 'ate' AND editor_job_id IS NOT NULL
			GROUP BY rid
		";

		$rowset = $this->wpdb->get_results( $sql, ARRAY_A );
		$rowset = \wpml_collect( is_array( $rowset ) ? $rowset : [] );

		return $rowset->pluck( 'rid', 'editor_job_id' );
	}
}