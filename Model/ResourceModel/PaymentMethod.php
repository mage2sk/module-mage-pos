<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PaymentMethod extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_pos_payment_method', 'method_id');
    }

    public function getIdByCode(string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'method_id')
            ->where('code = ?', $code)
            ->limit(1);
        $id = $connection->fetchOne($select);
        return $id ? (int) $id : null;
    }
}
