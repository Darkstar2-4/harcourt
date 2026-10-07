<?php

namespace WPML\Core\SharedKernel\Component\Translation\Domain;

class TranslationEditorType {
  const WORDPRESS = 'wordpress';
  const ATE = 'ate';
  const CLASSIC = 'classic';
  const NONE = 'none';


  public static function getTypes() {
    return [
      self::WORDPRESS,
      self::ATE,
      self::CLASSIC,
      self::NONE,
    ];
  }


}
