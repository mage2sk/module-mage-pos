<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Cart;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CartService;

class Add extends AbstractPosController implements HttpPostActionInterface
{
    private readonly AuthService $auth;

    private readonly Validator $formKey;

    private readonly RequestInterface $httpRequest;

    public function __construct(
        JsonFactory $jsonFactory,
        Validator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly CartService $cartService
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
        $this->auth = $authService;
        $this->formKey = $formKeyValidator;
        $this->httpRequest = $request;
    }

    public function execute(): ResultInterface
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        if ($invalid = $this->checkFormKey()) {
            return $invalid;
        }
        try {
            $this->auth->requireUser();
        } catch (LocalizedException $e) {
            return $this->jsonError('unauthorized', 'unauthorized');
        }

        $quoteId = (int)$this->getRequestValue('quote_id');
        if ($quoteId <= 0) {
            return $this->jsonError((string)__('quote_id is required.'), 'bad_request');
        }

        $productIdParam = $this->getRequestValue('product_id');
        $skuParam = $this->getRequestValue('sku');
        if ($productIdParam !== null && $productIdParam !== '' && (int)$productIdParam > 0) {
            $productIdOrSku = (int)$productIdParam;
        } elseif (is_string($skuParam) && trim($skuParam) !== '') {
            $productIdOrSku = trim($skuParam);
        } else {
            return $this->jsonError((string)__('product_id or sku is required.'), 'bad_request');
        }

        $qtyParam = $this->getRequestValue('qty');
        $qty = ($qtyParam === null || $qtyParam === '') ? 1.0 : (float)$qtyParam;

        $optionsParam = $this->getRequestValue('options');
        if (is_string($optionsParam) && $optionsParam !== '') {
            $decoded = json_decode($optionsParam, true);
            $optionsParam = is_array($decoded) ? $decoded : null;
        }
        $options = is_array($optionsParam) ? $optionsParam : [];

        try {
            return $this->jsonSuccess($this->cartService->addProduct($quoteId, $productIdOrSku, $qty, $options));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Unable to add the product to the cart.'));
        }
    }
}
