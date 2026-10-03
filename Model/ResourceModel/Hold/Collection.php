<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\Hold;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'hold_id';

    protected $_eventPrefix = 'panth_pos_hold_collection';

    protected $_eventObject = 'hold_collection';

    protected function _construct(): void
    {
        $this->_init(
            \Panth\MagePos\Model\Hold::class,
            \Panth\MagePos\Model\ResourceModel\Hold::class
        );
    }

    public function addRegisterFilter(int $registerId): self
    {
        $this->addFieldToFilter('register_id', $registerId);
        return $this;
    }
}
