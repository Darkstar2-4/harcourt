<?php

namespace WPML\UserInterface\Web\Core\Component\Notices\PromoteUsingDashboard\Application\Repository;

interface DashboardTranslationsRepositoryInterface {


  public function recordTranslator( int $translatorId );


  public function doesTranslatorHaveAny( int $translatorId ): bool;


}
