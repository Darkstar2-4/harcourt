<?php

namespace WPML\UserInterface\Web\Infrastructure\WordPress\CompositionRoot\Config;

use WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config\RegisterNoticesInterface;

class RegisterNotices implements RegisterNoticesInterface {


  public function register( callable $callback, array $args = [] ) {
    add_action(
      'all_admin_notices',
      function () use ( $callback, $args ) {
        call_user_func_array( $callback, $args );
      }
    );
  }


}
