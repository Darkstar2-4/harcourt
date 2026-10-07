<?php

namespace WPML\UserInterface\Web\Core\Component\Troubleshooting\Application\Endpoint;

use WPML\Core\Port\Endpoint\EndpointInterface;
use WPML\Core\SharedKernel\Component\Site\Application\Query\SiteUrlQueryInterface;
use WPML\TM\ATE\ClonedSites\AliasDomainProber;
use WPML\TM\ATE\ClonedSites\SecondaryDomains;

class RegisterAliasDomainController implements EndpointInterface {

  private $secondaryDomains;

  private $prober;

  private $siteUrlQuery;


  public function __construct(
    SecondaryDomains $secondaryDomains,
    AliasDomainProber $prober,
    SiteUrlQueryInterface $siteUrlQuery
  ) {
    $this->secondaryDomains = $secondaryDomains;
    $this->prober           = $prober;
    $this->siteUrlQuery     = $siteUrlQuery;
  }


  public function handle( $requestData = null ): array {
    $rawDomain = $requestData['domain'] ?? '';
    $alias     = is_string( $rawDomain ) ? trim( $rawDomain ) : '';

    if ( $alias === '' || filter_var( $alias, FILTER_VALIDATE_URL ) === false ) {
      return [
        'success' => false,
        'message' => __( 'Please enter a valid URL.', 'wpml' ),
      ];
    }

    $alias          = rtrim( $alias, '/' );
    $currentSiteUrl = rtrim( $this->siteUrlQuery->get(), '/' );

    if ( $alias === $currentSiteUrl ) {
      return [
        'success' => false,
        'message' => __( 'This is already the current site URL.', 'wpml' ),
      ];
    }

    if ( ! $this->prober->verify( $alias ) ) {
      return [
        'success' => false,
        'message' => __( 'We could not verify that the URL points to the same WordPress installation.', 'wpml' ),
      ];
    }

    $aliasDomains = $this->secondaryDomains->add( $alias, $currentSiteUrl );

    return [
      'success'     => true,
      'aliasDomain' => [
        'originalSiteUrl' => $currentSiteUrl,
        'aliasDomains'    => $aliasDomains,
      ],
    ];
  }


}
