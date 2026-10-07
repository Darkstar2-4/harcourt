<?php

namespace WPML\TM\Upgrade\Commands;

class AddAteCommunicationRetryColumnToTranslationStatus extends \WPML_Upgrade_Add_Column_To_Table {
	protected function get_table() {
		return 'icl_translation_status';
	}

	protected function get_column() {
		return 'ate_comm_retry_count';
	}

	protected function get_column_definition() {
		return "INT(11) UNSIGNED DEFAULT 0";
	}
}
