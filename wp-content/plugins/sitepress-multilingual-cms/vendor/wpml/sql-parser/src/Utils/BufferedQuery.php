<?php


namespace PhpMyAdmin\SqlParser\Utils;

use PhpMyAdmin\SqlParser\Context;

/**
 * Buffer query utilities.
 *
 * Implements a specialized lexer used to extract statements from large inputs
 * that are being buffered. After each statement has been extracted, a lexer or
 * a parser may be used.
 *
 * @category   Lexer
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class BufferedQuery
{

    const STATUS_STRING = 16;
    const STATUS_STRING_SINGLE_QUOTES = 17;
    const STATUS_STRING_DOUBLE_QUOTES = 18;
    const STATUS_STRING_BACKTICK = 20;

    const STATUS_COMMENT = 32;
    const STATUS_COMMENT_BASH = 33;
    const STATUS_COMMENT_C = 34;
    const STATUS_COMMENT_SQL = 36;

    public $query = '';

    public $options = array();

    public $delimiter;

    public $delimiterLen;

    public $status;

    public $current = '';

    public function __construct($query = '', array $options = array())
    {
        $this->options = array_merge(
            array(
                'delimiter' => ';',

                'parse_delimiter' => false,

                'add_delimiter' => false,
            ),
            $options
        );

        $this->query = $query;
        $this->setDelimiter($this->options['delimiter']);
    }

    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
        $this->delimiterLen = strlen($delimiter);
    }

    public function extract($end = false)
    {
        static $i = 0;

        if (empty($this->query)) {
            return false;
        }

        $len = strlen($this->query);

        $loopLen = $end ? $len : $len - 16;

        for (; $i < $loopLen; ++$i) {
            if ((($this->status & static::STATUS_COMMENT) === 0) && ($this->query[$i] === '\\')) {
                $this->current .= $this->query[$i] . ($i + 1 < $len ? $this->query[++$i] : '');
                continue;
            }

            if ($this->status === static::STATUS_STRING_SINGLE_QUOTES) {
                if ($this->query[$i] === '\'') {
                    $this->status = 0;
                }
                $this->current .= $this->query[$i];
                continue;
            } elseif ($this->status === static::STATUS_STRING_DOUBLE_QUOTES) {
                if ($this->query[$i] === '"') {
                    $this->status = 0;
                }
                $this->current .= $this->query[$i];
                continue;
            } elseif ($this->status === static::STATUS_STRING_BACKTICK) {
                if ($this->query[$i] === '`') {
                    $this->status = 0;
                }
                $this->current .= $this->query[$i];
                continue;
            } elseif (($this->status === static::STATUS_COMMENT_BASH)
                || ($this->status === static::STATUS_COMMENT_SQL)
            ) {
                if ($this->query[$i] === "\n") {
                    $this->status = 0;
                }
                $this->current .= $this->query[$i];
                continue;
            } elseif ($this->status === static::STATUS_COMMENT_C) {
                if (($this->query[$i - 1] === '*') && ($this->query[$i] === '/')) {
                    $this->status = 0;
                }
                $this->current .= $this->query[$i];
                continue;
            }

            if ($this->query[$i] === '\'') {
                $this->status = static::STATUS_STRING_SINGLE_QUOTES;
                $this->current .= $this->query[$i];
                continue;
            } elseif ($this->query[$i] === '"') {
                $this->status = static::STATUS_STRING_DOUBLE_QUOTES;
                $this->current .= $this->query[$i];
                continue;
            } elseif ($this->query[$i] === '`') {
                $this->status = static::STATUS_STRING_BACKTICK;
                $this->current .= $this->query[$i];
                continue;
            }

            if ($this->query[$i] === '#') {
                $this->status = static::STATUS_COMMENT_BASH;
                $this->current .= $this->query[$i];
                continue;
            } elseif ($i + 2 < $len) {
                if (($this->query[$i] === '-')
                 && ($this->query[$i + 1] === '-')
                 && Context::isWhitespace($this->query[$i + 2])) {
                    $this->status = static::STATUS_COMMENT_SQL;
                    $this->current .= $this->query[$i];
                    continue;
                } elseif (($this->query[$i] === '/')
                 && ($this->query[$i + 1] === '*')
                 && ($this->query[$i + 2] !== '!')) {
                    $this->status = static::STATUS_COMMENT_C;
                    $this->current .= $this->query[$i];
                    continue;
                }
            }

            if (($i + 9 < $len)
                && (($this->query[$i] === 'D') || ($this->query[$i] === 'd'))
                && (($this->query[$i + 1] === 'E') || ($this->query[$i + 1] === 'e'))
                && (($this->query[$i + 2] === 'L') || ($this->query[$i + 2] === 'l'))
                && (($this->query[$i + 3] === 'I') || ($this->query[$i + 3] === 'i'))
                && (($this->query[$i + 4] === 'M') || ($this->query[$i + 4] === 'm'))
                && (($this->query[$i + 5] === 'I') || ($this->query[$i + 5] === 'i'))
                && (($this->query[$i + 6] === 'T') || ($this->query[$i + 6] === 't'))
                && (($this->query[$i + 7] === 'E') || ($this->query[$i + 7] === 'e'))
                && (($this->query[$i + 8] === 'R') || ($this->query[$i + 8] === 'r'))
                && Context::isWhitespace($this->query[$i + 9])
            ) {
                $iBak = $i;
                $i += 9;

                while (($i < $len) && Context::isWhitespace($this->query[$i])) {
                    ++$i;
                }

                $delimiter = '';
                while (($i < $len) && (! Context::isWhitespace($this->query[$i]))) {
                    $delimiter .= $this->query[$i++];
                }

                if (($delimiter !== '')
                    && ((($i < $len) && Context::isWhitespace($this->query[$i]))
                    || (($i === $len) && $end))
                ) {
                    $this->setDelimiter($delimiter);

                    $ret = '';
                    if (! empty($this->options['parse_delimiter'])) {
                        $ret = trim(
                            $this->current . ' ' . substr($this->query, $iBak, $i - $iBak)
                        );
                    }

                    $this->query = substr($this->query, $i);
                    $i = 0;

                    $this->current = '';

                    return $ret;
                }

                $i = $iBak;

                return false;
            }

            if (($this->query[$i] === $this->delimiter[0])
                && (($this->delimiterLen === 1)
                || (substr($this->query, $i, $this->delimiterLen) === $this->delimiter))
            ) {
                $ret = $this->current;

                if (! empty($this->options['add_delimiter'])) {
                    $ret .= $this->delimiter;
                }

                $this->query = substr($this->query, $i + $this->delimiterLen);
                $i = 0;

                $this->current = '';

                return trim($ret);
            }

            $this->current .= $this->query[$i];
        }

        if ($end && ($i === $len)) {
            $ret = $this->current;

            $this->query = '';
            $i = 0;

            $this->current = '';

            return trim($ret);
        }

        return '';
    }
}
