<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Session extends AbstractDb
{
    public const TABLE_NAME = 'panth_pos_session';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'session_id');
    }
}
