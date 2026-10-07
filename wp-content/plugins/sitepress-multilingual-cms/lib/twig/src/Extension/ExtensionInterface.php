<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Extension;

use WPML\Core\Twig\Environment;
use WPML\Core\Twig\NodeVisitor\NodeVisitorInterface;
use WPML\Core\Twig\TokenParser\TokenParserInterface;
use WPML\Core\Twig\TwigFilter;
use WPML\Core\Twig\TwigFunction;
use WPML\Core\Twig\TwigTest;
interface ExtensionInterface
{
    public function initRuntime(\WPML\Core\Twig\Environment $environment);
    public function getTokenParsers();
    public function getNodeVisitors();
    public function getFilters();
    public function getTests();
    public function getFunctions();
    public function getOperators();
    public function getGlobals();
    public function getName();
}
\class_alias('WPML\\Core\\Twig\\Extension\\ExtensionInterface', 'WPML\\Core\\Twig_ExtensionInterface');
\class_exists('WPML\\Core\\Twig\\Environment');
