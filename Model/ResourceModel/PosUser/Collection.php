<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\PosUser;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\MagePos\Model\PosUser;
use Panth\MagePos\Model\ResourceModel\PosUser as PosUserResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = PosUserResource::ID_FIELD_NAME;

    protected $_eventPrefix = 'panth_pos_user_collection';

    protected $_eventObject = 'pos_user_collection';

    protected function _construct(): void
    {
        $this->_init(PosUser::class, PosUserResource::class);
    }
}
