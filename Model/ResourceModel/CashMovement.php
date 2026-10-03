<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CashMovement extends AbstractDb
{
    public const TABLE_NAME = 'panth_pos_cash_movement';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'movement_id');
    }
}
