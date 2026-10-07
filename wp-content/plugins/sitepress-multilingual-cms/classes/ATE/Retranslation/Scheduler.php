<?php

namespace WPML\TM\ATE\Retranslation;

class Scheduler {

	const LAST_CALL_OPTION = 'wpml_ate_retranslation_last_call';

	const INTERVAL = 60 * 2;

	public function shouldRun(): bool {
		$lastCall = get_option( self::LAST_CALL_OPTION );

		return $lastCall ? ( time() - $lastCall ) > self::INTERVAL : $lastCall;
	}

	public function scheduleNextRun() {
		update_option( self::LAST_CALL_OPTION, time(), false );
	}

	public function disable() {
		delete_option( self::LAST_CALL_OPTION );
	}
}
