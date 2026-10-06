<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\PosUser\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Panth\MagePos\Model\ResourceModel\PosUser as PosUserResource;
use Psr\Log\LoggerInterface;

class Collection extends SearchResult
{
    protected $_idFieldName = PosUserResource::ID_FIELD_NAME;

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        string $mainTable = PosUserResource::TABLE_NAME,
        string $resourceModel = PosUserResource::class,
        ?string $identifierName = PosUserResource::ID_FIELD_NAME,
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
        $this->getSelect()->reset(\Magento\Framework\DB\Select::COLUMNS)->columns([
            'user_id',
            'username',
            'name',
            'email',
            'role_id',
            'status',
            'last_login_at',
            'created_at',
            'updated_at',
        ]);
        $this->addFilterToMap(PosUserResource::ID_FIELD_NAME, 'main_table.' . PosUserResource::ID_FIELD_NAME);

        return $this;
    }

    protected function _afterLoad(): static
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            if ($item->getData(PosUserResource::ID_FIELD_NAME)) {
                $item->setId($item->getData(PosUserResource::ID_FIELD_NAME));
            }
        }

        return $this;
    }
}
