<?php
declare(strict_types=1);

namespace Panth\MagePos\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class BackfillReceiptToken implements DataPatchInterface
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('panth_pos_order');

        do {
            $select = $connection->select()
                ->from($table, ['pos_order_id'])
                ->where('receipt_token IS NULL OR receipt_token = ?', '')
                ->limit(self::BATCH_SIZE);

            $ids = $connection->fetchCol($select);
            foreach ($ids as $posOrderId) {
                $connection->update(
                    $table,
                    ['receipt_token' => bin2hex(random_bytes(16))],
                    ['pos_order_id = ?' => (int) $posOrderId]
                );
            }
        } while (count($ids) === self::BATCH_SIZE);

        return $this;
    }
}
