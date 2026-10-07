<?php

namespace WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint;

use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\TM\ATE\ClonedSites\AutoMigration\Handler;
use WPML\TM\ATE\ClonedSites\AutoMigration\Notice;
use WPML\TM\ATE\ClonedSites\Lock;
use WPML\TM\ATE\ClonedSites\SecondaryDomains;

class EnableAliasDomainController implements EndpointInterface {

  private $lock;

  private $secondaryDomains;


  public function __construct( Lock $lock, SecondaryDomains $secondaryDomains ) {
    $this->lock             = $lock;
    $this->secondaryDomains = $secondaryDomains;
  }


  public function handle( $requestData = null ): array {
    $lockData        = $this->lock->getLockData();
    $aliasUrl        = $lockData['urlUsedToMakeRequest'];
    $originalSiteUrl = $lockData['urlCurrentlyRegisteredInAMS'];

    $aliasDomains = $this->secondaryDomains->add( $aliasUrl, $originalSiteUrl );
    $this->lock->unlock();

    Handler::clearMigrationData();
    delete_option( Notice::NOTICE_URL_KEY );

    return [
      'success'     => true,
      'aliasDomain' => [
        'originalSiteUrl' => $originalSiteUrl,
        'aliasDomains'    => $aliasDomains,
      ],
    ];
  }


}
