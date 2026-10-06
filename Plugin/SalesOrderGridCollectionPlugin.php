<?php
declare(strict_types=1);

namespace Panth\MagePos\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use Psr\Log\LoggerInterface;

class SalesOrderGridCollectionPlugin
{
    private const REQUEST_NAME = 'sales_order_grid_data_source';

    private const POS_ORDER_TABLE = 'panth_pos_order';
    private const REGISTER_TABLE = 'panth_pos_register';
    private const USER_TABLE = 'panth_pos_user';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterGetReport(CollectionFactory $subject, $result, $requestName)
    {
        if ($requestName !== self::REQUEST_NAME || !$result instanceof AbstractDb) {
            return $result;
        }

        try {
            $posOrderTable = $this->resourceConnection->getTableName(self::POS_ORDER_TABLE);
            $registerTable = $this->resourceConnection->getTableName(self::REGISTER_TABLE);
            $userTable = $this->resourceConnection->getTableName(self::USER_TABLE);

            $connection = $result->getConnection();
            if (!$connection->isTableExists($posOrderTable)
                || !$connection->isTableExists($registerTable)
                || !$connection->isTableExists($userTable)
            ) {
                return $result;
            }

            $result->getSelect()
                ->joinLeft(
                    ['ppo' => $posOrderTable],
                    'ppo.order_id = main_table.entity_id',
                    []
                )
                ->joinLeft(
                    ['ppr' => $registerTable],
                    'ppr.register_id = ppo.register_id',
                    ['pos_register' => 'ppr.name']
                )
                ->joinLeft(
                    ['ppu' => $userTable],
                    'ppu.user_id = ppo.pos_user_id',
                    ['pos_cashier' => 'ppu.name']
                );

            $result->addFilterToMap('pos_register', 'ppr.name');
            $result->addFilterToMap('pos_cashier', 'ppu.name');
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Panth_MagePos] Failed to join POS data onto sales_order_grid: ' . $e->getMessage()
            );
        }

        return $result;
    }
}
