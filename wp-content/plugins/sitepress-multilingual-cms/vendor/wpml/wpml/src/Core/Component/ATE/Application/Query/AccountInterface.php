<?php

namespace WPML\Core\Component\ATE\Application\Query;

use WPML\Core\Component\ATE\Application\Query\Dto\AccountBalanceDto;
use WPML\Core\Component\ATE\Application\Query\Dto\CreditInfoDto;

interface AccountInterface {


  public function getCredits(): CreditInfoDto;


  public function getAccountBalances(): AccountBalanceDto;


}
