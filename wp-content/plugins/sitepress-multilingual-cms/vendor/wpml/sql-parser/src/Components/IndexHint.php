<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Parses an Index hint.
 *
 * @category   Components
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class IndexHint extends Component
{
    public $type;

    public $indexOrKey;

    public $for;

    public $indexes = array();

    public function __construct(?string $type = null, ?string $indexOrKey = null, ?string $for = null, array $indexes = array())
    {
        $this->type = $type;
        $this->indexOrKey = $indexOrKey;
        $this->for = $for;
        $this->indexes = $indexes;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = array();
        $expr = new self();
        $expr->type = isset($options['type']) ? $options['type'] : null;
        $state = 0;

        if ($list->idx > 0) {
            --$list->idx;
        }
        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }
            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            switch ($state) {
                case 0:
                    if ($token->type === Token::TYPE_KEYWORD) {
                        if ($token->keyword === 'USE' || $token->keyword === 'IGNORE' || $token->keyword === 'FORCE') {
                            $expr->type = $token->keyword;
                            $state = 1;
                        } else {
                            break 2;
                        }
                    }
                    break;
                case 1:
                    if ($token->type === Token::TYPE_KEYWORD) {
                        if ($token->keyword === 'INDEX' || $token->keyword === 'KEY') {
                            $expr->indexOrKey = $token->keyword;
                        } else {
                            $parser->error('Unexpected keyword.', $token);
                        }
                        $state = 2;
                    } else {
                        $parser->error('Unexpected token.', $token);
                    }
                    break;
                case 2:
                    if ($token->type === Token::TYPE_KEYWORD && $token->keyword === 'FOR') {
                        $state = 3;
                    } else {
                        $expr->indexes = ExpressionArray::parse($parser, $list);
                        $state = 0;
                        $ret[] = $expr;
                        $expr = new self();
                    }
                    break;
                case 3:
                    if ($token->type === Token::TYPE_KEYWORD) {
                        if ($token->keyword === 'JOIN' || $token->keyword === 'GROUP BY' || $token->keyword === 'ORDER BY') {
                            $expr->for = $token->keyword;
                        } else {
                            $parser->error('Unexpected keyword.', $token);
                        }
                        $state = 4;
                    } else {
                        $parser->error('Unexpected token.', $token);
                    }
                    break;
                case 4:
                    $expr->indexes = ExpressionArray::parse($parser, $list);
                    $state = 0;
                    $ret[] = $expr;
                    $expr = new self();
                    break;
            }
        }
        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        if (is_array($component)) {
            return implode(' ', $component);
        }

        $ret = $component->type . ' ' . $component->indexOrKey . ' ';
        if ($component->for !== null) {
            $ret .= 'FOR ' . $component->for . ' ';
        }
        return $ret . ExpressionArray::build($component->indexes);
    }
}
