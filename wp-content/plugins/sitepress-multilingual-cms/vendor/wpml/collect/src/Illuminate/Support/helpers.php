<?php

use WPML\Collect\Support\Arr;
use WPML\Collect\Support\Collection;

if (! function_exists('wpml_collect')) {
	function wpml_collect( $value = null ) {
		return new Collection( $value );
	}
}

if (! function_exists('value')) {
	function value( $value, ...$args ) {
		return $value instanceof Closure ? $value( ...$args ) : $value;
	}
}

if (! function_exists('data_get')) {
    function data_get($target, $key, $default = null)
    {
        if (is_null($key)) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', $key);

        while (($segment = array_shift($key)) !== null) {
            if ($segment === '*') {
                if ($target instanceof Collection) {
                    $target = $target->all();
                } elseif (! is_array($target)) {
                    return value($default);
                }

                $result = Arr::pluck($target, $key);

                return in_array('*', $key) ? Arr::collapse($result) : $result;
            }

            if (Arr::accessible($target) && Arr::exists($target, $segment)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return value($default);
            }
        }

        return $target;
    }
}

if (! function_exists('with')) {
    function with($object)
    {
        return $object;
    }
}
