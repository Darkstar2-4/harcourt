<?php

namespace WPML\UserInterface\Web\Core\SharedKernel\Config;

interface ExistingPageInterface {


  public function isActive();


  public function renderNotice( Notice $notice );


}
