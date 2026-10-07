<?php

namespace WPML\Legacy\Component\ATE\Application\Query;

use WPML\Core\Component\ATE\Application\Query\AccountException;
use WPML\Core\Component\ATE\Application\Query\AccountInterface;
use WPML\Core\Component\ATE\Application\Query\Dto\AccountBalanceDto;
use WPML\Core\Component\ATE\Application\Query\Dto\CreditInfoDto;

class Account implements AccountInterface {


  public function getCredits(): CreditInfoDto {
    $apiResult = \WPML\TM\API\ATE\Account::getCredits();

    $apiResult = $this->handleLegacyResult( $apiResult, __( 'Error getting credits', 'wpml' ) );
    $apiResult = is_array( $apiResult ) ? $apiResult : [];
    $subscriptionDebt = isset( $apiResult['subscription_debt'] )
      ? (int) $apiResult['subscription_debt']
      : 0;

    return new CreditInfoDto(
      $apiResult['free_credits_amount'] ?? 0,
      $apiResult['active_subscription'] ?? false,
      $apiResult['subscription_usage'] ?? 0,
      $apiResult['available_balance'] ?? 0,
      $apiResult['total_credits_deposited'] ?? 0,
      $apiResult['total_credits_spent'] ?? 0,
      $apiResult['pay_as_you_go'] ?? false,
      $apiResult['subscription_max_limit'] ?? null,
      $subscriptionDebt
    );
  }


  public function getAccountBalances(): AccountBalanceDto {
    $apiResult = \WPML\TM\API\ATE\Account::getAccountBalances();

    $apiResult = $this->handleLegacyResult( $apiResult, __( 'Error getting account balances', 'wpml' ) );
    $apiResult = is_array( $apiResult ) ? $apiResult : [];

    return new AccountBalanceDto(
      $apiResult['account_balance'] ?? 0,
      $apiResult['redirect_url'] ?? ''
    );
  }


  private function handleLegacyResult( $apiResult, string $errorMsg ) {
    $errorHandler = function ( $error ) use ( $errorMsg ) {
      throw new AccountException(
        $error['error'] ?? $errorMsg
      );
    };

    $identity = function ( array $result ) {
      return $result;
    };

    if ( is_object( $apiResult ) && method_exists( $apiResult, 'bimap' ) ) {
        $apiResult = $apiResult->bimap( $errorHandler, $identity );
    }

    if ( is_object( $apiResult ) && method_exists( $apiResult, 'getOrElse' ) ) {
        $apiResult = $apiResult->getOrElse( [] );
    }

    return $apiResult;
  }


}
