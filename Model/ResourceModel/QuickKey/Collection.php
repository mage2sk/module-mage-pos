<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\QuickKey;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'quick_key_id';

    protected $_eventPrefix = 'panth_pos_quick_key_collection';

    protected $_eventObject = 'quick_key_collection';

    protected function _construct(): void
    {
        $this->_init(
            \Panth\MagePos\Model\QuickKey::class,
            \Panth\MagePos\Model\ResourceModel\QuickKey::class
        );
    }

    public function addRegisterFilter(?int $registerId): self
    {
        if ($registerId === null) {
            $this->addFieldToFilter('register_id', ['null' => true]);
        } else {
            $this->addFieldToFilter(
                'register_id',
                [['eq' => $registerId], ['null' => true]]
            );
        }
        $this->setOrder('page', self::SORT_ORDER_ASC);
        $this->setOrder('position', self::SORT_ORDER_ASC);
        return $this;
    }
}
