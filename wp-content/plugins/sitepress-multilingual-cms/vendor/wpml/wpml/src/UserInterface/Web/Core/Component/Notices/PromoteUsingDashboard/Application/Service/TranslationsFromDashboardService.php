<?php

namespace WPML\UserInterface\Web\Core\Component\Notices\PromoteUsingDashboard\Application\Service;

use WPML\Core\SharedKernel\Component\User\Application\Query\UserQueryInterface;
use WPML\UserInterface\Web\Core\Component\Notices\PromoteUsingDashboard\Application\Repository\DashboardTranslationsRepositoryInterface;

final class TranslationsFromDashboardService {

  private $userQuery;

  private $dashboardTranslationsRepository;


  public function __construct(
    UserQueryInterface $userQuery,
    DashboardTranslationsRepositoryInterface $dashboardTranslationsRepository
  ) {
    $this->userQuery                      = $userQuery;
    $this->dashboardTranslationsRepository = $dashboardTranslationsRepository;
  }


  public function recordTranslator() {
    $translator = $this->userQuery->getCurrent();
    if ( ! $translator ) {
      return;
    }

    $this->dashboardTranslationsRepository->recordTranslator( $translator->getId() );
  }


  public function hasAny(): bool {
    $translator = $this->userQuery->getCurrent();
    if ( ! $translator ) {
      return false;
    }

    return $this->dashboardTranslationsRepository->doesTranslatorHaveAny( $translator->getId() );
  }


}
