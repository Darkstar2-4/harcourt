<?php

namespace WPML\UserInterface\Web\Core\Component\ATE\Application\Endpoint\GetAccountBalances;

use WPML\Core\Component\ATE\Application\Query\AccountException;
use WPML\Core\Component\ATE\Application\Query\AccountInterface;
use WPML\Core\Port\Endpoint\EndpointInterface;

class GetAccountBalancesController implements EndpointInterface {

  private $ateAccount;


  public function __construct( AccountInterface $ateAccount ) {
    $this->ateAccount = $ateAccount;
  }


  public function handle( $requestData = null ): array {
    try {
      $accountBalances = $this->ateAccount->getAccountBalances();
      return [
        'success' => true,
        'data'    => [
          'account_balance' => $accountBalances->getAccountBalance(),
          'redirect_url'    => $accountBalances->getRedirectUrl()
        ]
      ];
    } catch ( AccountException $e ) {
      return [
        'success' => false,
        'message' => $e->getMessage(),
      ];
    }
  }


}
