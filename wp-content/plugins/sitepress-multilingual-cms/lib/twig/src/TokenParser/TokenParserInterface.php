<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\TokenParser;

use WPML\Core\Twig\Error\SyntaxError;
use WPML\Core\Twig\Parser;
use WPML\Core\Twig\Token;
interface TokenParserInterface
{
    public function setParser(\WPML\Core\Twig\Parser $parser);
    public function parse(\WPML\Core\Twig\Token $token);
    public function getTag();
}
\class_alias('WPML\\Core\\Twig\\TokenParser\\TokenParserInterface', 'WPML\\Core\\Twig_TokenParserInterface');
\class_exists('WPML\\Core\\Twig\\Token');
\class_exists('WPML\\Core\\Twig\\Parser');
