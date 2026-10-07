<?php

namespace WPML\Core\SharedKernel\Component\User\Application\Query\Dto;

class UserDto {

  private $id;

  private $displayName;

  private $email;


  public function __construct( int $id, string $displayName, string $email ) {
    $this->id          = $id;
    $this->displayName = $displayName;
    $this->email       = $email;
  }


  public function getId(): int {
    return $this->id;
  }


  public function getDisplayName(): string {
    return $this->displayName;
  }


  public function getEmail(): string {
    return $this->email;
  }


  public function toArray(): array {
    return [
      'id'          => $this->getId(),
      'displayName' => $this->getDisplayName(),
      'email'       => $this->getEmail(),
    ];
  }


}
