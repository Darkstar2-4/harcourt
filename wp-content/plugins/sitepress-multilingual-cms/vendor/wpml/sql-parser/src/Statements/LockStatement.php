<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\LockExpression;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `LOCK` statement.
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class LockStatement extends Statement
{
    public $locked = array();

    public $isLock = true;

    public function parse(Parser $parser, TokensList $list)
    {
        if ($list->tokens[$list->idx]->value === 'UNLOCK') {
            $this->isLock = false;
        }
        ++$list->idx;

        $state = 0;

        $prevToken = null;

        for (; $list->idx < $list->count; ++$list->idx) {
            $token = $list->tokens[$list->idx];

            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            if (($token->type === Token::TYPE_WHITESPACE) || ($token->type === Token::TYPE_COMMENT)) {
                continue;
            }

            if ($state === 0) {
                if ($token->type === Token::TYPE_KEYWORD) {
                    if ($token->keyword !== 'TABLES') {
                        $parser->error('Unexpected keyword.', $token);
                        break;
                    }
                    $state = 1;
                    continue;
                } else {
                    $parser->error('Unexpected token.', $token);
                    break;
                }
            } elseif ($state === 1) {
                if (! $this->isLock) {
                    $parser->error('Unexpected token.', $token);
                    break;
                }
                $this->locked[] = LockExpression::parse($parser, $list);
                $state = 2;
            } elseif ($state === 2) {
                if ($token->value === ',') {
                    $state = 1;
                }
            }

            $prevToken = $token;
        }

        if ($state !== 2 && $prevToken != null) {
            $parser->error('Unexpected end of LOCK statement.', $prevToken);
        }
    }

    public function build()
    {
        return trim(($this->isLock ? 'LOCK' : 'UNLOCK')
            . ' TABLES ' . LockExpression::build($this->locked));
    }
}
