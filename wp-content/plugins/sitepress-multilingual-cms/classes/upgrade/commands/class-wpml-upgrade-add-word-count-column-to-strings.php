<?php

class WPML_Upgrade_Add_Word_Count_Column_To_Strings extends WPML_Upgrade_Add_Column_To_Table {

	protected function get_table() {
		return 'icl_strings';
	}

	protected function get_column() {
		return 'word_count';
	}

	protected function get_column_definition() {
		return 'int unsigned NULL';
	}
}
