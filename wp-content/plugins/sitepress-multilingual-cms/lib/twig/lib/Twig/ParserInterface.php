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
use WPML\Core\Twig\Error\SyntaxError;
use WPML\Core\Twig\Node\ModuleNode;
use WPML\Core\Twig\TokenStream;
interface Twig_ParserInterface
{
    public function parse(\WPML\Core\Twig\TokenStream $stream);
}
