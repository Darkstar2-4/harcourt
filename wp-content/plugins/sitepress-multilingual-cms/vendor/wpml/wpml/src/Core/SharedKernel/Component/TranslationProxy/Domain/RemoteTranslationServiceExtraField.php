<?php

namespace WPML\Core\SharedKernel\Component\TranslationProxy\Domain;

class RemoteTranslationServiceExtraField {

  private $type;

  private $label;

  private $name;

  private $items;


  public function __construct( string $type, string $label, string $name, $items ) {
    $this->type  = $type;
    $this->label = $label;
    $this->name  = $name;
    $this->items = $this->prepareItems( $items );
  }


  public function getType(): string {
    return $this->type;
  }


  public function getLabel(): string {
    return $this->label;
  }


  public function getName(): string {
    return $this->name;
  }


  public function getItems() {
    return $this->items;
  }


  private function prepareItems( $items ) {
    $preparedItems = new ExtraFieldItems();

    if ( ! $items ) {
      return null;
    }

    $items = is_object( $items ) ? (array) $items : $items;

    foreach ( $items as $key => $val ) {
      $preparedItems->{$key} = $val;
    }

    return $preparedItems;
  }


  public function toArray(): array {
    return [
      'type'  => $this->type,
      'label' => $this->label,
      'name'  => $this->name,
      'items' => $this->items
    ];
  }


}
