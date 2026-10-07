<?php

namespace WPML\Auryn;

class InjectionException extends InjectorException
{
    public $dependencyChain;

    public function __construct(array $inProgressMakes, $message = "", $code = 0, ?\Exception $previous = null)
    {
        $this->dependencyChain = array_flip($inProgressMakes);
        ksort($this->dependencyChain);
        
        parent::__construct($message, $code, $previous);
    }

    public static function fromInvalidCallable(
        array $inProgressMakes,
        $callableOrMethodStr,
        ?\Exception $previous = null
    ) {
        $callableString = null;

        if (is_string($callableOrMethodStr)) {
            $callableString .= $callableOrMethodStr;
        } else if (is_array($callableOrMethodStr) && 
            array_key_exists(0, $callableOrMethodStr) &&
            array_key_exists(0, $callableOrMethodStr)) {
            if (is_string($callableOrMethodStr[0]) && is_string($callableOrMethodStr[1])) {
                $callableString .= $callableOrMethodStr[0].'::'.$callableOrMethodStr[1];
            } else if (is_object($callableOrMethodStr[0]) && is_string($callableOrMethodStr[1])) {
                $callableString .= sprintf(
                    "[object(%s), '%s']",
                    get_class($callableOrMethodStr[0]),
                    $callableOrMethodStr[1]
                );
            }
        }

        if ($callableString) {
            $callableString = substr($callableString, 0, 250);
            $message = sprintf(
                "%s. Invalid callable was '%s'",
                Injector::M_INVOKABLE,
                $callableString
            );
        } else {
            $message = Injector::M_INVOKABLE;
        }

        return new self($inProgressMakes, $message, Injector::E_INVOKABLE, $previous);
    }

    public function getDependencyChain()
    {
        return $this->dependencyChain;
    }
}
