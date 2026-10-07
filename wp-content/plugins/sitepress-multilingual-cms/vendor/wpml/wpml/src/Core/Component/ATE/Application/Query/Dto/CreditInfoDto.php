<?php

namespace WPML\Core\Component\ATE\Application\Query\Dto;

class CreditInfoDto {

  private $freeCreditsAmount;

  private $activeSubscription;

  private $subscriptionMaxLimit;

  private $subscriptionUsage;

  private $availableBalance;

  private $totalCreditsDeposited;

  private $totalCreditsSpent;

  private $payAsYouGo;

  private $subscriptionDebt;


  public function __construct(
    int $freeCreditsAmount,
    bool $activeSubscription,
    int $subscriptionUsage,
    int $availableBalance,
    int $totalCreditsDeposited,
    int $totalCreditsSpent,
    bool $payAsYouGo,
    ?int $subscriptionMaxLimit = null,
    int $subscriptionDebt = 0
  ) {
    $this->freeCreditsAmount     = $freeCreditsAmount;
    $this->activeSubscription    = $activeSubscription;
    $this->subscriptionMaxLimit  = $subscriptionMaxLimit;
    $this->subscriptionUsage     = $subscriptionUsage;
    $this->availableBalance      = $availableBalance;
    $this->totalCreditsDeposited = $totalCreditsDeposited;
    $this->totalCreditsSpent     = $totalCreditsSpent;
    $this->payAsYouGo            = $payAsYouGo;
    $this->subscriptionDebt      = $subscriptionDebt;
  }


  public function getFreeCreditsAmount(): int {
    return $this->freeCreditsAmount;
  }


  public function getActiveSubscription(): bool {
    return $this->activeSubscription;
  }


  public function getSubscriptionMaxLimit() {
    return $this->subscriptionMaxLimit;
  }


  public function getSubscriptionUsage(): int {
    return $this->subscriptionUsage;
  }


  public function getAvailableBalance(): int {
    return $this->availableBalance;
  }


  public function getTotalCreditsDeposited(): int {
    return $this->totalCreditsDeposited;
  }


  public function getTotalCreditsSpent(): int {
    return $this->totalCreditsSpent;
  }


  public function getPayAsYouGo(): bool {
    return $this->payAsYouGo;
  }


  public function getSubscriptionDebt(): int {
    return $this->subscriptionDebt;
  }


}
