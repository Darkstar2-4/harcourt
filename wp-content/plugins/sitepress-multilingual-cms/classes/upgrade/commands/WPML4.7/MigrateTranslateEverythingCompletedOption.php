<?php

namespace WPML\TM\Upgrade\Commands;

use WPML\WP\OptionManager;
use WPML\Setup\Option;

class MigrateTranslateEverythingCompletedOption implements \IWPML_Upgrade_Command {

	public function run() {
		if ( ! Option::shouldTranslateEverything() ) {
			return true;
		}

		$optionManager = new OptionManager();

		$oldOption = $optionManager->get( Option::OPTION_GROUP, 'translate-everything-completed', [] );
		$optionManager->set( Option::OPTION_GROUP, Option::TRANSLATE_EVERYTHING_POSTS, $oldOption );

		return true;
	}

	public function run_admin() {
		return $this->run();
	}

	public function run_ajax() {
		return null;
	}

	public function run_frontend() {
		return null;
	}

	public function get_results() {
		return true;
	}
}
