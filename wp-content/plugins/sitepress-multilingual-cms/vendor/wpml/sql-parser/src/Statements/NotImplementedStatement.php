<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Not implemented (yet) statements.
 *
 * The `after` function makes the parser jump straight to the first delimiter.
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class NotImplementedStatement extends Statement
{
    public $unknown = array();

    public function build()
    {
        $query = parent::build() . ' ';

        foreach ($this->unknown as $token) {
            $query .= $token->token;
        }

        return $query;
    }

    public function parse(Parser $parser, TokensList $list)
    {
        for (; $list->idx < $list->count; ++$list->idx) {
            if ($list->tokens[$list->idx]->type === Token::TYPE_DELIMITER) {
                break;
            }
            $this->unknown[] = $list->tokens[$list->idx];
        }
    }
}
