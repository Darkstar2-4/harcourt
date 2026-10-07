<?php


namespace WPML\Upgrade\Commands;


class AddCountryColumnToLanguages extends \WPML_Upgrade_Add_Column_To_Table {

	protected function get_table() {
		return 'icl_languages';
	}

	protected function get_column() {
		return 'country';
	}

	protected function get_column_definition() {
		return 'VARCHAR(10) NULL DEFAULT NULL';
	}

}