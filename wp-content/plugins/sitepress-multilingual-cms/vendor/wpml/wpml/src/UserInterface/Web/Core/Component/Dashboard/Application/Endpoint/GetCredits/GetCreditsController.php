<?php

namespace WPML\UserInterface\Web\Core\Component\Dashboard\Application\Endpoint\GetCredits;

use WPML\Core\Component\ATE\Application\Query\AccountException;
use WPML\Core\Component\ATE\Application\Query\AccountInterface;
use WPML\Core\Component\ATE\Application\Service\CreditsService;
use WPML\Core\Port\Endpoint\EndpointInterface;

class GetCreditsController implements EndpointInterface {

  private $ateAccount;

  private $creditsService;


  public function __construct( AccountInterface $ateAccount, CreditsService $creditsService ) {
    $this->ateAccount = $ateAccount;
    $this->creditsService = $creditsService;
  }


  public function handle( $requestData = null ): array {
    $credits = $this->ateAccount->getCredits();

    return [
      'success' => true,
      'data'    => [
        'available_balance'      => $credits->getAvailableBalance(),
        'payAsYouGoSubscription' => $credits->getPayAsYouGo() || $credits->getActiveSubscription(),
        'totalCreditsDeposited'  => $credits->getTotalCreditsDeposited(),
        'totalCreditsSpent'      => $credits->getTotalCreditsSpent(),
        'subscriptionUsage'      => $credits->getSubscriptionUsage(),
        'subscriptionDebt'       => $credits->getSubscriptionDebt(),
        'subscriptionMaxLimit'   => $credits->getSubscriptionMaxLimit(),
        'creditsInProgress'      => $this->creditsService->getCreditsInProgress()->getCount(),
      ]
    ];
  }


}
