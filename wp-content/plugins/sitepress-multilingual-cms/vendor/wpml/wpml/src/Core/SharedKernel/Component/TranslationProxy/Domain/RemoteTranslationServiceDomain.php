<?php

namespace WPML\Core\SharedKernel\Component\TranslationProxy\Domain;

class RemoteTranslationServiceDomain {

  private $id;

  private $name;

  private $requiresAuthentication;

  private $description;

  private $url;

  private $logoUrl;

  private $customFields;

  private $customFieldsData;

  private $extraFields;

  private $autoRefreshProjectOptions;

  private $maximumJobsPerBatch;


  public function __construct(
    int $id,
    string $name,
    bool $requiresAuthentication,
    string $description,
    string $url,
    string $logoUrl,
    array $customFields,
    array $customFieldsData,
    array $extraFields,
    ?int $maximumJobsPerBatch = null,
    bool $autoRefreshProjectOptions = false
  ) {
    $this->id                     = $id;
    $this->name                   = $name;
    $this->requiresAuthentication = $requiresAuthentication;
    $this->description            = $description;
    $this->url                    = $url;
    $this->logoUrl                = $logoUrl;
    $this->customFields           = $customFields;
    $this->customFieldsData       = $customFieldsData;
    $this->extraFields            = $extraFields;
    $this->maximumJobsPerBatch    = $maximumJobsPerBatch;
    $this->autoRefreshProjectOptions = $autoRefreshProjectOptions;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getName(): string {
    return $this->name;
  }


  public function isRequiresAuthentication(): bool {
    return $this->requiresAuthentication;
  }


  public function getDescription(): string {
    return $this->description;
  }


  public function getUrl(): string {
    return $this->url;
  }


  public function getLogoUrl(): string {
    return $this->logoUrl;
  }


  public function getCustomFields(): array {
    return $this->customFields;
  }


  public function getCustomFieldsData(): array {
    return $this->customFieldsData;
  }


  public function isAuthenticated(): bool {
    if ( ! $this->requiresAuthentication ) {
      return true;
    }

    return ! empty( $this->customFieldsData );
  }


  public function setExtraFields( array $extraFields ) {
    $this->extraFields = $extraFields;
  }


  public function getExtraFields(): array {
    return $this->extraFields;
  }


  public function getMaximumJobsPerBatch() {
    return $this->maximumJobsPerBatch;
  }


  public function getAutoRefreshProjectOptions(): bool {
    return $this->autoRefreshProjectOptions;
  }


  public function toArray(): array {
    return [
      'id'                  => $this->getId(),
      'name'                => $this->getName(),
      'url'                 => $this->getUrl(),
      'isAuthenticated'     => $this->isAuthenticated(),
      'maximumJobsPerBatch' => $this->getMaximumJobsPerBatch(),
      'extraFields'         => array_map(
        function ( $field ) {
          return $field->toArray();
        },
        $this->getExtraFields()
      ),
      'autoRefreshProjectOptions' => $this->getAutoRefreshProjectOptions()
    ];
  }


}
