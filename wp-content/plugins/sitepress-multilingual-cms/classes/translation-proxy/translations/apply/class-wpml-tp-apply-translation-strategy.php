<?php

interface WPML_TP_Apply_Translation_Strategy {
	public function apply( WPML_TM_Job_Entity $job, WPML_TP_Translation_Collection $translations );
}
