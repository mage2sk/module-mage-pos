<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\PosOrderPayment;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'payment_id';

    protected $_eventPrefix = 'panth_pos_order_payment_collection';

    protected $_eventObject = 'pos_order_payment_collection';

    protected function _construct(): void
    {
        $this->_init(
            \Panth\MagePos\Model\PosOrderPayment::class,
            \Panth\MagePos\Model\ResourceModel\PosOrderPayment::class
        );
    }
}
