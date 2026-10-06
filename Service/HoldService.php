<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManagerInterface;
use Panth\MagePos\Api\Data\HoldInterface;
use Panth\MagePos\Api\Data\HoldInterfaceFactory;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\HoldRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;

class HoldService
{
    public function __construct(
        private readonly HoldRepositoryInterface $holdRepository,
        private readonly HoldInterfaceFactory $holdFactory,
        private readonly SessionRepositoryInterface $sessionRepository,
        private readonly SessionManagerInterface $sessionManager,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly AuthService $authService
    ) {
    }

    public function save(string $label, string $cartJson, ?int $customerId = null): array
    {
        $user = $this->authService->requireUser();

        $cartJson = trim($cartJson);
        if ($cartJson === '') {
            throw new LocalizedException(__('Cannot hold an empty cart.'));
        }
        $decoded = json_decode($cartJson, true);
        if (!is_array($decoded)) {
            throw new LocalizedException(__('Invalid cart data.'));
        }

        if (array_key_exists('items', $decoded)
            && (!is_array($decoded['items']) || count($decoded['items']) === 0)
        ) {
            throw new LocalizedException(__('Cannot hold an empty cart - add items first.'));
        }

        $label = trim($label);
        if ($label === '') {
            $label = (string)__('Hold %1', gmdate('Y-m-d H:i'));
        }

        $hold = $this->holdFactory->create();
        $hold->setRegisterId($this->getCurrentRegisterId((int)$user->getUserId()));
        $hold->setUserId((int)$user->getUserId());
        $hold->setLabel($label);
        $hold->setCustomerId($customerId !== null && $customerId > 0 ? $customerId : null);
        $hold->setCartJson($cartJson);

        return $this->toPayload($this->holdRepository->save($hold));
    }

    public function all(): array
    {
        $user = $this->authService->requireUser();

        $registerId = $this->getCurrentRegisterId((int)$user->getUserId());
        if ($registerId > 0) {
            $this->searchCriteriaBuilder->addFilter(
                HoldInterface::REGISTER_ID,
                [0, $registerId],
                'in'
            );
        }
        $sortOrder = $this->sortOrderBuilder
            ->setField(HoldInterface::HOLD_ID)
            ->setDirection(SortOrder::SORT_DESC)
            ->create();
        $searchCriteria = $this->searchCriteriaBuilder
            ->setSortOrders([$sortOrder])
            ->create();

        $rows = [];
        foreach ($this->holdRepository->getList($searchCriteria)->getItems() as $hold) {
            if ($hold instanceof HoldInterface) {
                $rows[] = $this->toPayload($hold);
            }
        }

        return $rows;
    }

    public function restore(int $holdId): array
    {
        $user = $this->authService->requireUser();

        return $this->toPayload($this->loadScoped($holdId, (int)$user->getUserId()));
    }

    public function delete(int $holdId): void
    {
        $user = $this->authService->requireUser();
        $this->holdRepository->delete($this->loadScoped($holdId, (int)$user->getUserId()));
    }

    private function loadScoped(int $holdId, int $userId): HoldInterface
    {
        $hold = $this->holdRepository->getById($holdId);

        $registerId = $this->getCurrentRegisterId($userId);
        if ($registerId > 0) {
            $holdRegisterId = (int)$hold->getRegisterId();
            if ($holdRegisterId !== 0 && $holdRegisterId !== $registerId) {
                throw NoSuchEntityException::singleField('hold_id', $holdId);
            }
        }

        return $hold;
    }

    private function getCurrentRegisterId(int $userId): int
    {
        $sessionId = (int)$this->sessionManager->getData(AuthService::SESSION_KEY_SESSION_ID);
        if ($sessionId > 0) {
            try {
                $session = $this->sessionRepository->getById($sessionId);
                if ($session->getStatus() === SessionInterface::STATUS_OPEN) {
                    return (int)$session->getRegisterId();
                }
            } catch (NoSuchEntityException $e) {
            }
        }

        return $this->findOpenRegisterIdByUser($userId);
    }

    private function findOpenRegisterIdByUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $sortOrder = $this->sortOrderBuilder
            ->setField(SessionInterface::SESSION_ID)
            ->setDirection(SortOrder::SORT_DESC)
            ->create();
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(SessionInterface::USER_ID, $userId)
            ->addFilter(SessionInterface::STATUS, SessionInterface::STATUS_OPEN)
            ->setSortOrders([$sortOrder])
            ->setPageSize(1)
            ->create();

        foreach ($this->sessionRepository->getList($searchCriteria)->getItems() as $session) {
            if ($session instanceof SessionInterface) {
                return (int)$session->getRegisterId();
            }
        }

        return 0;
    }

    private function toPayload(HoldInterface $hold): array
    {
        $cart = json_decode($hold->getCartJson(), true);

        return [
            'hold_id' => (int)$hold->getHoldId(),
            'register_id' => $hold->getRegisterId(),
            'user_id' => $hold->getUserId(),
            'label' => $hold->getLabel(),
            'customer_id' => $hold->getCustomerId(),
            'cart_json' => $hold->getCartJson(),
            'cart' => is_array($cart) ? $cart : null,
            'created_at' => $hold->getCreatedAt(),
        ];
    }
}
