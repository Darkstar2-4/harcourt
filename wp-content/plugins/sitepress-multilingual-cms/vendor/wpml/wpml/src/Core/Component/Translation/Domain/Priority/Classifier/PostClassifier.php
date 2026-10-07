<?php

namespace WPML\Core\Component\Translation\Domain\Priority\Classifier;

use WPML\Core\Component\Translation\Domain\Priority\JobPriority;
use WPML\Core\Component\Translation\Domain\Priority\PrioritizableItem;
use WPML\Core\Component\Translation\Domain\Priority\Rank;
use WPML\Core\Component\Translation\Domain\Priority\Tier;

class PostClassifier implements TierClassifierInterface {

    const EXCLUDED_CPT_POST_TYPES = [ 'post', 'page' ];


  public function canClassify( PrioritizableItem $item, ClassificationContext $context ): bool {
      return $item->getType()->isPost();
  }


  public function classify( PrioritizableItem $item, ClassificationContext $context ): JobPriority {
      $id = $item->getId();

    if ( $context->isHomePage( $id ) ) {
        return $this->createHomepagePriority( $item );
    }

    if ( $item->isPage() ) {
        return $this->classifyPage( $item, $context );
    }

    if ( $item->isBlogPost() ) {
        return $this->createBlogPostPriority( $item );
    }

      return $this->createOtherCptPriority( $item, $context );
  }


  private function classifyPage( PrioritizableItem $item, ClassificationContext $context ): JobPriority {
      $id = $item->getId();

    if ( $context->isInHomepageSubtree( $id ) ) {
        return $this->createPagesUnderHomepagePriority( $item, $context );
    }

    if ( $item->hasNoParent() ) {
        return $this->createPagesNoParentPriority( $item );
    }

      return $this->createRemainingPagesPriority( $item, $context );
  }


  private function createHomepagePriority( PrioritizableItem $item ): JobPriority {
      return new JobPriority(
        $item->getId(),
        Tier::homepage(),
        new Rank( [ 0 ] )
      );
  }


  private function createPagesUnderHomepagePriority(
    PrioritizableItem $item,
    ClassificationContext $context
  ): JobPriority {
      $depth     = $context->getHomepageSubtreeDepth( $item->getId() ) ?? PHP_INT_MAX;
      $menuOrder = $item->getMenuOrder();
      $titleKey  = $this->getTitleSortKey( $item->getPostTitle() );

      return new JobPriority(
        $item->getId(),
        Tier::pagesUnderHomepage(),
        new Rank( [ $depth, $menuOrder, $titleKey, $item->getId() ] )
      );
  }


  private function createPagesNoParentPriority( PrioritizableItem $item ): JobPriority {
      $menuOrder = $item->getMenuOrder();
      $titleKey  = $this->getTitleSortKey( $item->getPostTitle() );

      return new JobPriority(
        $item->getId(),
        Tier::pagesNoParent(),
        new Rank( [ $menuOrder, $titleKey, $item->getId() ] )
      );
  }


  private function createRemainingPagesPriority(
    PrioritizableItem $item,
    ClassificationContext $context
  ): JobPriority {
      $distance  = $context->getHierarchyDepthFromRoot( $item->getId() );
      $menuOrder = $item->getMenuOrder();
      $titleKey  = $this->getTitleSortKey( $item->getPostTitle() );

      return new JobPriority(
        $item->getId(),
        Tier::remainingPages(),
        new Rank( [ $distance, $menuOrder, $titleKey, $item->getId() ] )
      );
  }


  private function createOtherCptPriority( PrioritizableItem $item, ClassificationContext $context ): JobPriority {
      $postType = $item->getPostType();

    if ( $postType !== null && ! in_array( $postType, self::EXCLUDED_CPT_POST_TYPES, true ) ) {
        $cptPriority    = $context->getCptPriority( $postType );
        $modifiedGmtInv = PHP_INT_MAX - $item->getPostModifiedGmt();

        return new JobPriority(
          $item->getId(),
          Tier::otherCpts(),
          new Rank( [ $cptPriority, $modifiedGmtInv, $item->getId() ] )
        );
    }

      return new JobPriority(
        $item->getId(),
        new Tier( PHP_INT_MAX ),
        new Rank( [ $item->getId() ] )
      );
  }


  private function createBlogPostPriority( PrioritizableItem $item ): JobPriority {
      $modifiedGmtInv = PHP_INT_MAX - $item->getPostModifiedGmt();

      return new JobPriority(
        $item->getId(),
        Tier::blogPosts(),
        new Rank( [ $modifiedGmtInv, $item->getId() ] )
      );
  }


  private function getTitleSortKey( string $title ): int {
    if ( $title === '' ) {
        return PHP_INT_MAX;
    }

      $normalized = strtolower( substr( $title, 0, 4 ) );
      $key        = 0;
      $multiplier = 1;

    for ( $i = min( 3, strlen( $normalized ) - 1 ); $i >= 0; $i-- ) {
        $key        += ord( $normalized[ $i ] ) * $multiplier;
        $multiplier *= 256;
    }

      return $key;
  }


}
