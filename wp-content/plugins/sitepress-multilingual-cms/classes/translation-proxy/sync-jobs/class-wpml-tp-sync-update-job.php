<?php

use WPML\FP\Obj;
use \WPML\LIB\WP\Post;

class WPML_TP_Sync_Update_Job {

	private $strategies = array(
		WPML_TM_Job_Entity::POST_TYPE    => 'update_post_job',
		WPML_TM_Job_Entity::PACKAGE_TYPE => 'update_post_job',
		WPML_TM_Job_Entity::STRING_BATCH => 'update_post_job',
	);

	private $wpdb;

	private $sitepress;

	public function __construct( wpdb $wpdb, SitePress $sitepress ) {
		$this->wpdb      = $wpdb;
		$this->sitepress = $sitepress;
	}

	public function update_state( WPML_TM_Job_Entity $job ) {
		if ( ! array_key_exists( $job->get_type(), $this->strategies ) ) {
			return $job;
		}

		$method = $this->strategies[ $job->get_type() ];

		return call_user_func( array( $this, $method ), $job );
	}


	private function update_post_job( WPML_TM_Job_Entity $job ) {
		$rid = $job->get_id();

		if ( $job->get_status() === ICL_TM_NOT_TRANSLATED ) {
			$prev_status = \WPML\Translation\PreviousStateServiceFactory::create()->getByRid( $rid );
      if ( $prev_status && Obj::prop( 'needs_update', $prev_status ) ) {
				$prev_status['_prevstate'] = null;
				$this->wpdb->update( $this->wpdb->prefix . 'icl_translation_status', $prev_status, [ 'rid' => $rid ] );
				$job->set_needs_update( true );
				return $job;
			}
		}

		$this->wpdb->update(
			$this->wpdb->prefix . 'icl_translation_status',
			array(
				'status'      => $job->get_status(),
				'tp_revision' => $job->get_revision(),
				'ts_status'   => $this->get_ts_status_in_ts_format( $job ),
			),
			array( 'rid' => $rid )
		);

		if (
			ICL_TM_NOT_TRANSLATED === $job->get_status()
			&& ( $post_type = Post::getType( $job->get_original_element_id() ) )
		) {
			$this->sitepress->delete_orphan_element(
				$job->get_original_element_id(),
				'post_' . $post_type,
				$job->get_target_language()
			);
		}

		return $job;
	}


	private function get_ts_status_in_ts_format( WPML_TM_Job_Entity $job ) {
		$ts_status = $job->get_ts_status();

		return $ts_status ? wp_json_encode( array( 'ts_status' => json_decode( (string) $ts_status ) ) ) : null;
	}
}
