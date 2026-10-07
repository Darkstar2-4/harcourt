<?php

namespace WPML\Core\Component\Translation\Domain\PreviousState;

interface DataCompressInterface {


  public function compress( array $data ): string;


  public function decompress( string $data ): array;


}
