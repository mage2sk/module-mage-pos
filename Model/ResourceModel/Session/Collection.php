<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\Session;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\MagePos\Model\ResourceModel\Session as SessionResource;
use Panth\MagePos\Model\Session;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'session_id';

    protected $_eventPrefix = 'panth_pos_session_collection';

    protected function _construct(): void
    {
        $this->_init(Session::class, SessionResource::class);
    }
}
