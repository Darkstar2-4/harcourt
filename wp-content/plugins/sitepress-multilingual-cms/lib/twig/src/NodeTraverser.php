<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace WPML\Core\Twig;

use WPML\Core\Twig\NodeVisitor\NodeVisitorInterface;
class NodeTraverser
{
    protected $env;
    protected $visitors = [];
    public function __construct(\WPML\Core\Twig\Environment $env, array $visitors = [])
    {
        $this->env = $env;
        foreach ($visitors as $visitor) {
            $this->addVisitor($visitor);
        }
    }
    public function addVisitor(\WPML\Core\Twig\NodeVisitor\NodeVisitorInterface $visitor)
    {
        $this->visitors[$visitor->getPriority()][] = $visitor;
    }
    public function traverse(\WPML\Core\Twig_NodeInterface $node)
    {
        \ksort($this->visitors);
        foreach ($this->visitors as $visitors) {
            foreach ($visitors as $visitor) {
                $node = $this->traverseForVisitor($visitor, $node);
            }
        }
        return $node;
    }
    protected function traverseForVisitor(\WPML\Core\Twig\NodeVisitor\NodeVisitorInterface $visitor, ?\WPML\Core\Twig_NodeInterface $node = null)
    {
        if (null === $node) {
            return;
        }
        $node = $visitor->enterNode($node, $this->env);
        foreach ($node as $k => $n) {
            if (null === $n) {
                continue;
            }
            if (\false !== ($m = $this->traverseForVisitor($visitor, $n)) && null !== $m) {
                if ($m !== $n) {
                    $node->setNode($k, $m);
                }
            } else {
                $node->removeNode($k);
            }
        }
        return $visitor->leaveNode($node, $this->env);
    }
}
\class_alias('WPML\\Core\\Twig\\NodeTraverser', 'WPML\\Core\\Twig_NodeTraverser');
