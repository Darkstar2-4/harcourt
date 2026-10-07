<?php

namespace WPML\Core\Component\Translation\Domain\Priority;

class PrioritizableItem {

    private $id;

    private $type;

    private $postType;

    private $postParent;

    private $menuOrder;

    private $postTitle;

    private $postModifiedGmt;

    private $stringDomain;

    private $stringContext;

    private $isFeatured;

    private $stockStatus;


  public function __construct(
        int $id,
        ItemType $type,
        $postType = null,
        int $postParent = 0,
        int $menuOrder = 0,
        string $postTitle = '',
        int $postModifiedGmt = 0,
        $stringDomain = null,
        $stringContext = null,
        bool $isFeatured = false,
        $stockStatus = null
    ) {
      $this->id              = $id;
      $this->type            = $type;
      $this->postType        = $postType;
      $this->postParent      = $postParent;
      $this->menuOrder       = $menuOrder;
      $this->postTitle       = $postTitle;
      $this->postModifiedGmt = $postModifiedGmt;
      $this->stringDomain    = $stringDomain;
      $this->stringContext   = $stringContext;
      $this->isFeatured      = $isFeatured;
      $this->stockStatus     = $stockStatus;
  }


  public function getId(): int {
      return $this->id;
  }


  public function getType(): ItemType {
      return $this->type;
  }


  public function getPostType() {
      return $this->postType;
  }


  public function getPostParent(): int {
      return $this->postParent;
  }


  public function getMenuOrder(): int {
      return $this->menuOrder;
  }


  public function getPostTitle(): string {
      return $this->postTitle;
  }


  public function getPostModifiedGmt(): int {
      return $this->postModifiedGmt;
  }


  public function getStringDomain() {
      return $this->stringDomain;
  }


  public function getStringContext() {
      return $this->stringContext;
  }


  public function isFeatured(): bool {
      return $this->isFeatured;
  }


  public function getStockStatus() {
      return $this->stockStatus;
  }


  public function isPage(): bool {
      return $this->type->isPost() && $this->postType === 'page';
  }


  public function isProduct(): bool {
      return $this->type->isPost() && $this->postType === 'product';
  }


  public function isBlogPost(): bool {
      return $this->type->isPost() && $this->postType === 'post';
  }


  public function hasNoParent(): bool {
      return $this->postParent === 0;
  }


}
