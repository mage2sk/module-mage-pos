<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\UserPreference;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'preference_id';

    protected $_eventPrefix = 'panth_pos_user_preference_collection';

    protected $_eventObject = 'user_preference_collection';

    protected function _construct(): void
    {
        $this->_init(
            \Panth\MagePos\Model\UserPreference::class,
            \Panth\MagePos\Model\ResourceModel\UserPreference::class
        );
    }
}
