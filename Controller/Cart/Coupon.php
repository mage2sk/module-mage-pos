<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Cart;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\DiscountService;

class Coupon extends AbstractPosController implements HttpPostActionInterface
{
    private readonly JsonFactory $posJsonFactory;
    private readonly FormKeyValidator $posFormKeyValidator;
    private readonly AuthService $posAuthService;
    private readonly RequestInterface $posRequest;

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly FormKey $formKey,
        private readonly DiscountService $discountService
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
        $this->posJsonFactory = $jsonFactory;
        $this->posFormKeyValidator = $formKeyValidator;
        $this->posAuthService = $authService;
        $this->posRequest = $request;
    }

    public function execute(): Json
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        if (!$this->isFormKeyValid()) {
            return $this->errorResult((string)__('Invalid form key.'), 'invalid_form_key', 403);
        }
        try {
            $this->posAuthService->requireUser();
        } catch (\Exception $e) {
            return $this->errorResult('unauthorized', 'unauthorized');
        }

        $params = $this->getPostData();
        $quoteId = (int)($params['quote_id'] ?? 0);
        if ($quoteId <= 0) {
            return $this->errorResult((string)__('quote_id is required.'));
        }

        $remove = filter_var($params['remove'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            if ($remove) {
                $cart = $this->discountService->removeCoupon($quoteId);
            } else {
                $code = trim((string)($params['code'] ?? ''));
                if ($code === '') {
                    return $this->errorResult((string)__('A coupon code is required.'));
                }
                $cart = $this->discountService->applyCoupon($quoteId, $code);
            }
        } catch (LocalizedException $e) {
            return $this->errorResult($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResult((string)__('Unable to update the coupon. Please try again.'));
        }

        return $this->successResult($cart);
    }

    private function isFormKeyValid(): bool
    {
        if ($this->posFormKeyValidator->validate($this->posRequest)) {
            return true;
        }

        $data = $this->getPostData();
        $bodyKey = isset($data['form_key']) ? (string)$data['form_key'] : '';

        return $bodyKey !== '' && hash_equals((string)$this->formKey->getFormKey(), $bodyKey);
    }

    protected function getPostData(): array
    {
        $params = (array)$this->posRequest->getParams();
        $content = (string)$this->posRequest->getContent();
        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $params = array_merge($decoded, $params);
            }
        }

        return $params;
    }

    private function successResult(mixed $data): Json
    {
        return $this->posJsonFactory->create()->setData([
            'success' => true,
            'data' => $data,
            'message' => null,
        ]);
    }

    private function errorResult(string $message, ?string $code = null, ?int $httpStatus = null): Json
    {
        $result = $this->posJsonFactory->create();
        if ($httpStatus !== null) {
            $result->setHttpResponseCode($httpStatus);
        }
        $payload = [
            'success' => false,
            'data' => null,
            'message' => $message,
        ];
        if ($code !== null) {
            $payload['code'] = $code;
        }

        return $result->setData($payload);
    }
}
