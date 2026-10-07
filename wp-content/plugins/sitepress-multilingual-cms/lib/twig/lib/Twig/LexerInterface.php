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
use WPML\Core\Twig\Source;
use WPML\Core\Twig\TokenStream;
interface Twig_LexerInterface
{
    public function tokenize($code, $name = null);
}
