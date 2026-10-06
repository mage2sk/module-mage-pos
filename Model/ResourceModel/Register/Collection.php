<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\Register;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'register_id';

    protected $_eventPrefix = 'panth_pos_register_collection';

    protected $_eventObject = 'register_collection';

    protected function _construct(): void
    {
        $this->_init(
            \Panth\MagePos\Model\Register::class,
            \Panth\MagePos\Model\ResourceModel\Register::class
        );
    }
}
