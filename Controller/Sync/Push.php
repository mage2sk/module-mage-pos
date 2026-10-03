<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Sync;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\SyncService;

class Push extends AbstractPosController implements HttpPostActionInterface
{
    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly SyncService $syncService
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
    }

    public function execute(): Json
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        if ($invalid = $this->checkFormKey()) {
            return $invalid;
        }
        if ($unauthorized = $this->checkAuthenticated()) {
            return $unauthorized;
        }

        $orders = $this->normalizeOrders($this->getRequestValue('orders'));
        if ($orders === []) {
            return $this->jsonSuccess(['results' => (object)[]], (string)__('Nothing to sync.'));
        }

        try {
            $results = $this->syncService->pushOrders($orders);
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        }

        return $this->jsonSuccess(['results' => (object)$results]);
    }

    private function normalizeOrders(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? array_values($value) : [];
    }
}
