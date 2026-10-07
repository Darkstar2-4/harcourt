<?php


namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `LIMIT` keyword parser.
 *
 * @category   Keywords
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class Limit extends Component
{
    public $offset;

    public $rowCount;

    public function __construct($rowCount = 0, $offset = 0)
    {
        $this->rowCount = $rowCount;
        $this->offset = $offset;
    }

    public static function parse(Parser $parser, TokensList $list, array $options = array())
    {
        $ret = new self();

        $offset = false;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            if (($token->type === Token::TYPE_KEYWORD) && ($token->flags & Token::FLAG_KEYWORD_RESERVED)) {
                break;
            }

            if ($token->type === Token::TYPE_KEYWORD && $token->keyword === 'OFFSET') {
                if ($offset) {
                    $parser->error('An offset was expected.', $token);
                }
                $offset = true;
                continue;
            }

            if (($token->type === Token::TYPE_OPERATOR) && ($token->value === ',')) {
                $ret->offset = $ret->rowCount;
                $ret->rowCount = 0;
                continue;
            }

            if (($token->type !== Token::TYPE_NUMBER)) {
                break;
            }

            if ($offset) {
                $ret->offset = $token->value;
                $offset = false;
            } else {
                $ret->rowCount = $token->value;
            }
        }

        if ($offset) {
            $parser->error(
                'An offset was expected.',
                $list->tokens[$list->idx - 1]
            );
        }

        --$list->idx;

        return $ret;
    }

    public static function build($component, array $options = array())
    {
        return $component->offset . ', ' . $component->rowCount;
    }
}
