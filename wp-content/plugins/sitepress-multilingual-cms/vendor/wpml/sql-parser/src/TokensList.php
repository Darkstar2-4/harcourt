<?php


namespace PhpMyAdmin\SqlParser;

/**
 * A structure representing a list of tokens.
 *
 * @category Tokens
 *
 * @license  https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class TokensList implements \ArrayAccess
{
    public $tokens = array();

    public $count = 0;

    public $idx = 0;

    public function __construct(array $tokens = array(), $count = -1)
    {
        if (! empty($tokens)) {
            $this->tokens = $tokens;
            if ($count === -1) {
                $this->count = count($tokens);
            }
        }
    }

    public static function build($list)
    {
        if (is_string($list)) {
            return $list;
        }

        if ($list instanceof self) {
            $list = $list->tokens;
        }

        $ret = '';
        if (is_array($list)) {
            foreach ($list as $tok) {
                $ret .= $tok->token;
            }
        }

        return $ret;
    }

    public function add(Token $token)
    {
        $this->tokens[$this->count++] = $token;
    }

    public function getNext()
    {
        for (; $this->idx < $this->count; ++$this->idx) {
            if (($this->tokens[$this->idx]->type !== Token::TYPE_WHITESPACE)
                && ($this->tokens[$this->idx]->type !== Token::TYPE_COMMENT)
            ) {
                return $this->tokens[$this->idx++];
            }
        }

        return null;
    }

    public function getNextOfType($type)
    {
        for (; $this->idx < $this->count; ++$this->idx) {
            if ($this->tokens[$this->idx]->type === $type) {
                return $this->tokens[$this->idx++];
            }
        }

        return null;
    }

    public function getNextOfTypeAndValue($type, $value)
    {
        for (; $this->idx < $this->count; ++$this->idx) {
            if (($this->tokens[$this->idx]->type === $type)
                && ($this->tokens[$this->idx]->value === $value)
            ) {
                return $this->tokens[$this->idx++];
            }
        }

        return null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->tokens[$this->count++] = $value;
        } else {
            $this->tokens[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $offset < $this->count ? $this->tokens[$offset] : null;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $offset < $this->count;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->tokens[$offset]);
        --$this->count;
        for ($i = $offset; $i < $this->count; ++$i) {
            $this->tokens[$i] = $this->tokens[$i + 1];
        }
        unset($this->tokens[$this->count]);
    }
}
