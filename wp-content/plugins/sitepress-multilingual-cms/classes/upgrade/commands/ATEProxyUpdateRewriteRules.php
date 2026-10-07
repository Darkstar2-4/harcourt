<?php

namespace WPML\TM\Upgrade\Commands;

class ATEProxyUpdateRewriteRules implements \IWPML_Upgrade_Command {

	private $result = false;

	public function run_admin() {
		update_option( 'plugin_permalinks_flushed', 0 );
		$this->result = true;

		return $this->result;
	}

	public function run_ajax() {
		return null;
	}

	public function run_frontend() {
		return null;
	}

	public function get_results() {
		return $this->result;
	}
}
