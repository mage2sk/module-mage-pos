<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class UserPreference extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_pos_user_preference', 'preference_id');
    }

    public function getIdByUserId(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'preference_id')
            ->where('user_id = ?', $userId)
            ->limit(1);
        $id = $connection->fetchOne($select);
        return $id ? (int) $id : null;
    }
}
