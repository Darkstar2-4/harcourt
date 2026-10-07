<?php

namespace WPML\Core\Component\WordsToTranslate\Domain;

class Config {
  const KEY_WORDS_PER_IDEOGRAM = 'words_per_ideogram';

  const LANGS = [
    'ja' => [
      self::KEY_WORDS_PER_IDEOGRAM => 0.5
    ],
    'ko' => [
      self::KEY_WORDS_PER_IDEOGRAM => 0.5
    ],
    'zh-hans' => [
      self::KEY_WORDS_PER_IDEOGRAM => 0.55
    ],
    'zh-hant' => [
      self::KEY_WORDS_PER_IDEOGRAM => 0.55
    ],
  ];

}
