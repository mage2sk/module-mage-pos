<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Auth;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\Data\PaymentMethodInterface;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\PaymentMethodRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\PosSessionService;

class State extends AbstractPosController implements HttpGetActionInterface
{
    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly PosSessionService $posSessionService,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly CurrencyInterface $localeCurrency
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
    }

    public function execute(): Json
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }

        $user = $this->authService->getCurrentUser();
        $authenticated = $user !== null;

        $session = null;
        if ($authenticated) {
            try {
                $session = $this->posSessionService->current();
            } catch (\Exception $e) {
                $session = null;
            }
        }

        return $this->jsonSuccess([
            'authenticated' => $authenticated,

            'locked' => $this->authService->isLocked(),
            'user' => $authenticated ? $this->authService->buildUserPayload($user) : null,
            'session' => $session,
            'registers' => $this->getRegisters(),
            'payment_methods' => $this->getPaymentMethods(),
            'config' => $this->getConfigBlock(),
        ]);
    }

    private function getRegisters(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(RegisterInterface::STATUS, 1)
            ->create();

        $registers = [];
        foreach ($this->registerRepository->getList($searchCriteria)->getItems() as $register) {
            if (!$register instanceof RegisterInterface) {
                continue;
            }
            $registers[] = [
                'register_id' => (int)$register->getRegisterId(),
                'name' => $register->getName(),
                'code' => $register->getCode(),
                'store_id' => (int)$register->getStoreId(),
            ];
        }

        return $registers;
    }

    private function getPaymentMethods(): array
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField(PaymentMethodInterface::SORT_ORDER)
            ->setDirection(SortOrder::SORT_ASC)
            ->create();
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(PaymentMethodInterface::IS_ACTIVE, 1)
            ->addSortOrder($sortOrder)
            ->create();

        $methods = [];
        foreach ($this->paymentMethodRepository->getList($searchCriteria)->getItems() as $method) {
            if (!$method instanceof PaymentMethodInterface) {
                continue;
            }
            $methods[] = [
                'code' => $method->getCode(),
                'title' => $method->getTitle(),
                'type' => $method->getType(),
                'icon' => $method->getIcon(),
                'requires_reference' => (bool)$method->getRequiresReference(),
                'instructions' => $method->getInstructions(),
                'open_drawer' => (bool)$method->getOpenDrawer(),
                'sort_order' => (int)$method->getSortOrder(),
            ];
        }

        return $methods;
    }

    private function getConfigBlock(): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();

        $currencyCode = '';
        $storeName = (string)$store->getName();
        if ($store instanceof Store) {
            $currencyCode = (string)$store->getCurrentCurrency()->getCode();
            $storeName = (string)$store->getFrontendName();
        }

        $currencySymbol = $currencyCode;
        if ($currencyCode !== '') {
            try {
                $symbol = $this->localeCurrency->getCurrency($currencyCode)->getSymbol();
                if (is_string($symbol) && $symbol !== '') {
                    $currencySymbol = $symbol;
                }
            } catch (\Exception $e) {
                $currencySymbol = $currencyCode;
            }
        }

        return [
            'currency_symbol' => $currencySymbol,
            'idle_lock_minutes' => $this->config->getIdleLockMinutes($storeId),
            'offline_enabled' => $this->config->isOfflineModeEnabled($storeId),
            'store_name' => $storeName,
        ];
    }
}
