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

final class TemplateWrapper
{
    private $env;
    private $template;
    public function __construct(\WPML\Core\Twig\Environment $env, \WPML\Core\Twig\Template $template)
    {
        $this->env = $env;
        $this->template = $template;
    }
    public function render($context = [])
    {
        return $this->template->render($context, \func_num_args() > 1 ? \func_get_arg(1) : []);
    }
    public function display($context = [])
    {
        $this->template->display($context, \func_num_args() > 1 ? \func_get_arg(1) : []);
    }
    public function hasBlock($name, $context = [])
    {
        return $this->template->hasBlock($name, $context);
    }
    public function getBlockNames($context = [])
    {
        return $this->template->getBlockNames($context);
    }
    public function renderBlock($name, $context = [])
    {
        $context = $this->env->mergeGlobals($context);
        $level = \ob_get_level();
        if ($this->env->isDebug()) {
            \ob_start();
        } else {
            \ob_start(function () {
                return '';
            });
        }
        try {
            $this->template->displayBlock($name, $context);
        } catch (\Exception $e) {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }
            throw $e;
        } catch (\Throwable $e) {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }
            throw $e;
        }
        return \ob_get_clean();
    }
    public function displayBlock($name, $context = [])
    {
        $this->template->displayBlock($name, $this->env->mergeGlobals($context));
    }
    public function getSourceContext()
    {
        return $this->template->getSourceContext();
    }
    public function getTemplateName()
    {
        return $this->template->getTemplateName();
    }
    public function unwrap()
    {
        return $this->template;
    }
}
\class_alias('WPML\\Core\\Twig\\TemplateWrapper', 'WPML\\Core\\Twig_TemplateWrapper');
