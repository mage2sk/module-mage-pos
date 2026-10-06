<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Hold;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\HoldService;

class Save extends AbstractPosController implements HttpPostActionInterface
{
    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly HoldService $holdService
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

        $label = (string)$this->getRequestValue('label', '');
        $cartJson = $this->getRequestValue('cart_json', '');
        if (is_array($cartJson)) {
            $cartJson = json_encode($cartJson);
        }
        $customerId = $this->getRequestValue('customer_id');
        $customerId = is_numeric($customerId) && (int)$customerId > 0 ? (int)$customerId : null;

        try {
            $hold = $this->holdService->save($label, (string)$cartJson, $customerId);
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        }

        return $this->jsonSuccess($hold, (string)__('Cart held.'));
    }
}
