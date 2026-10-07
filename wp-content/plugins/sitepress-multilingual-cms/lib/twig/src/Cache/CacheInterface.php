<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\Cache;

interface CacheInterface
{
    public function generateKey($name, $className);
    public function write($key, $content);
    public function load($key);
    public function getTimestamp($key);
}
\class_alias('WPML\\Core\\Twig\\Cache\\CacheInterface', 'WPML\\Core\\Twig_CacheInterface');
