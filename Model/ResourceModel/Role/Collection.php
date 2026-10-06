<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\Role;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\MagePos\Model\ResourceModel\Role as RoleResource;
use Panth\MagePos\Model\Role;

class Collection extends AbstractCollection
{
    protected $_idFieldName = RoleResource::ID_FIELD_NAME;

    protected $_eventPrefix = 'panth_pos_role_collection';

    protected $_eventObject = 'pos_role_collection';

    protected function _construct(): void
    {
        $this->_init(Role::class, RoleResource::class);
    }
}
