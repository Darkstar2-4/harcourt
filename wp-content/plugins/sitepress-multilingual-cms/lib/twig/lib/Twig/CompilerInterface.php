<?php

namespace WPML\Core;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
interface Twig_CompilerInterface
{
    public function compile(\WPML\Core\Twig_NodeInterface $node);
    public function getSource();
}
