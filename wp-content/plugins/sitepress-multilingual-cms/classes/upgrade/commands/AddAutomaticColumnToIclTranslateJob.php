<?php


namespace WPML\Upgrade\Commands;


class AddAutomaticColumnToIclTranslateJob extends \WPML_Upgrade_Add_Column_To_Table {
	protected function get_table() {
		return 'icl_translate_job';
	}

	protected function get_column() {
		return 'automatic';
	}

	protected function get_column_definition() {
		return 'TINYINT UNSIGNED NOT NULL DEFAULT 0';
	}
}