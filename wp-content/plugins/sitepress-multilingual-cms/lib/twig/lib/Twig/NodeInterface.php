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
use WPML\Core\Twig\Compiler;
interface Twig_NodeInterface extends \Countable, \IteratorAggregate
{
    public function compile(\WPML\Core\Twig\Compiler $compiler);
    public function getLine();
    public function getNodeTag();
}
