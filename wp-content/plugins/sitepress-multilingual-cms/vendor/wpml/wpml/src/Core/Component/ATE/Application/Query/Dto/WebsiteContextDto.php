<?php

namespace WPML\Core\Component\ATE\Application\Query\Dto;

use WPML\PHP\DateTime;


class WebsiteContextDto
{

  private $contextPresent;

  private $lastSync;

  private $context;

  private $languageIso;

  private $siteTopic;

  private $sitePurpose;

  private $siteAudience;

  private $status;

  private $translateNames;


  public function __construct(
      bool $contextPresent,
      ?string $lastSync = null,
      ?string $context = null,
      ?string $languageIso = null,
      ?string $siteTopic = null,
      ?string $sitePurpose = null,
      ?string $siteAudience = null,
      ?string $status = null,
      ?int $translateNames = null
  ) {

    $this->contextPresent = $contextPresent;
    $this->lastSync = $lastSync ? DateTime::createFromFormat( 'Y-m-d\TH:i:s.v\Z' ,$lastSync ) : null;
    $this->context = $context;
    $this->languageIso = $languageIso;
    $this->siteTopic = $siteTopic;
    $this->sitePurpose = $sitePurpose;
    $this->siteAudience = $siteAudience;
    $this->status = $status;
    $this->translateNames = $translateNames;
  }


  public function isContextPresent(): bool {
    return $this->contextPresent;
  }


  public function jsonSerialize(): array {
     return [
       'contextPresent' => $this->contextPresent,
       'lastSync' => $this->lastSync ? $this->lastSync->format( 'Y-m-d H:i:s' ) : null,
       'context' => $this->context,
       'languageIso' => $this->languageIso,
       'siteTopic' => $this->siteTopic,
       'sitePurpose' => $this->sitePurpose,
       'siteAudience' => $this->siteAudience,
       'status' => $this->status,
       'translateNames' => $this->translateNames,
     ];
  }


}
