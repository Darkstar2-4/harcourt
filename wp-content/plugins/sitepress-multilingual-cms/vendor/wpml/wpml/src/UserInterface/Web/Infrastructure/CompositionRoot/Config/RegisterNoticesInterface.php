<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config;

interface RegisterNoticesInterface {


  public function register( callable $callback, array $args = [] );


}
