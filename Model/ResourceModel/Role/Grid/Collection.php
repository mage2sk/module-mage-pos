<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\Role\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Panth\MagePos\Model\ResourceModel\Role as RoleResource;
use Psr\Log\LoggerInterface;

class Collection extends SearchResult
{
    protected $_idFieldName = RoleResource::ID_FIELD_NAME;

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        string $mainTable = RoleResource::TABLE_NAME,
        string $resourceModel = RoleResource::class,
        ?string $identifierName = RoleResource::ID_FIELD_NAME,
        ?string $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect(): static
    {
        parent::_initSelect();
        $this->addFilterToMap(RoleResource::ID_FIELD_NAME, 'main_table.' . RoleResource::ID_FIELD_NAME);

        return $this;
    }

    protected function _afterLoad(): static
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            if ($item->getData(RoleResource::ID_FIELD_NAME)) {
                $item->setId($item->getData(RoleResource::ID_FIELD_NAME));
            }
        }

        return $this;
    }
}
