<?php


namespace PhpMyAdmin\SqlParser;

class Core
{
    public $strict = false;

    public $errors = array();

    public function error($error)
    {
        if ($this->strict) {
            throw $error;
        }
        $this->errors[] = $error;
    }
}
