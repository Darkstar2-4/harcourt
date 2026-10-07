<?php

namespace WPML\UserInterface\Web\Infrastructure\CompositionRoot\Config;

use WPML\UserInterface\Web\Core\SharedKernel\Config\Page;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Script;
use WPML\UserInterface\Web\Core\SharedKernel\Config\Style;

interface PageInterface {


  public function register( Page $page, $onLoadPageHandle );


  public function loadStyle( Style $style );


  public function registerScript( Script $script );


  public function loadScript( Script $script );


  public function provideDataForScript(
    Script $script,
    string $jsWindowKey,
    $data
  );


}
