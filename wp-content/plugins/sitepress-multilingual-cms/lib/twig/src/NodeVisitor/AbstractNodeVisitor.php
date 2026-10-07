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
use WPML\Core\Twig\Node\Node;
abstract class AbstractNodeVisitor implements \WPML\Core\Twig\NodeVisitor\NodeVisitorInterface
{
    public final function enterNode(\WPML\Core\Twig_NodeInterface $node, \WPML\Core\Twig\Environment $env)
    {
        if (!$node instanceof \WPML\Core\Twig\Node\Node) {
            throw new \LogicException(\sprintf('%s only supports \\Twig\\Node\\Node instances.', __CLASS__));
        }
        return $this->doEnterNode($node, $env);
    }
    public final function leaveNode(\WPML\Core\Twig_NodeInterface $node, \WPML\Core\Twig\Environment $env)
    {
        if (!$node instanceof \WPML\Core\Twig\Node\Node) {
            throw new \LogicException(\sprintf('%s only supports \\Twig\\Node\\Node instances.', __CLASS__));
        }
        return $this->doLeaveNode($node, $env);
    }
    protected abstract function doEnterNode(\WPML\Core\Twig\Node\Node $node, \WPML\Core\Twig\Environment $env);
    protected abstract function doLeaveNode(\WPML\Core\Twig\Node\Node $node, \WPML\Core\Twig\Environment $env);
}
\class_alias('WPML\\Core\\Twig\\NodeVisitor\\AbstractNodeVisitor', 'WPML\\Core\\Twig_BaseNodeVisitor');
