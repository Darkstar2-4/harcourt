<?php


namespace PhpMyAdmin\SqlParser;

use PhpMyAdmin\SqlParser\Exceptions\LexerException;

if (! defined('USE_UTF_STRINGS')) {

    define('USE_UTF_STRINGS', true);
}

/**
 * Performs lexical analysis over a SQL statement and splits it in multiple
 * tokens.
 *
 * The output of the lexer is affected by the context of the SQL statement.
 *
 * @category Lexer
 *
 * @license  https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 *
 * @see      Context
 */
class Lexer extends Core
{
    public static $PARSER_METHODS = array(

        'parseDelimiter',
        'parseWhitespace',
        'parseNumber',
        'parseComment',
        'parseOperator',
        'parseBool',
        'parseString',
        'parseSymbol',
        'parseKeyword',
        'parseLabel',
        'parseUnknown'
    );

    public $str = '';

    public $len = 0;

    public $last = 0;

    public $list;

    public static $DEFAULT_DELIMITER = ';';

    public $delimiter;

    public $delimiterLen;

    public static function getTokens($str, $strict = false, $delimiter = null)
    {
        $lexer = new self($str, $strict, $delimiter);

        return $lexer->list;
    }

    public function __construct($str, $strict = false, $delimiter = null)
    {
        $len = $str instanceof UtfString ? $str->length() : strlen($str);

        if (! $str instanceof UtfString && USE_UTF_STRINGS && $len !== mb_strlen($str, 'UTF-8')) {
            $str = new UtfString($str);
        }

        $this->str = $str;
        $this->len = $str instanceof UtfString ? $str->length() : $len;

        $this->strict = $strict;

        $this->setDelimiter(
            ! empty($delimiter) ? $delimiter : static::$DEFAULT_DELIMITER
        );

        $this->lex();
    }

    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
        $this->delimiterLen = strlen($delimiter);
    }

    public function lex()
    {

        $list = new TokensList();

        $lastToken = null;

        for ($this->last = 0, $lastIdx = 0; $this->last < $this->len; $lastIdx = ++$this->last) {
            $token = null;

            foreach (static::$PARSER_METHODS as $method) {
                if ($token = $this->$method()) {
                    break;
                }
            }

            if ($token === null) {
                $token = new Token($this->str[$this->last]);
                $this->error(
                    'Unexpected character.',
                    $this->str[$this->last],
                    $this->last
                );
            } elseif ($lastToken !== null
                && $token->type === Token::TYPE_SYMBOL
                && $token->flags & Token::FLAG_SYMBOL_VARIABLE
                && (
                    $lastToken->type === Token::TYPE_STRING
                    || (
                        $lastToken->type === Token::TYPE_SYMBOL
                        && $lastToken->flags & Token::FLAG_SYMBOL_BACKTICK
                    )
                )
            ) {
                $lastToken->token .= $token->token;
                $lastToken->type = Token::TYPE_SYMBOL;
                $lastToken->flags = Token::FLAG_SYMBOL_USER;
                $lastToken->value .= '@' . $token->value;
                continue;
            } elseif ($lastToken !== null
                && $token->type === Token::TYPE_KEYWORD
                && $lastToken->type === Token::TYPE_OPERATOR
                && $lastToken->value === '.'
            ) {
                $token->type = Token::TYPE_NONE;
                $token->flags = 0;
                $token->value = $token->token;
            }

            $token->position = $lastIdx;

            $list->tokens[$list->count++] = $token;

            if ($token->type === Token::TYPE_NONE && $token->value === 'DELIMITER') {
                if ($this->last + 1 >= $this->len) {
                    $this->error(
                        'Expected whitespace(s) before delimiter.',
                        '',
                        $this->last + 1
                    );
                    continue;
                }

                $pos = ++$this->last;
                if (($token = $this->parseWhitespace()) !== null) {
                    $token->position = $pos;
                    $list->tokens[$list->count++] = $token;
                }

                if ($this->last + 1 >= $this->len) {
                    $this->error(
                        'Expected delimiter.',
                        '',
                        $this->last + 1
                    );
                    continue;
                }
                $pos = $this->last + 1;

                $this->delimiter = null;
                $delimiterLen = 0;
                while (++$this->last < $this->len && ! Context::isWhitespace($this->str[$this->last]) && $delimiterLen < 15) {
                    $this->delimiter .= $this->str[$this->last];
                    ++$delimiterLen;
                }

                if (empty($this->delimiter)) {
                    $this->error(
                        'Expected delimiter.',
                        '',
                        $this->last
                    );
                    $this->delimiter = ';';
                }

                --$this->last;

                $this->delimiterLen = strlen($this->delimiter);
                $token = new Token($this->delimiter, Token::TYPE_DELIMITER);
                $token->position = $pos;
                $list->tokens[$list->count++] = $token;
            }

            $lastToken = $token;
        }

        $list->tokens[$list->count++] = new Token(null, Token::TYPE_DELIMITER);

        $this->list = $list;

        $this->solveAmbiguityOnStarOperator();
    }

    private function solveAmbiguityOnStarOperator()
    {
        $iBak = $this->list->idx;
        while (null !== ($starToken = $this->list->getNextOfTypeAndValue(Token::TYPE_OPERATOR, '*'))) {
            if (($next = $this->list->getNext()) !== null) {
                if (($next->type === Token::TYPE_KEYWORD && in_array($next->value, array('FROM', 'USING'), true))
                    || ($next->type === Token::TYPE_OPERATOR && in_array($next->value, array(',', ')'), true))
                ) {
                    $starToken->flags = Token::FLAG_OPERATOR_SQL;
                }
            }
        }
        $this->list->idx = $iBak;
    }

    public function error($msg, $str = '', $pos = 0, $code = 0)
    {
        $error = new LexerException(
            Translator::gettext($msg),
            $str,
            $pos,
            $code
        );
        parent::error($error);
    }

    public function parseKeyword()
    {
        $token = '';

        $ret = null;

        $iEnd = $this->last;

        $lastSpace = false;

        for ($j = 1; $j < Context::KEYWORD_MAX_LENGTH && $this->last < $this->len; ++$j, ++$this->last) {
            if (Context::isWhitespace($this->str[$this->last])) {
                if ($lastSpace) {
                    --$j;
                    continue;
                }
                $lastSpace = true;
            } else {
                $lastSpace = false;
            }

            $token .= $this->str[$this->last];
            if (($this->last + 1 === $this->len || Context::isSeparator($this->str[$this->last + 1]))
                && $flags = Context::isKeyword($token)
            ) {
                $ret = new Token($token, Token::TYPE_KEYWORD, $flags);
                $iEnd = $this->last;

            }
        }

        $this->last = $iEnd;

        return $ret;
    }

    public function parseLabel()
    {
        $token = '';

        $ret = null;

        $iEnd = $this->last;
        for ($j = 1; $j < Context::LABEL_MAX_LENGTH && $this->last < $this->len; ++$j, ++$this->last) {
            if ($this->str[$this->last] === ':' && $j > 1) {
                $token .= $this->str[$this->last];
                $ret = new Token($token, Token::TYPE_LABEL);
                $iEnd = $this->last;
                break;
            } elseif (Context::isWhitespace($this->str[$this->last]) && $j > 1) {
                --$j;
            } elseif (Context::isSeparator($this->str[$this->last])) {
                break;
            }
            $token .= $this->str[$this->last];
        }

        $this->last = $iEnd;

        return $ret;
    }

    public function parseOperator()
    {
        $token = '';

        $ret = null;

        $iEnd = $this->last;

        for ($j = 1; $j < Context::OPERATOR_MAX_LENGTH && $this->last < $this->len; ++$j, ++$this->last) {
            $token .= $this->str[$this->last];
            if ($flags = Context::isOperator($token)) {
                $ret = new Token($token, Token::TYPE_OPERATOR, $flags);
                $iEnd = $this->last;
            }
        }

        $this->last = $iEnd;

        return $ret;
    }

    public function parseWhitespace()
    {
        $token = $this->str[$this->last];

        if (! Context::isWhitespace($token)) {
            return null;
        }

        while (++$this->last < $this->len && Context::isWhitespace($this->str[$this->last])) {
            $token .= $this->str[$this->last];
        }

        --$this->last;

        return new Token($token, Token::TYPE_WHITESPACE);
    }

    public function parseComment()
    {
        $iBak = $this->last;
        $token = $this->str[$this->last];

        if (Context::isComment($token)) {
            while (++$this->last < $this->len
                && $this->str[$this->last] !== "\n"
            ) {
                $token .= $this->str[$this->last];
            }
            if ($this->last < $this->len) {
                --$this->last;
            }

            return new Token($token, Token::TYPE_COMMENT, Token::FLAG_COMMENT_BASH);
        }

        if (++$this->last < $this->len) {
            $token .= $this->str[$this->last];
            if (Context::isComment($token)) {
                $next = $this->last+1;
                if (($next < $this->len) && $this->str[$next] === '*') {
                    $this->last = $iBak;

                    return null;
                }

                $flags = Token::FLAG_COMMENT_C;

                if ($token === '*/') {
                    return new Token($token, Token::TYPE_COMMENT, $flags);
                }

                if ($this->last + 1 < $this->len
                    && $this->str[$this->last + 1] === '!'
                ) {
                    $flags |= Token::FLAG_COMMENT_MYSQL_CMD;
                    $token .= $this->str[++$this->last];

                    while (++$this->last < $this->len
                        && $this->str[$this->last] >= '0'
                        && $this->str[$this->last] <= '9'
                    ) {
                        $token .= $this->str[$this->last];
                    }
                    --$this->last;

                    return new Token($token, Token::TYPE_COMMENT, $flags);
                }

                while (++$this->last < $this->len
                    && (
                        $this->str[$this->last - 1] !== '*'
                        || $this->str[$this->last] !== '/'
                    )
                ) {
                    $token .= $this->str[$this->last];
                }

                if ($this->last < $this->len) {
                    $token .= $this->str[$this->last];
                }

                return new Token($token, Token::TYPE_COMMENT, $flags);
            }
        }

        if (++$this->last < $this->len) {
            $token .= $this->str[$this->last];
            $end = false;
        } else {
            --$this->last;
            $end = true;
        }
        if (Context::isComment($token, $end)) {
            if ($this->str[$this->last] !== "\n") {
                while (++$this->last < $this->len
                    && $this->str[$this->last] !== "\n"
                ) {
                    $token .= $this->str[$this->last];
                }
            }
            if ($this->last < $this->len) {
                --$this->last;
            }

            return new Token($token, Token::TYPE_COMMENT, Token::FLAG_COMMENT_SQL);
        }

        $this->last = $iBak;

        return null;
    }

    public function parseBool()
    {
        if ($this->last + 3 >= $this->len) {
            return null;
        }

        $iBak = $this->last;
        $token = $this->str[$this->last] . $this->str[++$this->last]
        . $this->str[++$this->last] . $this->str[++$this->last];

        if (Context::isBool($token)) {
            return new Token($token, Token::TYPE_BOOL);
        } elseif (++$this->last < $this->len) {
            $token .= $this->str[$this->last];
            if (Context::isBool($token)) {
                return new Token($token, Token::TYPE_BOOL, 1);
            }
        }

        $this->last = $iBak;

        return null;
    }

    public function parseNumber()
    {
        $iBak = $this->last;
        $token = '';
        $flags = 0;
        $state = 1;
        for (; $this->last < $this->len; ++$this->last) {
            if ($state === 1) {
                if ($this->str[$this->last] === '-') {
                    $flags |= Token::FLAG_NUMBER_NEGATIVE;
                } elseif ($this->last + 1 < $this->len
                    && $this->str[$this->last] === '0'
                    && (
                        $this->str[$this->last + 1] === 'x'
                        || $this->str[$this->last + 1] === 'X'
                    )
                ) {
                    $token .= $this->str[$this->last++];
                    $state = 2;
                } elseif ($this->str[$this->last] >= '0' && $this->str[$this->last] <= '9') {
                    $state = 3;
                } elseif ($this->str[$this->last] === '.') {
                    $state = 4;
                } elseif ($this->str[$this->last] === 'b') {
                    $state = 7;
                } elseif ($this->str[$this->last] !== '+') {
                    break;
                }
            } elseif ($state === 2) {
                $flags |= Token::FLAG_NUMBER_HEX;
                if (! (
                        ($this->str[$this->last] >= '0' && $this->str[$this->last] <= '9')
                        || ($this->str[$this->last] >= 'A' && $this->str[$this->last] <= 'F')
                        || ($this->str[$this->last] >= 'a' && $this->str[$this->last] <= 'f')
                    )
                ) {
                    break;
                }
            } elseif ($state === 3) {
                if ($this->str[$this->last] === '.') {
                    $state = 4;
                } elseif ($this->str[$this->last] === 'e' || $this->str[$this->last] === 'E') {
                    $state = 5;
                } elseif (($this->str[$this->last] >= 'a' && $this->str[$this->last] <= 'z')
                    || ($this->str[$this->last] >= 'A' && $this->str[$this->last] <= 'Z')) {
                    $state = -$state;
                } elseif ($this->str[$this->last] < '0' || $this->str[$this->last] > '9') {
                    break;
                }
            } elseif ($state === 4) {
                $flags |= Token::FLAG_NUMBER_FLOAT;
                if ($this->str[$this->last] === 'e' || $this->str[$this->last] === 'E') {
                    $state = 5;
                } elseif (($this->str[$this->last] >= 'a' && $this->str[$this->last] <= 'z')
                    || ($this->str[$this->last] >= 'A' && $this->str[$this->last] <= 'Z')) {
                    $state = -$state;
                } elseif ($this->str[$this->last] < '0' || $this->str[$this->last] > '9') {
                    break;
                }
            } elseif ($state === 5) {
                $flags |= Token::FLAG_NUMBER_APPROXIMATE;
                if ($this->str[$this->last] === '+' || $this->str[$this->last] === '-'
                    || ($this->str[$this->last] >= '0' && $this->str[$this->last] <= '9')
                ) {
                    $state = 6;
                } elseif (($this->str[$this->last] >= 'a' && $this->str[$this->last] <= 'z')
                    || ($this->str[$this->last] >= 'A' && $this->str[$this->last] <= 'Z')) {
                    $state = -$state;
                } else {
                    break;
                }
            } elseif ($state === 6) {
                if ($this->str[$this->last] < '0' || $this->str[$this->last] > '9') {
                    break;
                }
            } elseif ($state === 7) {
                $flags |= Token::FLAG_NUMBER_BINARY;
                if ($this->str[$this->last] === '\'') {
                    $state = 8;
                } else {
                    break;
                }
            } elseif ($state === 8) {
                if ($this->str[$this->last] === '\'') {
                    $state = 9;
                } elseif ($this->str[$this->last] !== '0'
                    && $this->str[$this->last] !== '1'
                ) {
                    break;
                }
            } elseif ($state === 9) {
                break;
            }
            $token .= $this->str[$this->last];
        }
        if ($state === 2 || $state === 3
            || ($token !== '.' && $state === 4)
            || $state === 6 || $state === 9
        ) {
            --$this->last;

            return new Token($token, Token::TYPE_NUMBER, $flags);
        }
        $this->last = $iBak;

        return null;
    }

    public function parseString($quote = '')
    {
        $token = $this->str[$this->last];
        if (! ($flags = Context::isString($token)) && $token !== $quote) {
            return null;
        }
        $quote = $token;

        while (++$this->last < $this->len) {
            if ($this->last + 1 < $this->len
                && (
                    ($this->str[$this->last] === $quote && $this->str[$this->last + 1] === $quote)
                    || ($this->str[$this->last] === '\\' && $quote !== '`')
                )
            ) {
                $token .= $this->str[$this->last] . $this->str[++$this->last];
            } else {
                if ($this->str[$this->last] === $quote) {
                    break;
                }
                $token .= $this->str[$this->last];
            }
        }

        if ($this->last >= $this->len || $this->str[$this->last] !== $quote) {
            $this->error(
                sprintf(
                    Translator::gettext('Ending quote %1$s was expected.'),
                    $quote
                ),
                '',
                $this->last
            );
        } else {
            $token .= $this->str[$this->last];
        }

        return new Token($token, Token::TYPE_STRING, $flags);
    }

    public function parseSymbol()
    {
        $token = $this->str[$this->last];
        if (! ($flags = Context::isSymbol($token))) {
            return null;
        }

        if ($flags & Token::FLAG_SYMBOL_VARIABLE) {
            if ($this->last + 1 < $this->len && $this->str[++$this->last] === '@') {
                $token .= $this->str[$this->last++];
                $flags |= Token::FLAG_SYMBOL_SYSTEM;
            }
        } elseif ($flags & Token::FLAG_SYMBOL_PARAMETER) {
            if ($token !== '?' && $this->last + 1 < $this->len) {
                ++$this->last;
            }
        } else {
            $token = '';
        }

        $str = null;

        if ($this->last < $this->len) {
            if (($str = $this->parseString('`')) === null) {
                if (($str = $this->parseUnknown()) === null) {
                    $this->error(
                        'Variable name was expected.',
                        $this->str[$this->last],
                        $this->last
                    );
                }
            }
        }

        if ($str !== null) {
            $token .= $str->token;
        }

        return new Token($token, Token::TYPE_SYMBOL, $flags);
    }

    public function parseUnknown()
    {
        $token = $this->str[$this->last];
        if (Context::isSeparator($token)) {
            return null;
        }

        while (++$this->last < $this->len && ! Context::isSeparator($this->str[$this->last])) {
            $token .= $this->str[$this->last];

            if (substr($token, -$this->delimiterLen) === $this->delimiter) {
                $token = substr($token, 0, -$this->delimiterLen);
                $this->last -= $this->delimiterLen - 1;
                break;
            }
        }

        --$this->last;

        return new Token($token);
    }

    public function parseDelimiter()
    {
        $idx = 0;

        while ($idx < $this->delimiterLen && $this->last + $idx < $this->len) {
            if ($this->delimiter[$idx] !== $this->str[$this->last + $idx]) {
                return null;
            }
            ++$idx;
        }

        $this->last += $this->delimiterLen - 1;

        return new Token($this->delimiter, Token::TYPE_DELIMITER);
    }
}
