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
abstract class AbstractExtension implements \WPML\Core\Twig\Extension\ExtensionInterface
{
    public function initRuntime(\WPML\Core\Twig\Environment $environment)
    {
    }
    public function getTokenParsers()
    {
        return [];
    }
    public function getNodeVisitors()
    {
        return [];
    }
    public function getFilters()
    {
        return [];
    }
    public function getTests()
    {
        return [];
    }
    public function getFunctions()
    {
        return [];
    }
    public function getOperators()
    {
        return [];
    }
    public function getGlobals()
    {
        return [];
    }
    public function getName()
    {
        return \get_class($this);
    }
}
\class_alias('WPML\\Core\\Twig\\Extension\\AbstractExtension', 'WPML\\Core\\Twig_Extension');
