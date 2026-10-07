<?php

class WPML_Upgrade_Add_Wrap_Column_To_Translate extends WPML_Upgrade_Add_Column_To_Table {

	protected function get_table() {
		return 'icl_translate';
	}

	protected function get_column() {
		return 'field_wrap_tag';
	}

	protected function get_column_definition() {
		return 'VARCHAR( 16 ) NOT NULL AFTER `field_type`';
	}
}
