<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\PosOrder;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'pos_order_id';

    protected $_eventPrefix = 'panth_pos_order_collection';

    protected $_eventObject = 'pos_order_collection';

    protected function _construct(): void
    {
        $this->_init(
            \Panth\MagePos\Model\PosOrder::class,
            \Panth\MagePos\Model\ResourceModel\PosOrder::class
        );
    }
}
