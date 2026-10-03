<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\PaymentMethod;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'method_id';

    protected $_eventPrefix = 'panth_pos_payment_method_collection';

    protected $_eventObject = 'payment_method_collection';

    protected function _construct(): void
    {
        $this->_init(
            \Panth\MagePos\Model\PaymentMethod::class,
            \Panth\MagePos\Model\ResourceModel\PaymentMethod::class
        );
    }

    public function addActiveFilter(): self
    {
        $this->addFieldToFilter('is_active', 1);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        return $this;
    }
}
