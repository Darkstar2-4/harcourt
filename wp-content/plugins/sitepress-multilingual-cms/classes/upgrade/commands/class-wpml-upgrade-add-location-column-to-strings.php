<?php

class WPML_Upgrade_Add_Location_Column_To_Strings extends WPML_Upgrade_Add_Column_To_Table {

	protected function get_table() {
		return 'icl_strings';
	}

	protected function get_column() {
		return 'location';
	}

	protected function get_column_definition() {
		return 'BIGINT unsigned NULL AFTER `string_package_id`';
	}
}
