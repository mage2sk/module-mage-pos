<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PosOrder extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_pos_order', 'pos_order_id');
    }

    public function getIdByClientUuid(string $clientUuid): ?int
    {
        if ($clientUuid === '') {
            return null;
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'pos_order_id')
            ->where('client_uuid = ?', $clientUuid)
            ->limit(1);
        $id = $connection->fetchOne($select);
        return $id ? (int) $id : null;
    }

    public function getIdByOrderId(int $orderId): ?int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'pos_order_id')
            ->where('order_id = ?', $orderId)
            ->limit(1);
        $id = $connection->fetchOne($select);
        return $id ? (int) $id : null;
    }
}
