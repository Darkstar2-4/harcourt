<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig\NodeVisitor;

use WPML\Core\Twig\Environment;
interface NodeVisitorInterface
{
    public function enterNode(\WPML\Core\Twig_NodeInterface $node, \WPML\Core\Twig\Environment $env);
    public function leaveNode(\WPML\Core\Twig_NodeInterface $node, \WPML\Core\Twig\Environment $env);
    public function getPriority();
}
\class_alias('WPML\\Core\\Twig\\NodeVisitor\\NodeVisitorInterface', 'WPML\\Core\\Twig_NodeVisitorInterface');
\class_exists('WPML\\Core\\Twig\\Environment');
