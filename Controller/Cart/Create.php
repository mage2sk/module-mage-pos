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

class Create extends AbstractPosController implements HttpPostActionInterface
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

        try {
            $quoteId = $this->cartService->create();

            return $this->jsonSuccess($this->cartService->get($quoteId));
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonError((string)__('Unable to create a new cart.'));
        }
    }
}
