<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Cart;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CartService;

class Get extends AbstractPosController implements HttpGetActionInterface
{
    private readonly AuthService $auth;

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
        $this->httpRequest = $request;
    }

    public function execute(): ResultInterface
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        try {
            $this->auth->requireUser();
        } catch (LocalizedException $e) {
            return $this->jsonError('unauthorized', 'unauthorized');
        }

        $quoteId = (int)$this->httpRequest->getParam('quote_id');
        if ($quoteId <= 0) {
            return $this->jsonError((string)__('quote_id is required.'), 'bad_request');
        }

        try {
            return $this->jsonSuccess($this->cartService->get($quoteId));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Unable to load the cart.'));
        }
    }
}
