<?php

namespace WPML\TM\Upgrade\Commands;

class AddAteSyncCountToTranslationJob extends \WPML_Upgrade_Add_Column_To_Table {
	protected function get_table() {
		return 'icl_translate_job';
	}

	protected function get_column() {
		return 'ate_sync_count';
	}

	protected function get_column_definition() {
		return "INT(6) UNSIGNED DEFAULT 0";
	}
}
