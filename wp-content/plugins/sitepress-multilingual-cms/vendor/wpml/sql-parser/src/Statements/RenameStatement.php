<?php


namespace PhpMyAdmin\SqlParser\Statements;

use PhpMyAdmin\SqlParser\Components\RenameOperation;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * `RENAME` statement.
 *
 * RENAME TABLE tbl_name TO new_tbl_name
 *  [, tbl_name2 TO new_tbl_name2] ...
 *
 * @category   Statements
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */
class RenameStatement extends Statement
{
    public $renames;

    public function before(Parser $parser, TokensList $list, Token $token)
    {
        if (($token->type === Token::TYPE_KEYWORD) && ($token->keyword === 'RENAME')) {
            $list->getNextOfTypeAndValue(Token::TYPE_KEYWORD, 'TABLE');
        }
    }

    public function build()
    {
        return 'RENAME TABLE ' . RenameOperation::build($this->renames);
    }
}
