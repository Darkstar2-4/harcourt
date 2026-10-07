<?php

namespace WPML\Core;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 * (c) Arnaud Le Blanc
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use WPML\Core\Twig\TokenParser\TokenParserInterface;
interface Twig_TokenParserBrokerInterface
{
    public function getTokenParser($tag);
    public function setParser(\WPML\Core\Twig_ParserInterface $parser);
    public function getParser();
}
