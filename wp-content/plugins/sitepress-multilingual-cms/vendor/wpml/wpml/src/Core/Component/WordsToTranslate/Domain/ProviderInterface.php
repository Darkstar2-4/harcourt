<?php

namespace WPML\Core\Component\WordsToTranslate\Domain;

interface ProviderInterface {


  public function getByIdAndTypeForLangs( $id, $type, $langs, $freshTranslation = false );


  public function useThisContentForItem( $id, $type, $content );


}
