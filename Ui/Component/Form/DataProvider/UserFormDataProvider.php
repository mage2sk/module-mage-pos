<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Form\DataProvider;

use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Model\ResourceModel\PosUser\CollectionFactory;

class UserFormDataProvider extends AbstractDataProvider
{
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

            unset($itemData[PosUserInterface::PASSWORD_HASH], $itemData[PosUserInterface::PIN_HASH]);

            $this->loadedData[$item->getId()] = $itemData;
        }

        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                PosUserInterface::STATUS => '1',
            ];
        }

        return $this->loadedData;
    }
}
