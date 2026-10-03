<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\Source;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Module\Manager as ModuleManager;

class InventorySource implements OptionSourceInterface
{
    private const MSI_MODULE = 'Magento_InventoryApi';
    private const SOURCE_TABLE = 'inventory_source';

    private ?array $options = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ModuleManager $moduleManager
    ) {
    }

    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $this->options = [['value' => '', 'label' => (string) __('-- Magento Default Stock --')]];

        if (!$this->moduleManager->isEnabled(self::MSI_MODULE)) {
            return $this->options;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::SOURCE_TABLE);
        if (!$connection->isTableExists($table)) {
            return $this->options;
        }

        $select = $connection->select()
            ->from($table, ['source_code', 'name', 'enabled'])
            ->order('name ASC');

        foreach ($connection->fetchAll($select) as $row) {
            $code = (string) ($row['source_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $label = $name !== '' ? $name : $code;
            if (!(int) ($row['enabled'] ?? 1)) {
                $label .= ' ' . (string) __('(disabled)');
            }
            $this->options[] = ['value' => $code, 'label' => $label . ' [' . $code . ']'];
        }

        return $this->options;
    }
}
