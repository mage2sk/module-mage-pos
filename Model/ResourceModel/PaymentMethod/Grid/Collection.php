<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\PaymentMethod\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Panth\MagePos\Model\ResourceModel\PaymentMethod as PaymentMethodResource;
use Psr\Log\LoggerInterface;

class Collection extends SearchResult
{
    protected $_idFieldName = 'method_id';

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'panth_pos_payment_method',
        $resourceModel = PaymentMethodResource::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    protected function _initSelect()
    {
        parent::_initSelect();
        $this->addFilterToMap('method_id', 'main_table.method_id');
        return $this;
    }

    protected function _afterLoad()
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            if ($item->getData('method_id')) {
                $item->setId($item->getData('method_id'));
            }
        }
        return $this;
    }
}
