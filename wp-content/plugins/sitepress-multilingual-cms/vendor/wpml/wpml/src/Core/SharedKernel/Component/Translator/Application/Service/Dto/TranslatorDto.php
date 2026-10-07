<?php

namespace WPML\Core\SharedKernel\Component\Translator\Application\Service\Dto;

class TranslatorDto {

  private $id;

  private $name;

  private $userName;

  private $languagePairs;


  public function __construct(
    int $id,
    string $name,
    string $userName,
    array $languagePairs
  ) {
    $this->id            = $id;
    $this->name          = $name;
    $this->userName      = $userName;
    $this->languagePairs = $languagePairs;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getName(): string {
    return $this->name;
  }


  public function getUserName(): string {
    return $this->userName;
  }


  public function getLanguagePairs(): array {
    return $this->languagePairs;
  }


  public function toArray(): array {
    return [
      'id'            => $this->getId(),
      'name'          => $this->getName(),
      'userName'      => $this->getUserName(),
      'languagePairs' => array_map(
        function ( LanguagePairDto $languagePairDto ) {
          return $languagePairDto->toArray();
        },
        $this->getLanguagePairs()
      ),
    ];
  }


}
