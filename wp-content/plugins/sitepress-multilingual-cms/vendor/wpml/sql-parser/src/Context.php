<?php


namespace PhpMyAdmin\SqlParser;

use PhpMyAdmin\SqlParser\Exceptions\LoaderException;

/**
 * Holds the configuration of the context that is currently used.
 *
 * @category Contexts
 *
 * @license  https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
abstract class Context
{
    const KEYWORD_MAX_LENGTH = 30;

    const LABEL_MAX_LENGTH = 16;

    const OPERATOR_MAX_LENGTH = 4;

    public static $defaultContext = '\\PhpMyAdmin\\SqlParser\\Contexts\\ContextMySql50700';

    public static $loadedContext = '\\PhpMyAdmin\\SqlParser\\Contexts\\ContextMySql50700';

    public static $contextPrefix = '\\PhpMyAdmin\\SqlParser\\Contexts\\Context';

    public static $KEYWORDS = array();

    public static $OPERATORS = array(

        '%' => 1,
        '*' => 1,
        '+' => 1,
        '-' => 1,
        '/' => 1,

        '!' => 2,
        '!=' => 2,
        '&&' => 2,
        '<' => 2,
        '<=' => 2,
        '<=>' => 2,
        '<>' => 2,
        '=' => 2,
        '>' => 2,
        '>=' => 2,
        '||' => 2,

        '&' => 4,
        '<<' => 4,
        '>>' => 4,
        '^' => 4,
        '|' => 4,
        '~' => 4,

        ':=' => 8,

        '(' => 16,
        ')' => 16,
        '.' => 16,
        ',' => 16,
        ';' => 16
    );

    public static $MODE = 0;


    const SQL_MODE_COMPAT_MYSQL = 2;

    const SQL_MODE_ALLOW_INVALID_DATES = 1;

    const SQL_MODE_ANSI_QUOTES = 2;

    const SQL_MODE_ERROR_FOR_DIVISION_BY_ZERO = 4;

    const SQL_MODE_HIGH_NOT_PRECEDENCE = 8;

    const SQL_MODE_IGNORE_SPACE = 16;

    const SQL_MODE_NO_AUTO_CREATE_USER = 32;

    const SQL_MODE_NO_AUTO_VALUE_ON_ZERO = 64;

    const SQL_MODE_NO_BACKSLASH_ESCAPES = 128;

    const SQL_MODE_NO_DIR_IN_CREATE = 256;

    const SQL_MODE_NO_ENGINE_SUBSTITUTION = 512;

    const SQL_MODE_NO_FIELD_OPTIONS = 1024;

    const SQL_MODE_NO_KEY_OPTIONS = 2048;

    const SQL_MODE_NO_TABLE_OPTIONS = 4096;

    const SQL_MODE_NO_UNSIGNED_SUBTRACTION = 8192;

    const SQL_MODE_NO_ZERO_DATE = 16384;

    const SQL_MODE_NO_ZERO_IN_DATE = 32768;

    const SQL_MODE_ONLY_FULL_GROUP_BY = 65536;

    const SQL_MODE_PIPES_AS_CONCAT = 131072;

    const SQL_MODE_REAL_AS_FLOAT = 262144;

    const SQL_MODE_STRICT_ALL_TABLES = 524288;

    const SQL_MODE_STRICT_TRANS_TABLES = 1048576;


    const SQL_MODE_NO_ENCLOSING_QUOTES = 1073741824;


    const SQL_MODE_ANSI = 393234;

    const SQL_MODE_DB2 = 138258;

    const SQL_MODE_MAXDB = 138290;

    const SQL_MODE_MSSQL = 138258;

    const SQL_MODE_ORACLE = 138290;

    const SQL_MODE_POSTGRESQL = 138258;

    const SQL_MODE_TRADITIONAL = 1622052;


    public static function isKeyword($str, $isReserved = false)
    {
        $str = strtoupper($str);

        if (isset(static::$KEYWORDS[$str])) {
            if ($isReserved && ! (static::$KEYWORDS[$str] & Token::FLAG_KEYWORD_RESERVED)) {
                return null;
            }

            return static::$KEYWORDS[$str];
        }

        return null;
    }


    public static function isOperator($str)
    {
        if (! isset(static::$OPERATORS[$str])) {
            return null;
        }

        return static::$OPERATORS[$str];
    }


    public static function isWhitespace($str)
    {
        return ($str === ' ') || ($str === "\r") || ($str === "\n") || ($str === "\t");
    }


    public static function isComment($str, $end = false)
    {
        $len = strlen($str);
        if ($len === 0) {
            return null;
        }

        if ($str[0] === '#') {
            return Token::FLAG_COMMENT_BASH;
        }
        if (($len > 1) && ($str[0] === '/') && ($str[1] === '*')) {
            return ($len > 2) && ($str[2] === '!') ?
                Token::FLAG_COMMENT_MYSQL_CMD : Token::FLAG_COMMENT_C;
        }
        if (($len > 1) && ($str[0] === '*') && ($str[1] === '/')) {
            return Token::FLAG_COMMENT_C;
        }
        if (($len > 2) && ($str[0] === '-')
            && ($str[1] === '-') && static::isWhitespace($str[2])
        ) {
            return Token::FLAG_COMMENT_SQL;
        }
        if (($len === 2) && $end && ($str[0] === '-') && ($str[1] === '-')) {
            return Token::FLAG_COMMENT_SQL;
        }

        return null;
    }


    public static function isBool($str)
    {
        $str = strtoupper($str);

        return ($str === 'TRUE') || ($str === 'FALSE');
    }


    public static function isNumber($str)
    {
        return (($str >= '0') && ($str <= '9')) || ($str === '.')
            || ($str === '-') || ($str === '+') || ($str === 'e') || ($str === 'E');
    }


    public static function isSymbol($str)
    {
        if (strlen($str) === 0) {
            return null;
        }
        if ($str[0] === '@') {
            return Token::FLAG_SYMBOL_VARIABLE;
        } elseif ($str[0] === '`') {
            return Token::FLAG_SYMBOL_BACKTICK;
        } elseif ($str[0] === ':' || $str[0] === '?') {
            return Token::FLAG_SYMBOL_PARAMETER;
        }

        return null;
    }


    public static function isString($str)
    {
        if (strlen($str) === 0) {
            return null;
        }
        if ($str[0] === '\'') {
            return Token::FLAG_STRING_SINGLE_QUOTES;
        } elseif ($str[0] === '"') {
            return Token::FLAG_STRING_DOUBLE_QUOTES;
        }

        return null;
    }


    public static function isSeparator($str)
    {
        return ($str <= '~')
            && ($str !== '_')
            && ($str !== '$')
            && (($str < '0') || ($str > '9'))
            && (($str < 'a') || ($str > 'z'))
            && (($str < 'A') || ($str > 'Z'));
    }

    public static function load($context = '')
    {
        if (empty($context)) {
            $context = self::$defaultContext;
        }
        if ($context[0] !== '\\') {
            $context = self::$contextPrefix . $context;
        }
        if (! class_exists($context)) {
            throw @new LoaderException(
                'Specified context ("' . $context . '") does not exist.',
                $context
            );
        }
        self::$loadedContext = $context;
        self::$KEYWORDS = $context::$KEYWORDS;
    }

    public static function loadClosest($context = '')
    {
        $length = strlen($context);
        for ($i = $length; $i > 0;) {
            try {
                static::load($context);
                return $context;
            } catch (LoaderException $e) {
                do {
                    $i -= 2;
                    $part = substr($context, $i, 2);
                    if (! is_numeric($part)) {
                        break 2;
                    }
                } while (intval($part) === 0 && $i > 0);
                $context = substr($context, 0, $i) . '00' . substr($context, $i + 2);
            }
        }
        if (strncmp($context, 'MariaDb', 7) === 0) {
            return static::loadClosest('MariaDb100300');
        } elseif (strncmp($context, 'MySql', 5) === 0) {
            return static::loadClosest('MySql50700');
        }
        return null;
    }

    public static function setMode($mode = '')
    {
        static::$MODE = 0;
        if (empty($mode)) {
            return;
        }
        $mode = explode(',', $mode);
        foreach ($mode as $m) {
            static::$MODE |= constant('static::SQL_MODE_' . $m);
        }
    }

    public static function escape($str, $quote = '`')
    {
        if (is_array($str)) {
            foreach ($str as $key => $value) {
                $str[$key] = static::escape($value);
            }

            return $str;
        }

        if ((static::$MODE & self::SQL_MODE_NO_ENCLOSING_QUOTES)
            && (! static::isKeyword($str, true))
        ) {
            return $str;
        }

        if (static::$MODE & self::SQL_MODE_ANSI_QUOTES) {
            $quote = '"';
        }

        return $quote . str_replace($quote, $quote . $quote, $str) . $quote;
    }

    public static function getIdentifierQuote()
    {
        return self::hasMode(self::SQL_MODE_ANSI_QUOTES) ? '"' : '`';
    }

    public static function hasMode($flag = null)
    {
        if (empty($flag)) {
            return false;
        }
        return (self::$MODE & $flag) === $flag;
    }
}

Context::load();
