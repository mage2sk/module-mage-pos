<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;

abstract class AbstractPosController implements CsrfAwareActionInterface
{
    public const CODE_UNAUTHORIZED = 'unauthorized';
    public const CODE_INVALID_FORM_KEY = 'invalid_form_key';
    public const CODE_DISABLED = 'disabled';

    public function __construct(
        protected readonly JsonFactory $jsonFactory,
        protected readonly FormKeyValidator $formKeyValidator,
        protected readonly AuthService $authService,
        protected readonly RequestInterface $request,
        protected readonly Config $config
    ) {
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    protected function jsonSuccess(mixed $data = null, ?string $message = null): Json
    {
        return $this->jsonFactory->create()->setData([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ]);
    }

    protected function jsonError(string $message, ?string $code = null, int $httpStatus = 200): Json
    {
        $payload = [
            'success' => false,
            'data' => null,
            'message' => $message,
        ];
        if ($code !== null) {
            $payload['code'] = $code;
        }
        if ($code === self::CODE_UNAUTHORIZED) {
            $locked = $this->isSessionLocked();
            $payload['locked'] = $locked;
            $payload['message'] = $locked
                ? (string)__('The terminal is locked. Enter your PIN to continue.')
                : (string)__('Your session has expired. Please sign in again.');
        }

        $result = $this->jsonFactory->create()->setData($payload);
        if ($httpStatus !== 200) {
            $result->setHttpResponseCode($httpStatus);
        }

        return $result;
    }

    protected function validateFormKey(): bool
    {
        if ($this->request instanceof HttpRequest && $this->request->getParam('form_key') === null) {
            $body = $this->getPostData();
            if (isset($body['form_key']) && is_string($body['form_key'])) {
                $this->request->setParams(
                    array_merge($this->request->getParams(), ['form_key' => $body['form_key']])
                );
            }
        }

        return $this->formKeyValidator->validate($this->request);
    }

    protected function checkFormKey(): ?Json
    {
        if ($this->validateFormKey()) {
            return null;
        }

        return $this->jsonError((string)__('Invalid form key.'), self::CODE_INVALID_FORM_KEY, 403);
    }

    protected function checkEnabled(): ?Json
    {
        if ($this->config->isEnabled()) {
            return null;
        }

        return $this->jsonError((string)__('POS is disabled.'), self::CODE_DISABLED);
    }

    protected function checkAuthenticated(): ?Json
    {
        if ($this->authService->getCurrentUser() === null) {
            return $this->jsonError('unauthorized', self::CODE_UNAUTHORIZED);
        }
        if ($this->authService->isLocked() && !$this->isReachableWhileLocked()) {
            return $this->jsonError('unauthorized', self::CODE_UNAUTHORIZED);
        }

        return null;
    }

    private function isSessionLocked(): bool
    {
        try {
            return $this->authService->isLocked();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isReachableWhileLocked(): bool
    {
        return str_starts_with(static::class, 'Panth\\MagePos\\Controller\\Auth\\');
    }

    protected function getPostData(): array
    {
        $data = [];
        if ($this->request instanceof HttpRequest) {
            $content = (string)$this->request->getContent();
            if ($content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
            $post = $this->request->getPostValue();
            if (is_array($post) && $post !== []) {
                $data = array_merge($data, $post);
            }
        }

        return $data;
    }

    protected function getRequestValue(string $key, mixed $default = null): mixed
    {
        $body = $this->getPostData();
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }

        return $this->request->getParam($key, $default);
    }
}
