<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Form\DataProvider;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Panth\MagePos\Api\Data\PaymentMethodInterface;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory;

class MethodFormDataProvider extends AbstractDataProvider
{
    use PersistedFormDataTrait;

    public const PERSIST_KEY = 'panth_pos_method';

    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly RequestInterface $request,
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
        $id = (int) $this->request->getParam($this->requestFieldName);

        if ($id > 0) {
            $this->collection->addFieldToFilter(PaymentMethodInterface::METHOD_ID, $id);
            foreach ($this->collection->getItems() as $item) {
                $this->loadedData[$item->getId()] = $item->getData();
            }
        }

        if ($this->loadedData === []) {
            $this->loadedData[''] = [
                PaymentMethodInterface::TYPE => PaymentMethodInterface::TYPE_OFFLINE,
                PaymentMethodInterface::IS_ACTIVE => '1',
                PaymentMethodInterface::SORT_ORDER => '0',
                PaymentMethodInterface::REQUIRES_REFERENCE => '0',
                PaymentMethodInterface::OPEN_DRAWER => '0',
            ];
        }

        $this->mergePersistedData(self::PERSIST_KEY);

        return $this->loadedData;
    }
}
