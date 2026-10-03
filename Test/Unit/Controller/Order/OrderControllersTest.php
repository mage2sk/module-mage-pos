<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Order;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Panth\MagePos\Controller\Order\Preview;
use Panth\MagePos\Controller\Order\Refund;
use Panth\MagePos\Controller\Order\Search;
use Panth\MagePos\Service\RefundService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class OrderControllersTest extends AbstractControllerTestCase
{
    private RefundService&MockObject $refundService;
    private LoggerInterface&MockObject $logger;
    private ?string $rawBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rawBody = null;
        $this->refundService = $this->createMock(RefundService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function dispatch(string $class): void
    {
        $args = $class === Refund::class
            ? [...$this->baseArgs(), $this->refundService, new JsonSerializer(), $this->logger]
            : [...$this->baseArgs(), $this->refundService, $this->logger];
        (new $class(...$args))->execute();
    }

    public function testSearchRequiresRefundPermission(): void
    {
        $this->signIn();
        $this->denyPermission('can_refund');
        $this->refundService->expects($this->never())->method('searchOrders');

        $this->dispatch(Search::class);

        $this->assertError('You do not have permission to perform this action.', 'forbidden', 403);
    }

    public function testSearchAnonymous(): void
    {
        $this->signOut();

        $this->dispatch(Search::class);

        $this->assertError('unauthorized', 'unauthorized');
    }

    public function testSearchPassesQuery(): void
    {
        $this->signIn();
        $this->params = ['q' => '000123'];
        $this->refundService->expects($this->once())->method('searchOrders')->with('000123')->willReturn(['orders' => []]);

        $this->dispatch(Search::class);

        $this->assertSuccess(['orders' => []]);
    }

    public function testSearchUnexpectedFailureIsLogged(): void
    {
        $this->signIn();
        $this->refundService->method('searchOrders')->willThrowException(new \RuntimeException('db'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('Order search failed: db'));

        $this->dispatch(Search::class);

        $this->assertError('Unable to search orders. Please try again.');
    }

    public function testPreviewRequiresOrderId(): void
    {
        $this->signIn();
        $this->body = ['items' => [1 => 1]];

        $this->dispatch(Preview::class);

        $this->assertError('order_id is required.');
    }

    public function testPreviewIgnoresNonArrayItems(): void
    {
        $this->signIn();
        $this->body = ['order_id' => '5', 'items' => 'all'];
        $this->refundService->expects($this->once())->method('preview')->with(5, [])->willReturn(['refund_total' => 1.0]);

        $this->dispatch(Preview::class);

        $this->assertSuccess(['refund_total' => 1.0]);
    }

    public function testPreviewErrors(): void
    {
        $this->signIn();
        $this->body = ['order_id' => 5];
        $this->refundService->method('preview')->willReturnOnConsecutiveCalls(
            $this->throwException(new NoSuchEntityException(__('x'))),
            $this->throwException($this->localized('Order cannot be refunded.')),
            $this->throwException(new \RuntimeException('x'))
        );

        $this->dispatch(Preview::class);
        $this->assertError('The requested order does not exist.');
        $this->dispatch(Preview::class);
        $this->assertError('Order cannot be refunded.');
        $this->dispatch(Preview::class);
        $this->assertError('Unable to compute the refund preview. Please try again.');
    }

    public function testRefundUsesBodyFormKeyWhenParamMissing(): void
    {
        $this->signIn();
        $this->body = ['form_key' => 'fk', 'order_id' => 5, 'items' => [7 => 1], 'payments' => ['x' => ['method_code' => 'cash', 'amount' => 5]], 'restock' => 'yes', 'reason' => ' broken '];
        $this->refundService->expects($this->once())->method('refund')
            ->with(5, [7 => 1], [['method_code' => 'cash', 'amount' => 5]], true, 'broken')
            ->willReturn(['creditmemo_id' => 1]);

        $this->dispatch(Refund::class);

        $this->assertSame(['form_key' => 'fk'], $this->params);
        $this->assertSuccess(['creditmemo_id' => 1]);
    }

    public function testRefundInvalidFormKey(): void
    {
        $this->signIn();
        $this->formKeyValid = false;
        $this->refundService->expects($this->never())->method('refund');

        $this->dispatch(Refund::class);

        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    public function testRefundAnonymous(): void
    {
        $this->signOut();

        $this->dispatch(Refund::class);

        $this->assertError('unauthorized', 'unauthorized');
    }

    public function testRefundRequiresOrderAndDefaultsOptions(): void
    {
        $this->signIn();
        $this->dispatch(Refund::class);
        $this->assertError('order_id is required.');

        $this->params = ['order_id' => '5'];
        $this->refundService->expects($this->once())->method('refund')->with(5, [], [], false, '')->willReturn([]);
        $this->dispatch(Refund::class);
        $this->assertSuccess([]);
    }

    public function testRefundErrors(): void
    {
        $this->signIn();
        $this->params = ['order_id' => '5'];
        $this->refundService->method('refund')->willReturnOnConsecutiveCalls(
            $this->throwException(new NoSuchEntityException(__('x'))),
            $this->throwException($this->localized('Refund payments must equal the refund total.')),
            $this->throwException(new \RuntimeException('x'))
        );
        $this->logger->expects($this->once())->method('error');

        $this->dispatch(Refund::class);
        $this->assertError('The requested order does not exist.');
        $this->dispatch(Refund::class);
        $this->assertError('Refund payments must equal the refund total.');
        $this->dispatch(Refund::class);
        $this->assertError('Unable to process the refund. Please try again.');
    }
}
