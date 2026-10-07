<?php

namespace WPML\Auryn;

interface Reflector
{
    public function getClass($class);

    public function getCtor($class);

    public function getCtorParams($class);

    public function getParamTypeHint(\ReflectionFunctionAbstract $function, \ReflectionParameter $param);

    public function getFunction($functionName);

    public function getMethod($classNameOrInstance, $methodName);
}
