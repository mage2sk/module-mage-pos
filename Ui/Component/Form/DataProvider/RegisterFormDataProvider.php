<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Form\DataProvider;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\MagePos\Model\ResourceModel\Register\CollectionFactory;

class RegisterFormDataProvider extends AbstractDataProvider
{
    use PersistedFormDataTrait;

    public const PERSIST_KEY = 'panth_pos_register';

    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
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
        foreach ($this->collection->getItems() as $register) {
            $this->loadedData[$register->getId()] = $register->getData();
        }

        if (empty($this->loadedData)) {
            $this->loadedData[''] = [
                'status' => '1',
                'store_id' => '1',
            ];
        }

        $this->mergePersistedData(self::PERSIST_KEY);

        return $this->loadedData;
    }
}
