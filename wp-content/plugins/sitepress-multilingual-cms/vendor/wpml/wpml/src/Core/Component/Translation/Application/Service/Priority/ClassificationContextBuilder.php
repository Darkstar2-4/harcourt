<?php

namespace WPML\Core\Component\Translation\Application\Service\Priority;

use WPML\Core\Component\Translation\Application\Query\Priority\SiteSettingsQueryInterface;
use WPML\Core\Component\Translation\Domain\Priority\Classifier\ClassificationContext;

class ClassificationContextBuilder {

    const DEFAULT_STRING_DOMAIN_PRIORITIES = [
        'admin_texts_theme_mods_*' => 1,
        'admin_texts_widget_*'     => 2,
        'WordPress'                => 3,
        'default'                  => 4,
    ];

    const DEFAULT_STRING_CONTEXT_PRIORITIES = [
        'menu'   => 1,
        'header' => 2,
        'footer' => 3,
        'nav'    => 4,
    ];

    private $stringDomainPriorities;

    private $stringContextPriorities;

    private $cptPriorities;

    private $siteSettingsQuery;


    public function __construct(
        ?SiteSettingsQueryInterface $siteSettingsQuery = null,
        array $stringDomainPriorities = [],
        array $stringContextPriorities = [],
        array $cptPriorities = []
    ) {
        $this->siteSettingsQuery       = $siteSettingsQuery;
        $this->stringDomainPriorities  = $stringDomainPriorities ?: self::DEFAULT_STRING_DOMAIN_PRIORITIES;
        $this->stringContextPriorities = $stringContextPriorities ?: self::DEFAULT_STRING_CONTEXT_PRIORITIES;
        $this->cptPriorities           = $cptPriorities;
    }


    public function build( array $parentMap = [] ): ClassificationContext {
        $homePageId = $this->getHomePageId();

        $homepageSubtreeDepths = [];
      if ( $homePageId !== null ) {
          $homepageSubtreeDepths = $this->buildHomepageSubtreeDepths( $homePageId, $parentMap );
      }

        return new ClassificationContext(
          $homePageId,
          $parentMap,
          $homepageSubtreeDepths,
          [],
          $this->stringDomainPriorities,
          $this->stringContextPriorities,
          $this->cptPriorities
        );
    }


    private function getHomePageId() {
      if ( $this->siteSettingsQuery === null ) {
          return null;
      }

        return $this->siteSettingsQuery->getHomePageId();
    }


    private function buildHomepageSubtreeDepths( int $homePageId, array $parentMap ): array {
        $childrenMap = $this->buildChildrenMap( $parentMap );
        $depths      = [];

        $this->traverseSubtree( $homePageId, $childrenMap, 1, $depths );

        return $depths;
    }


    private function buildChildrenMap( array $parentMap ): array {
        $childrenMap = [];

      foreach ( $parentMap as $postId => $parentId ) {
        if ( ! isset( $childrenMap[ $parentId ] ) ) {
            $childrenMap[ $parentId ] = [];
        }
          $childrenMap[ $parentId ][] = $postId;
      }

        return $childrenMap;
    }


    private function traverseSubtree(
        int $parentId,
        array $childrenMap,
        int $currentDepth,
        array &$depths
    ) {
      if ( ! isset( $childrenMap[ $parentId ] ) ) {
          return;
      }

      foreach ( $childrenMap[ $parentId ] as $childId ) {
          $depths[ $childId ] = $currentDepth;
          $this->traverseSubtree( $childId, $childrenMap, $currentDepth + 1, $depths );
      }
    }


}
