<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Hold extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_pos_hold', 'hold_id');
    }
}
