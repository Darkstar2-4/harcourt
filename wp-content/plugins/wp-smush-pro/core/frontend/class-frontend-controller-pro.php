<?php

namespace Smush\Core\Frontend;

use Smush\Core\Membership\Membership;

class Frontend_Controller_Pro extends Frontend_Controller {

    /**
     * Frontend_Controller constructor.
     */
    public function __construct() {
        parent::__construct();
    }

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