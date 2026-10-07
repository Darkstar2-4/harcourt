<?php


namespace PhpMyAdmin\SqlParser;

use PhpMyAdmin\SqlParser\Components\FunctionCall;
use PhpMyAdmin\SqlParser\Components\OptionsArray;

/**
 * Abstract statement definition.
 *
 * @category Statements
 *
 * @license  https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
abstract class Statement
{
    public static $OPTIONS = array();

    public static $CLAUSES = array();

    public static $END_OPTIONS = array();

    public $options;

    public $first;

    public $last;

    public function __construct(?Parser $parser = null, ?TokensList $list = null)
    {
        if (($parser !== null) && ($list !== null)) {
            $this->parse($parser, $list);
        }
    }

    public function build()
    {
        $query = '';

        $built = array();

        $clauses = $this->getClauses();

        foreach ($clauses as $clause) {
            $name = $clause[0];

            $type = $clause[1];

            $class = Parser::$KEYWORD_PARSERS[$name]['class'];

            $field = Parser::$KEYWORD_PARSERS[$name]['field'];

            if (empty($this->$field)) {
                continue;
            }

            if ($type & 1) {
                if (! empty($built[$field])) {
                    continue;
                }
                $built[$field] = true;
            }

            if ($type & 2) {
                $query = trim($query) . ' ' . $name;
            }

            if ($type & 1) {
                $query = trim($query) . ' ' . $class::build($this->$field);
            }
        }

        return $query;
    }

    public function parse(Parser $parser, TokensList $list)
    {
        $parsedClauses = array();

        $this->first = $list->idx;

        $parsedOptions = empty(static::$OPTIONS);

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->value === ')') && ($parser->brackets > 0)) {
                --$parser->brackets;
                continue;
            }

            if ($token->type !== Token::TYPE_KEYWORD) {
                if (($token->type !== Token::TYPE_COMMENT)
                    && ($token->type !== Token::TYPE_WHITESPACE)
                ) {
                    $parser->error('Unexpected token.', $token);
                }
                continue;
            }

            if (($token->keyword === 'UNION') ||
                ($token->keyword === 'UNION ALL') ||
                ($token->keyword === 'UNION DISTINCT') ||
                ($token->keyword === 'EXCEPT') ||
                ($token->keyword === 'INTERSECT')
            ) {
                break;
            }

            $lastIdx = $list->idx;

            if ($this instanceof Statements\SelectStatement
                && $token->value === 'ON'
            ) {
                ++$list->idx;

                $first = $list->getNextOfType(Token::TYPE_KEYWORD);
                $second = $list->getNextOfType(Token::TYPE_KEYWORD);
                $third = $list->getNextOfType(Token::TYPE_KEYWORD);

                if ($first && $second && $third
                    && $first->value === 'DUPLICATE'
                    && $second->value === 'KEY'
                    && $third->value === 'UPDATE'
                ) {
                    $list->idx = $lastIdx;
                    break;
                }
            }
            $list->idx = $lastIdx;

            $class = null;

            $field = null;

            $options = array();

            if (! empty(Parser::$KEYWORD_PARSERS[$token->value])
                || ! empty(Parser::$STATEMENT_PARSERS[$token->value])
            ) {
                if (! empty($parsedClauses[$token->value])) {
                    $parser->error(
                        'This type of clause was previously parsed.',
                        $token
                    );
                    break;
                }
                $parsedClauses[$token->value] = true;
            }

            $token_value = in_array($token->keyword, array('TRUNCATE')) ? $token->keyword : $token->value;
            if (! empty(Parser::$KEYWORD_PARSERS[$token_value]) && $list->idx < $list->count) {
                $class = Parser::$KEYWORD_PARSERS[$token_value]['class'];
                $field = Parser::$KEYWORD_PARSERS[$token_value]['field'];
                if (! empty(Parser::$KEYWORD_PARSERS[$token_value]['options'])) {
                    $options = Parser::$KEYWORD_PARSERS[$token_value]['options'];
                }
            }

            if (! empty(Parser::$STATEMENT_PARSERS[$token->keyword])) {
                if (! empty(static::$CLAUSES)
                    && empty(static::$CLAUSES[$token->value])
                ) {
                    $parser->error(
                        'A new statement was found, but no delimiter between it and the previous one.',
                        $token
                    );
                    break;
                }
                if (! $parsedOptions) {
                    if (empty(static::$OPTIONS[$token->value])) {
                        ++$list->idx;
                    }
                    $this->options = OptionsArray::parse(
                        $parser,
                        $list,
                        static::$OPTIONS
                    );
                    $parsedOptions = true;
                }
            } elseif ($class === null) {
                if ($this instanceof Statements\SelectStatement
                    && ($token->value === 'FOR UPDATE'
                        || $token->value === 'LOCK IN SHARE MODE')
                ) {
                    $this->end_options = OptionsArray::parse(
                        $parser,
                        $list,
                        static::$END_OPTIONS
                    );
                } elseif ($this instanceof Statements\SetStatement
                    && ($token->value === 'COLLATE'
                        || $token->value === 'DEFAULT')
                ) {
                    $this->end_options = OptionsArray::parse(
                        $parser,
                        $list,
                        static::$END_OPTIONS
                    );
                } else {
                    $parser->error('Unrecognized keyword.', $token);
                    continue;
                }
            }

            $this->before($parser, $list, $token);

            if ($class !== null) {
                if ($list->idx >= $list->count) {
                    $parser->error('Keyword at end of statement.', $token);
                    continue;
                }
                ++$list->idx;
                $this->$field = $class::parse($parser, $list, $options);
            }

            $this->after($parser, $list, $token);

            if ($class === 'PhpMyAdmin\\SqlParser\\Components\\FunctionCall'
                && $list->offsetGet($list->idx)->type === Token::TYPE_DELIMITER
            ) {
                --$list->idx;
            }
        }

        $this->last = --$list->idx;
    }

    public function before(Parser $parser, TokensList $list, Token $token)
    {
    }

    public function after(Parser $parser, TokensList $list, Token $token)
    {
    }

    public function getClauses()
    {
        return static::$CLAUSES;
    }

    public function __toString()
    {
        return $this->build();
    }

    public function validateClauseOrder($parser, $list)
    {
        $clauses = array_flip(array_keys($this->getClauses()));

        if (empty($clauses) || count($clauses) === 0) {
            return true;
        }

        $minIdx = -1;

        $minJoin = 0;

        $maxJoin = 0;

        $error = 0;
        $lastIdx = 0;
        foreach ($clauses as $clauseType => $index) {
            $clauseStartIdx = Utils\Query::getClauseStartOffset(
                $this,
                $list,
                $clauseType
            );

            if ($clauseStartIdx !== -1
                && $this instanceof Statements\SelectStatement
                && ($clauseType === 'FORCE'
                    || $clauseType === 'IGNORE'
                    || $clauseType === 'USE')
            ) {
                return true;
            }

            if ($clauseStartIdx !== -1) {
                if ($minJoin === 0 && stripos($clauseType, 'JOIN')) {
                    $minJoin = $maxJoin = $clauseStartIdx;
                } elseif ($minJoin !== 0 && ! stripos($clauseType, 'JOIN')) {
                    $maxJoin = $lastIdx;
                } elseif ($maxJoin < $clauseStartIdx && stripos($clauseType, 'JOIN')) {
                    $error = 1;
                }
            }

            if ($clauseStartIdx !== -1 && $clauseStartIdx < $minIdx) {
                if ($minJoin === 0 || $error === 1) {
                    $token = $list->tokens[$clauseStartIdx];
                    $parser->error(
                        'Unexpected ordering of clauses.',
                        $token
                    );

                    return false;
                }
                $minIdx = $clauseStartIdx;
            } elseif ($clauseStartIdx !== -1) {
                $minIdx = $clauseStartIdx;
            }

            $lastIdx = ($clauseStartIdx !== -1) ? $clauseStartIdx : $lastIdx;
        }

        return true;
    }
}
