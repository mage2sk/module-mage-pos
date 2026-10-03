<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Form\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\MagePos\Api\Data\RoleInterface;
use Panth\MagePos\Model\ResourceModel\Role\CollectionFactory;

class RoleFormDataProvider extends AbstractDataProvider
{
    private const BOOLEAN_PERMISSION_KEYS = [
        'can_price_override',
        'can_refund',
        'can_open_close',
        'can_cash_inout',
        'can_custom_product',
        'can_edit_layout',
        'can_view_reports',
    ];

    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];
        foreach ($this->collection->getItems() as $item) {
            $itemData = $item->getData();
            $this->explodePermissions($itemData);
            $this->loadedData[$item->getId()] = $itemData;
        }

        if (empty($this->loadedData)) {
            $defaults = ['max_discount_percent' => '0'];
            foreach (self::BOOLEAN_PERMISSION_KEYS as $key) {
                $defaults[$key] = '0';
            }
            $this->loadedData[''] = $defaults;
        }

        return $this->loadedData;
    }

    private function explodePermissions(array &$data): void
    {
        $raw = (string) ($data[RoleInterface::PERMISSIONS] ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $permissions = is_array($decoded) ? $decoded : [];

        $data['max_discount_percent'] = (string) (int) ($permissions['max_discount_percent'] ?? 0);
        foreach (self::BOOLEAN_PERMISSION_KEYS as $key) {
            $data[$key] = !empty($permissions[$key]) ? '1' : '0';
        }

        unset($data[RoleInterface::PERMISSIONS]);
    }
}
