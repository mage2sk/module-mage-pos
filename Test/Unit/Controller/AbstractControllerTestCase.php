<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class AbstractControllerTestCase extends TestCase
{
    use PosTestHelperTrait;

    protected const SESSION_EXPIRED = 'Your session has expired. Please sign in again.';
    protected const TERMINAL_LOCKED = 'The terminal is locked. Enter your PIN to continue.';

    protected array $params = [];
    protected ?array $body = null;
    protected bool $formKeyValid = true;
    protected bool $enabled = true;
    protected ?array $result = null;
    protected ?int $httpCode = null;
    protected HttpRequest&MockObject $request;
    protected JsonFactory&MockObject $jsonFactory;
    protected FormKeyValidator&MockObject $formKeyValidator;
    protected AuthService&MockObject $authService;
    protected Config&MockObject $config;

    protected function setUp(): void
    {
        $this->params = [];
        $this->body = null;
        $this->formKeyValid = true;
        $this->enabled = true;
        $this->result = null;
        $this->httpCode = null;
        $this->criteriaFilters = [];

        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getParam')->willReturnCallback(
            fn ($key, $default = null) => $this->params[$key] ?? $default
        );
        $this->request->method('getParams')->willReturnCallback(fn () => $this->params);
        $this->request->method('setParams')->willReturnCallback(function (array $params) {
            $this->params = $params;
            return $this->request;
        });
        $this->request->method('getContent')->willReturnCallback(
            fn () => $this->body === null ? '' : (string) json_encode($this->body)
        );
        $this->request->method('getPostValue')->willReturn([]);

        $this->formKeyValidator = $this->createMock(FormKeyValidator::class);
        $this->formKeyValidator->method('validate')->willReturnCallback(fn () => $this->formKeyValid);

        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->jsonFactory->method('create')->willReturnCallback(fn () => $this->makeJsonResult());

        $this->authService = $this->createMock(AuthService::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
    }

    protected function makeJsonResult(): Json
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->result = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($json) {
            $this->httpCode = $code;
            return $json;
        });

        return $json;
    }

    protected function baseArgs(): array
    {
        return [$this->jsonFactory, $this->formKeyValidator, $this->authService, $this->request, $this->config];
    }

    protected function signIn(int $userId = 9): PosUserInterface
    {
        $user = $this->createStub(PosUserInterface::class);
        $user->method('getUserId')->willReturn($userId);
        $user->method('getUsername')->willReturn('cashier');
        $this->authService->method('requireUser')->willReturn($user);
        $this->authService->method('getCurrentUser')->willReturn($user);

        return $user;
    }

    protected function signOut(): void
    {
        $this->authService->method('requireUser')
            ->willThrowException(new AuthorizationException(__('unauthorized')));
        $this->authService->method('getCurrentUser')->willReturn(null);
    }

    protected function denyPermission(string $permission): void
    {
        $this->authService->method('requirePermission')->willReturnCallback(
            static function (string $key) use ($permission) {
                if ($key === $permission) {
                    throw new AuthorizationException(__('You do not have permission to perform this action.'));
                }
            }
        );
    }

    protected function assertSuccess(mixed $data = null): void
    {
        $this->assertNotNull($this->result, 'No JSON result was produced');
        $this->assertTrue($this->result['success'], (string) ($this->result['message'] ?? ''));
        if (func_num_args() > 0) {
            $this->assertSame($data, $this->result['data']);
        }
    }

    protected function assertError(?string $message = null, ?string $code = null, ?int $httpCode = null): void
    {
        $this->assertNotNull($this->result, 'No JSON result was produced');
        $this->assertFalse($this->result['success']);
        $this->assertNull($this->result['data']);
        if ($message !== null) {
            $this->assertSame($message, $this->result['message']);
        }
        if ($code !== null) {
            $this->assertSame($code, $this->result['code'] ?? null);
        }
        $this->assertSame($httpCode, $this->httpCode);
    }

    protected function localized(string $message): LocalizedException
    {
        return new LocalizedException(__($message));
    }
}
