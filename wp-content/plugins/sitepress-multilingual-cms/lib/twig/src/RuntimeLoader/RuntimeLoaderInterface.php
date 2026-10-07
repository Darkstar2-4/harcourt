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

interface RuntimeLoaderInterface
{
    public function load($class);
}
\class_alias('WPML\\Core\\Twig\\RuntimeLoader\\RuntimeLoaderInterface', 'WPML\\Core\\Twig_RuntimeLoaderInterface');
