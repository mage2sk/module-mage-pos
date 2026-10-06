<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../PosTestHelperTrait.php';

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Session\SessionManager;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\PosOrderPaymentRepositoryInterface;
use Panth\MagePos\Api\PosOrderRepositoryInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Model\CashMovementFactory;
use Panth\MagePos\Model\Payment\ProcessorPool;
use Panth\MagePos\Model\PosOrderFactory;
use Panth\MagePos\Model\PosOrderPaymentFactory;
use Panth\MagePos\Model\ResourceModel\PaymentMethod\CollectionFactory as PaymentMethodCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrder\CollectionFactory as PosOrderCollectionFactory;
use Panth\MagePos\Model\ResourceModel\PosOrderPayment\CollectionFactory as PosOrderPaymentCollectionFactory;
use Panth\MagePos\Model\Session;
use Panth\MagePos\Model\SessionFactory;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CheckoutService;
use Panth\MagePos\Service\QuotePreparer;
use Panth\MagePos\Test\Unit\MagicCallsTrait;
use Panth\MagePos\Test\Unit\PosTestHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class CheckoutServiceEdgeCasesTest extends TestCase
{
    use MagicCallsTrait;
    use PosTestHelperTrait;

    private CartRepositoryInterface&MockObject $quoteRepository;
    private CartManagementInterface&MockObject $cartManagement;
    private SessionRepositoryInterface&MockObject $sessionRepository;
    private SessionFactory&MockObject $sessionFactory;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private Config&MockObject $config;
    private array $sessionData = [];
    private bool $enabled = true;
    private bool $sessionRequired = true;
    private ?int $defaultRegister = null;

    protected function setUp(): void
    {
        $this->sessionData = [];
        $this->enabled = true;
        $this->sessionRequired = true;
        $this->defaultRegister = null;
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
        $this->sessionFactory = $this->createMock(SessionFactory::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
        $this->config->method('isSessionRequired')->willReturnCallback(fn () => $this->sessionRequired);
        $this->config->method('getDefaultRegisterId')->willReturnCallback(fn () => $this->defaultRegister);
    }

    private function makeService(): CheckoutService
    {
        $authService = $this->createStub(AuthService::class);
        $user = $this->createStub(PosUserInterface::class);
        $user->method('getUserId')->willReturn(9);
        $authService->method('requireUser')->willReturn($user);
        $sessionManager = $this->getMockBuilder(SessionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call'])
            ->getMock();
        $sessionManager->method('getData')->willReturnCallback(fn ($k) => $this->sessionData[$k] ?? null);
        $this->stubMagicCalls($sessionManager, [
            'setData' => function ($k, $v) {
                $this->sessionData[$k] = $v;
            },
        ]);

        return new CheckoutService(
            $authService,
            $this->config,
            $this->quoteRepository,
            $this->cartManagement,
            $this->createStub(OrderRepositoryInterface::class),
            $this->createStub(InvoiceService::class),
            $this->createStub(TransactionFactory::class),
            $sessionManager,
            $this->sessionRepository,
            $this->sessionFactory,
            $this->registerRepository,
            $this->createStub(PaymentMethodCollectionFactory::class),
            $this->createStub(PosOrderFactory::class),
            $this->createStub(PosOrderRepositoryInterface::class),
            $this->createStub(PosOrderCollectionFactory::class),
            $this->createStub(PosOrderPaymentFactory::class),
            $this->createStub(PosOrderPaymentRepositoryInterface::class),
            $this->createStub(PosOrderPaymentCollectionFactory::class),
            $this->createStub(CashMovementFactory::class),
            $this->createStub(CashMovementRepositoryInterface::class),
            new ProcessorPool(),
            $this->createStub(QuotePreparer::class),
            $this->createStub(ModuleManager::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    private function useQuote(array $data, int $itemsCount = 1): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getItemsCount'])
            ->getMock();
        $quote->setData($data);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getItemsCount')->willReturn($itemsCount);
        $this->quoteRepository->method('getActive')->with(3)->willReturn($quote);

        return $quote;
    }

    public function testQuoteWithoutPosMarkerIsTreatedAsMissing(): void
    {
        $this->useQuote([]);
        $this->quoteRepository->expects($this->never())->method('save');

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('Cart 3 no longer exists.');
        $this->makeService()->placeOrder(3, []);
    }

    public function testDisabledStoreRejectsCheckout(): void
    {
        $this->enabled = false;
        $this->useQuote(['panth_pos_register_id' => 0]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The POS is disabled for this store.');
        $this->makeService()->placeOrder(3, []);
    }

    public function testEmptyCartRejectsCheckout(): void
    {
        $this->useQuote(['panth_pos_register_id' => 0], 0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The cart is empty.');
        $this->makeService()->placeOrder(3, []);
    }

    public function testRequiredSessionMustBeOpen(): void
    {
        $this->useQuote(['panth_pos_register_id' => 0]);
        $this->sessionData['panth_pos_session_id'] = 4;
        $this->sessionRepository->method('getById')->willReturn(
            $this->makeModel(Session::class, 'session_id', ['session_id' => 4, 'status' => 'closed'])
        );
        $this->sessionRepository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('An open register session is required before placing orders.');
        $this->makeService()->placeOrder(3, []);
    }

    public function testWithoutSessionOrDefaultRegisterCheckoutFails(): void
    {
        $this->sessionRequired = false;
        $this->useQuote(['panth_pos_register_id' => 0]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No register session is open and no default register is configured.');
        $this->makeService()->placeOrder(3, []);
    }

    public function testSessionIsAutoOpenedOnDefaultRegisterAndCartOfOtherRegisterRejected(): void
    {
        $this->sessionRequired = false;
        $this->defaultRegister = 2;
        $this->useQuote(['panth_pos_register_id' => 5]);
        $session = $this->makeModel(Session::class, 'session_id');
        $this->sessionFactory->method('create')->willReturn($session);
        $this->sessionRepository->expects($this->once())->method('save')->with($session)
            ->willReturnCallback(static function (Session $s) {
                $s->setSessionId(40);
                return $s;
            });
        $this->registerRepository->expects($this->never())->method('getById');

        try {
            $this->makeService()->placeOrder(3, []);
            $this->fail('Expected exception');
        } catch (NoSuchEntityException $e) {
            $this->assertSame('Cart 3 no longer exists.', $e->getMessage());
        }

        $this->assertSame(2, $session->getRegisterId());
        $this->assertSame(9, $session->getUserId());
        $this->assertSame('open', $session->getStatus());
        $this->assertSame(0.0, $session->getOpeningFloat());
        $this->assertSame('Auto-opened at checkout (session requirement disabled)', $session->getNote());
        $this->assertSame(40, $this->sessionData['panth_pos_session_id']);
    }

    public function testExplicitRegisterOptionWinsOverDefault(): void
    {
        $this->sessionRequired = false;
        $this->defaultRegister = 2;
        $this->useQuote(['panth_pos_register_id' => 5]);
        $session = $this->makeModel(Session::class, 'session_id');
        $this->sessionFactory->method('create')->willReturn($session);
        $this->sessionRepository->method('save')->willReturnArgument(0);

        try {
            $this->makeService()->placeOrder(3, [], ['register_id' => 7]);
        } catch (NoSuchEntityException) {
        }

        $this->assertSame(7, $session->getRegisterId());
    }
}
