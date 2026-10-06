<?php
declare(strict_types=1);

namespace Panth\MagePos\Model\ResourceModel\Session\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Panth\MagePos\Model\ResourceModel\Session as SessionResource;
use Psr\Log\LoggerInterface;

class Collection extends SearchResult
{
    protected $_idFieldName = 'session_id';

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        string $mainTable = SessionResource::TABLE_NAME,
        string $resourceModel = SessionResource::class,
        ?string $identifierName = 'session_id',
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

        $this->getSelect()->joinLeft(
            ['register' => $this->getTable('panth_pos_register')],
            'register.register_id = main_table.register_id',
            ['register_name' => 'register.name']
        )->joinLeft(
            ['pos_user' => $this->getTable('panth_pos_user')],
            'pos_user.user_id = main_table.user_id',
            ['cashier_name' => 'pos_user.name']
        );

        $this->addFilterToMap('session_id', 'main_table.session_id');
        $this->addFilterToMap('register_id', 'main_table.register_id');
        $this->addFilterToMap('user_id', 'main_table.user_id');
        $this->addFilterToMap('status', 'main_table.status');
        $this->addFilterToMap('opening_float', 'main_table.opening_float');
        $this->addFilterToMap('expected_cash', 'main_table.expected_cash');
        $this->addFilterToMap('counted_cash', 'main_table.counted_cash');
        $this->addFilterToMap('over_short', 'main_table.over_short');
        $this->addFilterToMap('opened_at', 'main_table.opened_at');
        $this->addFilterToMap('closed_at', 'main_table.closed_at');
        $this->addFilterToMap('created_at', 'main_table.created_at');
        $this->addFilterToMap('updated_at', 'main_table.updated_at');
        $this->addFilterToMap('register_name', 'register.name');
        $this->addFilterToMap('cashier_name', 'pos_user.name');

        return $this;
    }

    protected function _afterLoad(): static
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            if ($item->getData('session_id')) {
                $item->setId($item->getData('session_id'));
            }
        }
        return $this;
    }
}
