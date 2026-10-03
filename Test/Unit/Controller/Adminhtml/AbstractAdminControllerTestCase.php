<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Adminhtml;

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../PosTestHelperTrait.php';

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class AbstractAdminControllerTestCase extends TestCase
{
    use PosTestHelperTrait;

    protected array $params = [];
    protected array $post = [];
    protected array $messages = [];
    protected ?array $redirect = null;
    protected ?string $redirectUrl = null;
    protected array $titles = [];
    protected ?string $activeMenu = null;
    protected array $allowedResources = [];
    protected Context&MockObject $context;
    protected HttpRequest&MockObject $request;

    protected function setUp(): void
    {
        $this->params = [];
        $this->post = [];
        $this->messages = [];
        $this->redirect = null;
        $this->redirectUrl = null;
        $this->titles = [];
        $this->activeMenu = null;
        $this->allowedResources = [];

        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $this->params[$k] ?? $d);
        $this->request->method('getPostValue')->willReturnCallback(fn () => $this->post);

        $messageManager = $this->createMock(ManagerInterface::class);
        foreach (['addErrorMessage' => 'error', 'addSuccessMessage' => 'success'] as $method => $type) {
            $messageManager->method($method)->willReturnCallback(function ($message) use ($type, $messageManager) {
                $this->messages[] = [$type, (string) $message];
                return $messageManager;
            });
        }
        $messageManager->method('addExceptionMessage')->willReturnCallback(
            function ($exception, $message = null) use ($messageManager) {
                $this->messages[] = ['exception', (string) $message];
                return $messageManager;
            }
        );

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturnCallback(fn () => $this->makeRedirect());

        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            fn (string $resource) => in_array($resource, $this->allowedResources, true)
        );

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getMessageManager')->willReturn($messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $this->context->method('getAuthorization')->willReturn($authorization);
    }

    protected function makeRedirect(): Redirect
    {
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->redirect = [$path, $params];
            return $redirect;
        });
        $redirect->method('setUrl')->willReturnCallback(function ($url) use ($redirect) {
            $this->redirectUrl = $url;
            return $redirect;
        });

        return $redirect;
    }

    protected function makePageFactory(): PageFactory
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($text) {
            $this->titles[] = (string) $text;
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $page->method('setActiveMenu')->willReturnCallback(function ($menu) use ($page) {
            $this->activeMenu = $menu;
            return $page;
        });
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        return $factory;
    }

    protected function assertRedirect(string $path, array $params = []): void
    {
        $this->assertSame([$path, $params], $this->redirect);
    }

    protected function assertMessages(array $expected): void
    {
        $this->assertSame($expected, $this->messages);
    }

    protected function isAllowed(object $controller): bool
    {
        $method = new \ReflectionMethod($controller, '_isAllowed');

        return $method->invoke($controller);
    }
}
