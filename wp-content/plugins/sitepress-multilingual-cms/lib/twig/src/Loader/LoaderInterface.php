<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Loader;

use WPML\Core\Twig\Error\LoaderError;
interface LoaderInterface
{
    public function getSource($name);
    public function getCacheKey($name);
    public function isFresh($name, $time);
}
\class_alias('WPML\\Core\\Twig\\Loader\\LoaderInterface', 'WPML\\Core\\Twig_LoaderInterface');
