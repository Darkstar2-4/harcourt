<?php


namespace PhpMyAdmin\SqlParser\Utils;

use PhpMyAdmin\SqlParser\Components\JoinKeyword;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Utilities that are used for formatting queries.
 *
 * @category   Misc
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Formatter
{
    public $options;

    public static $SHORT_CLAUSES = array(
        'CREATE' => true,
        'INSERT' => true
    );

    public static $INLINE_CLAUSES = array(
        'CREATE' => true,
        'INTO' => true,
        'LIMIT' => true,
        'PARTITION BY' => true,
        'PARTITION' => true,
        'PROCEDURE' => true,
        'SUBPARTITION BY' => true,
        'VALUES' => true
    );

    public function __construct(array $options = array())
    {
        $this->options = $this->getMergedOptions($options);
    }

    private function getMergedOptions(array $options)
    {
        $options = array_merge(
            $this->getDefaultOptions(),
            $options
        );

        if (isset($options['formats'])) {
            $options['formats'] = self::mergeFormats($this->getDefaultFormats(), $options['formats']);
        } else {
            $options['formats'] = $this->getDefaultFormats();
        }

        if (is_null($options['line_ending'])) {
            $options['line_ending'] = $options['type'] === 'html' ? '<br/>' : "\n";
        }

        if (is_null($options['indentation'])) {
            $options['indentation'] = $options['type'] === 'html' ? '&nbsp;&nbsp;&nbsp;&nbsp;' : '    ';
        }

        $options['parts_newline'] &= $options['clause_newline'];

        return $options;
    }

    protected function getDefaultOptions()
    {
        return array(
            'type' => php_sapi_name() === 'cli' ? 'cli' : 'text',

            'line_ending' => null,

            'indentation' => null,

            'remove_comments' => false,

            'clause_newline' => true,

            'parts_newline' => true,

            'indent_parts' => true
        );
    }

    protected function getDefaultFormats()
    {
        return array(
            array(
                'type' => Token::TYPE_KEYWORD,
                'flags' => Token::FLAG_KEYWORD_RESERVED,
                'html' => 'class="sql-reserved"',
                'cli' => "\x1b[35m",
                'function' => 'strtoupper',
            ),
            array(
                'type' => Token::TYPE_KEYWORD,
                'flags' => 0,
                'html' => 'class="sql-keyword"',
                'cli' => "\x1b[95m",
                'function' => 'strtoupper',
            ),
            array(
                'type' => Token::TYPE_COMMENT,
                'flags' => 0,
                'html' => 'class="sql-comment"',
                'cli' => "\x1b[37m",
                'function' => '',
            ),
            array(
                'type' => Token::TYPE_BOOL,
                'flags' => 0,
                'html' => 'class="sql-atom"',
                'cli' => "\x1b[36m",
                'function' => 'strtoupper',
            ),
            array(
                'type' => Token::TYPE_NUMBER,
                'flags' => 0,
                'html' => 'class="sql-number"',
                'cli' => "\x1b[92m",
                'function' => 'strtolower',
            ),
            array(
                'type' => Token::TYPE_STRING,
                'flags' => 0,
                'html' => 'class="sql-string"',
                'cli' => "\x1b[91m",
                'function' => '',
            ),
            array(
                'type' => Token::TYPE_SYMBOL,
                'flags' => Token::FLAG_SYMBOL_PARAMETER,
                'html' => 'class="sql-parameter"',
                'cli' => "\x1b[31m",
                'function' => '',
            ),
            array(
                'type' => Token::TYPE_SYMBOL,
                'flags' => 0,
                'html' => 'class="sql-variable"',
                'cli' => "\x1b[36m",
                'function' => '',
            )
        );
    }

    private static function mergeFormats(array $formats, array $newFormats)
    {
        $added = array();
        $integers = array(
            'flags',
            'type'
        );
        $strings = array(
            'html',
            'cli',
            'function'
        );

        foreach ($newFormats as $j => $new) {
            foreach ($integers as $name) {
                if (! isset($new[$name])) {
                    $newFormats[$j][$name] = 0;
                }
            }
            foreach ($strings as $name) {
                if (! isset($new[$name])) {
                    $newFormats[$j][$name] = '';
                }
            }
        }

        foreach ($formats as $i => $original) {
            foreach ($newFormats as $j => $new) {
                if ($new['type'] === $original['type']
                    && $original['flags'] === $new['flags']
                ) {
                    $formats[$i] = $new;
                    $added[] = $j;
                }
            }
        }

        foreach ($newFormats as $j => $new) {
            if (! in_array($j, $added)) {
                $formats[] = $new;
            }
        }

        return $formats;
    }

    public function formatList($list)
    {
        $ret = '';

        $indent = 0;

        $lineEnded = false;

        $shortGroup = false;

        $lastClause = '';

        $blocksIndentation = array();

        $blocksLineEndings = array();

        $formattedOptions = false;

        $prev = null;

        for ($list->idx = 0; $list->idx < $list->count; ++$list->idx) {
            $curr = $list->tokens[$list->idx];
            if ($list->idx + 1 < $list->count) {
                $next = $list->tokens[$list->idx + 1];
            } else {
                $next = null;
            }

            if ($curr->type === Token::TYPE_WHITESPACE) {
                if (strpos($curr->token, "\n") !== false && (
                        ($prev !== null && $prev->type === Token::TYPE_COMMENT) ||
                        ($next !== null && $next->type === Token::TYPE_COMMENT)
                    )
                ) {
                    $lineEnded = true;
                }
                continue;
            }

            if ($curr->type === Token::TYPE_COMMENT && $this->options['remove_comments']) {
                continue;
            }

            if ($prev !== null) {
                if (static::isClause($prev) !== false) {
                    $lastClause = $prev->value;
                    $formattedOptions = false;
                }

                if ($this->options['parts_newline']
                    && ! $formattedOptions
                    && empty(self::$INLINE_CLAUSES[$lastClause])
                    && (
                        $curr->type !== Token::TYPE_KEYWORD
                        || (
                            $curr->type === Token::TYPE_KEYWORD
                            && $curr->flags & Token::FLAG_KEYWORD_FUNCTION
                        )
                    )
                ) {
                    $formattedOptions = true;
                    $lineEnded = true;
                    ++$indent;
                }

                if ($isClause = static::isClause($curr)) {
                    if (($isClause === 2 || $this->options['clause_newline']) && empty(self::$SHORT_CLAUSES[$lastClause])) {
                        $lineEnded = true;
                        if ($this->options['parts_newline'] && $indent > 0) {
                            --$indent;
                        }
                    }
                }

                if (($prev->type === Token::TYPE_KEYWORD && isset(JoinKeyword::$JOINS[$prev->value]))
                    || (in_array($curr->value, array('ON', 'USING'), true) && isset(JoinKeyword::$JOINS[$list->tokens[$list->idx - 2]->value]))
                    || isset($list->tokens[$list->idx - 4], JoinKeyword::$JOINS[$list->tokens[$list->idx - 4]->value])
                    || isset($list->tokens[$list->idx - 6], JoinKeyword::$JOINS[$list->tokens[$list->idx - 6]->value])
                ) {
                    $lineEnded = false;
                }

                if ($prev->type === Token::TYPE_KEYWORD && $prev->keyword === 'BEGIN') {
                    $lineEnded = true;
                    $blocksIndentation[] = $indent;
                    ++$indent;
                } elseif ($curr->type === Token::TYPE_KEYWORD && $curr->keyword === 'END') {
                    $lineEnded = true;
                    $indent = array_pop($blocksIndentation);
                }

                if ($prev->type === Token::TYPE_OPERATOR && $prev->value === ',') {
                    if (end($blocksLineEndings) === true
                        || (
                            empty(self::$INLINE_CLAUSES[$lastClause])
                            && ! $shortGroup
                            && $this->options['parts_newline']
                        )
                    ) {
                        $lineEnded = true;
                    }
                }

                if ($prev->type === Token::TYPE_OPERATOR && $prev->value === '(') {
                    $blocksIndentation[] = $indent;
                    $shortGroup = true;
                    if (static::getGroupLength($list) > 30) {
                        ++$indent;
                        $lineEnded = true;
                        $shortGroup = false;
                    }
                    $blocksLineEndings[] = $lineEnded;
                } elseif ($curr->type === Token::TYPE_OPERATOR && $curr->value === ')') {
                    $indent = array_pop($blocksIndentation);
                    $lineEnded |= array_pop($blocksLineEndings);
                    $shortGroup = false;
                }

                $ret .= $this->toString($prev);

                if ($lineEnded) {
                    $ret .= $this->options['line_ending']
                        . str_repeat($this->options['indentation'], $indent);

                    $lineEnded = false;
                } else {
                    if (
                        $prev->keyword === 'DELIMITER'
                        || ! (
                            ($prev->type === Token::TYPE_OPERATOR && ($prev->value === '.' || $prev->value === '('))
                            || ($curr->type === Token::TYPE_OPERATOR && ($curr->value === '.' || $curr->value === ',' || $curr->value === '(' || $curr->value === ')'))
                            || $curr->type === Token::TYPE_DELIMITER && mb_strlen($curr->value, 'UTF-8') < 2
                        )
                    ) {
                        $ret .= ' ';
                    }
                }
            }

            $prev = $curr;
        }

        if ($this->options['type'] === 'cli') {
            return $ret . "\x1b[0m";
        }

        return $ret;
    }

    public function escapeConsole($string)
    {
        return str_replace(
            array(
                "\x00",
                "\x01",
                "\x02",
                "\x03",
                "\x04",
                "\x05",
                "\x06",
                "\x07",
                "\x08",
                "\x09",
                "\x0A",
                "\x0B",
                "\x0C",
                "\x0D",
                "\x0E",
                "\x0F",
                "\x10",
                "\x11",
                "\x12",
                "\x13",
                "\x14",
                "\x15",
                "\x16",
                "\x17",
                "\x18",
                "\x19",
                "\x1A",
                "\x1B",
                "\x1C",
                "\x1D",
                "\x1E",
                "\x1F",
            ),
            array(
                '\x00',
                '\x01',
                '\x02',
                '\x03',
                '\x04',
                '\x05',
                '\x06',
                '\x07',
                '\x08',
                '\x09',
                '\x0A',
                '\x0B',
                '\x0C',
                '\x0D',
                '\x0E',
                '\x0F',
                '\x10',
                '\x11',
                '\x12',
                '\x13',
                '\x14',
                '\x15',
                '\x16',
                '\x17',
                '\x18',
                '\x19',
                '\x1A',
                '\x1B',
                '\x1C',
                '\x1D',
                '\x1E',
                '\x1F',
            ),
            $string
        );
    }

    public function toString($token)
    {
        $text = $token->token;
        static $prev;

        foreach ($this->options['formats'] as $format) {
            if ($token->type === $format['type']
                && ($token->flags & $format['flags']) === $format['flags']
            ) {
                if (! empty($format['function'])) {
                    $func = $format['function'];
                    $text = $func($text);
                }

                if ($this->options['type'] === 'html') {
                    return '<span ' . $format['html'] . '>' . htmlspecialchars($text, ENT_NOQUOTES) . '</span>';
                } elseif ($this->options['type'] === 'cli') {
                    if ($prev !== $format['cli']) {
                        $prev = $format['cli'];

                        return $format['cli'] . $this->escapeConsole($text);
                    }

                    return $this->escapeConsole($text);
                }

                break;
            }
        }

        if ($this->options['type'] === 'cli') {
            if ($prev !== "\x1b[39m") {
                $prev = "\x1b[39m";

                return "\x1b[39m" . $this->escapeConsole($text);
            }

            return $this->escapeConsole($text);
        } elseif ($this->options['type'] === 'html') {
            return htmlspecialchars($text, ENT_NOQUOTES);
        }

        return $text;
    }

    public static function format($query, array $options = array())
    {
        $lexer = new Lexer($query);
        $formatter = new self($options);

        return $formatter->formatList($lexer->list);
    }

    public static function getGroupLength($list)
    {
        $count = 1;

        $length = 0;

        for ($idx = $list->idx; $idx < $list->count; ++$idx) {
            if ($list->tokens[$idx]->type === Token::TYPE_OPERATOR) {
                if ($list->tokens[$idx]->value === '(') {
                    ++$count;
                } elseif ($list->tokens[$idx]->value === ')') {
                    --$count;
                    if ($count === 0) {
                        break;
                    }
                }
            }

            $length += mb_strlen($list->tokens[$idx]->value, 'UTF-8');
        }

        return $length;
    }

    public static function isClause($token)
    {
        if (($token->type === Token::TYPE_KEYWORD && isset(Parser::$STATEMENT_PARSERS[$token->keyword]))
            || ($token->type === Token::TYPE_NONE && strtoupper($token->token) === 'DELIMITER')
        ) {
            return 2;
        } elseif ($token->type === Token::TYPE_KEYWORD && isset(Parser::$KEYWORD_PARSERS[$token->keyword])
        ) {
            return 1;
        }

        return false;
    }
}
