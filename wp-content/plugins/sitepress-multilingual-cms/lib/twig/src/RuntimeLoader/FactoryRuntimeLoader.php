<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\RuntimeLoader;

class FactoryRuntimeLoader implements \WPML\Core\Twig\RuntimeLoader\RuntimeLoaderInterface
{
    private $map;
    public function __construct($map = [])
    {
        $this->map = $map;
    }
    public function load($class)
    {
        if (isset($this->map[$class])) {
            $runtimeFactory = $this->map[$class];
            return $runtimeFactory();
        }
    }
}
\class_alias('WPML\\Core\\Twig\\RuntimeLoader\\FactoryRuntimeLoader', 'WPML\\Core\\Twig_FactoryRuntimeLoader');
