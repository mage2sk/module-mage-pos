<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\CashMovement;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\MagePos\Model\CashMovement;
use Panth\MagePos\Model\ResourceModel\CashMovement as CashMovementResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'movement_id';

    protected $_eventPrefix = 'panth_pos_cash_movement_collection';

    protected function _construct(): void
    {
        $this->_init(CashMovement::class, CashMovementResource::class);
    }
}
