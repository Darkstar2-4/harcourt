<?php

class WPML_Upgrade_Add_Editor_Column_To_Icl_Translate_Job extends WPML_Upgrade_Add_Column_To_Table {

	protected function get_table() {
		return 'icl_translate_job';
	}

	protected function get_column() {
		return 'editor';
	}

	protected function get_column_definition() {
		return 'VARCHAR(16) NULL';
	}
}
