<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses an array.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class ArrayObj extends Component
{
    public $raw = array();

    public $values = array();

    public function __construct(array $raw = array(), array $values = array())
    {
        $this->raw = $raw;
        $this->values = $values;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = empty($options['type']) ? new self() : array();

        $lastRaw = '';

        $lastValue = '';

        $brackets = 0;

        $isCommaLast = false;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE)
                || ($token->type === Token::TYPE_COMMENT)
            ) {
                $lastRaw .= $token->token;
                $lastValue = trim($lastValue) . ' ';
                continue;
            }

            if (($brackets === 0)
                && (($token->type !== Token::TYPE_OPERATOR)
                || ($token->value !== '('))
            ) {
                $parser->error('An opening bracket was expected.', $token);
                break;
            }

            if ($token->type === Token::TYPE_OPERATOR) {
                if ($token->value === '(') {
                    if (++$brackets === 1) {
                        continue;
                    }
                } elseif ($token->value === ')') {
                    if (--$brackets === 0) {
                        break;
                    }
                } elseif ($token->value === ',') {
                    if ($brackets === 1) {
                        $isCommaLast = true;
                        if (empty($options['type'])) {
                            $ret->raw[] = trim($lastRaw);
                            $ret->values[] = trim($lastValue);
                            $lastRaw = $lastValue = '';
                        }
                    }
                    continue;
                }
            }

            if (empty($options['type'])) {
                $lastRaw .= $token->token;
                $lastValue .= $token->value;
            } else {
                $ret[] = $options['type']::parse(
                    $parser,
                    $list,
                    empty($options['typeOptions']) ? array() : $options['typeOptions']
                );
            }
        }

        $lastRaw = trim($lastRaw);
        if ((empty($options['type']))
            && ((strlen($lastRaw) > 0) || ($isCommaLast))
        ) {
            $ret->raw[] = $lastRaw;
            $ret->values[] = trim($lastValue);
        }

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return implode(', ', $component);
        } elseif (! empty($component->raw)) {
            return '(' . implode(', ', $component->raw) . ')';
        }

        return '(' . implode(', ', $component->values) . ')';
    }
}
