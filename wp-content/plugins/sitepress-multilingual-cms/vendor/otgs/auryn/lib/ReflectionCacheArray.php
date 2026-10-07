<?php

namespace WPML\Auryn;

class ReflectionCacheArray implements ReflectionCache
{
    private $cache = array();

    public function fetch($key)
    {
        return (isset($this->cache[$key]) || array_key_exists($key, $this->cache))
            ? $this->cache[$key]
            : false;
    }

    public function store($key, $data)
    {
        $this->cache[$key] = $data;
    }
}
