<?php

class WPML_Add_UUID_Column_To_Translation_Status extends WPML_Upgrade_Add_Column_To_Table {

	protected function get_table() {
		return 'icl_translation_status';
	}

	protected function get_column() {
		return 'uuid';
	}

	protected function get_column_definition() {
		return 'VARCHAR(36) NULL';
	}
}
