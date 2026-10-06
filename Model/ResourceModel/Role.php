<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Role extends AbstractDb
{
    public const TABLE_NAME = 'panth_pos_role';
    public const ID_FIELD_NAME = 'role_id';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, self::ID_FIELD_NAME);
    }
}
