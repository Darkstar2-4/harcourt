<?php

namespace WPML\UserInterface\Web\Core\Component\Notices\PromoteUsingDashboard\Application\Repository;

interface ManualTranslationsCountRepositoryInterface {


  public function count( int $translatorId ): int;


  public function increment( int $translatorId );


}
