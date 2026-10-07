<?php


namespace PhpMyAdmin\SqlParser;

/**
 * Implements array-like access for UTF-8 strings.
 *
 * In this library, this class should be used to parse UTF-8 queries.
 *
 * @category Misc
 *
 * @license  https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class UtfString implements \ArrayAccess
{
    public $str = '';

    public $byteIdx = 0;

    public $charIdx = 0;

    public $byteLen = 0;

    public $charLen = 0;

    public function __construct($str)
    {
        $this->str = $str;
        $this->byteIdx = 0;
        $this->charIdx = 0;
        $this->byteLen = mb_strlen($str, '8bit');
        if (! mb_check_encoding($str, 'UTF-8')) {
            $this->charLen = 0;
        } else {
            $this->charLen = mb_strlen($str, 'UTF-8');
        }
    }

    public function offsetExists($offset)
    {
        return ($offset >= 0) && ($offset < $this->charLen);
    }

    public function offsetGet($offset)
    {
        if (($offset < 0) || ($offset >= $this->charLen)) {
            return null;
        }

        $delta = $offset - $this->charIdx;

        if ($delta > 0) {
            while ($delta-- > 0) {
                $this->byteIdx += static::getCharLength($this->str[$this->byteIdx]);
                ++$this->charIdx;
            }
        } elseif ($delta < 0) {
            while ($delta++ < 0) {
                do {
                    $byte = ord($this->str[--$this->byteIdx]);
                } while (($byte >= 128) && ($byte < 192));
                --$this->charIdx;
            }
        }

        $bytesCount = static::getCharLength($this->str[$this->byteIdx]);

        $ret = '';
        for ($i = 0; $bytesCount-- > 0; ++$i) {
            $ret .= $this->str[$this->byteIdx + $i];
        }

        return $ret;
    }

    public function offsetSet($offset, $value)
    {
        throw new \Exception('Not implemented.');
    }

    public function offsetUnset($offset)
    {
        throw new \Exception('Not implemented.');
    }

    public static function getCharLength($byte)
    {
        $byte = ord($byte);
        if ($byte < 128) {
            return 1;
        } elseif ($byte < 224) {
            return 2;
        } elseif ($byte < 240) {
            return 3;
        } elseif ($byte < 248) {
            return 4;
        } elseif ($byte < 252) {
            return 5;
        }

        return 6;
    }

    public function length()
    {
        return $this->charLen;
    }

    public function __toString()
    {
        return $this->str;
    }
}
