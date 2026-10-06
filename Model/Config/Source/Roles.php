<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\MagePos\Api\Data\RoleInterface;
use Panth\MagePos\Model\ResourceModel\Role\CollectionFactory;

class Roles implements OptionSourceInterface
{
    private ?array $options = null;

    public function __construct(
        private readonly CollectionFactory $roleCollectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $this->options = [
            ['value' => '', 'label' => (string) __('-- No Role --')],
        ];

        $collection = $this->roleCollectionFactory->create();
        $collection->setOrder(RoleInterface::NAME, 'ASC');
        foreach ($collection as $role) {
            $this->options[] = [
                'value' => (string) $role->getRoleId(),
                'label' => $role->getName(),
            ];
        }

        return $this->options;
    }
}
