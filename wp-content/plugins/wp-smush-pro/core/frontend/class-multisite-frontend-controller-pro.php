<?php

namespace Smush\Core\Frontend;

use Smush\Core\Membership\Membership;

class Multisite_Frontend_Controller_Pro extends Multisite_Frontend_Controller {

	public function add_upgrade_submenu_page() {
		if ( ! Membership::get_instance()->is_pro() ) {
			parent::add_upgrade_submenu_page();
		}
	}

	public function print_upgrade_submenu_script() {
		if ( ! Membership::get_instance()->is_pro() ) {
			parent::print_upgrade_submenu_script();
		}
	}
}