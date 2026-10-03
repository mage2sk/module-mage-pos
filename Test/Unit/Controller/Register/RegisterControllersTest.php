<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Register;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Controller\Register\All;
use Panth\MagePos\Controller\Register\Close;
use Panth\MagePos\Controller\Register\Current;
use Panth\MagePos\Controller\Register\Movement;
use Panth\MagePos\Controller\Register\Open;
use Panth\MagePos\Controller\Register\Xreport;
use Panth\MagePos\Model\Register;
use Panth\MagePos\Service\PosSessionService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class RegisterControllersTest extends AbstractControllerTestCase
{
    private PosSessionService&MockObject $posSessionService;
    private RegisterRepositoryInterface&MockObject $registerRepository;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->posSessionService = $this->createMock(PosSessionService::class);
        $this->registerRepository = $this->createMock(RegisterRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function dispatch(string $class): void
    {
        $args = $class === All::class
            ? [...$this->baseArgs(), $this->registerRepository, $this->makeCriteriaBuilder(), $this->makeSortOrderBuilder(), $this->logger]
            : [...$this->baseArgs(), $this->posSessionService, $this->logger];
        (new $class(...$args))->execute();
    }

    public static function controllerProvider(): array
    {
        return [[All::class], [Close::class], [Current::class], [Movement::class], [Open::class], [Xreport::class]];
    }

    #[DataProvider('controllerProvider')]
    public function testAnonymousRejected(string $class): void
    {
        $this->signOut();

        $this->dispatch($class);

        $this->assertError('unauthorized', 'unauthorized');
    }

    public function testAllListsActiveRegisters(): void
    {
        $this->signIn();
        $this->registerRepository->method('getList')->willReturn($this->makeSearchResults([
            $this->makeModel(Register::class, 'register_id', ['register_id' => '2', 'name' => 'Front', 'code' => 'R1', 'store_id' => '1']),
            new \stdClass(),
        ]));

        $this->dispatch(All::class);

        $this->assertSuccess([['id' => 2, 'name' => 'Front', 'code' => 'R1', 'store_id' => 1]]);
        $this->assertSame([['status', 1, 'eq']], $this->criteriaFilters);
    }

    public function testAllLogsAndMasksFailure(): void
    {
        $this->signIn();
        $this->registerRepository->method('getList')->willThrowException(new \RuntimeException('db'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('register/all failed: db'));

        $this->dispatch(All::class);

        $this->assertError('Unable to load registers.');
    }

    public function testOpenRequiresPermission(): void
    {
        $this->signIn();
        $this->denyPermission('can_open_close');
        $this->posSessionService->expects($this->never())->method('open');

        $this->dispatch(Open::class);

        $this->assertError('You do not have permission to perform this action.');
    }

    public function testOpenRequiresRegister(): void
    {
        $this->signIn();
        $this->body = ['opening_float' => 10];

        $this->dispatch(Open::class);

        $this->assertError('The register_id parameter is required.');
    }

    public function testOpenPassesFloatAndNote(): void
    {
        $this->signIn();
        $this->body = ['register_id' => '2', 'opening_float' => '100.5', 'note' => ''];
        $this->posSessionService->expects($this->once())->method('open')->with(2, 100.5, null)->willReturn(['session_id' => 7]);

        $this->dispatch(Open::class);

        $this->assertSuccess(['session_id' => 7]);
    }

    public function testOpenUnexpectedFailureIsLogged(): void
    {
        $this->signIn();
        $this->body = ['register_id' => 2, 'note' => 'morning'];
        $this->posSessionService->method('open')->with(2, 0.0, 'morning')->willThrowException(new \RuntimeException('x'));
        $this->logger->expects($this->once())->method('error');

        $this->dispatch(Open::class);

        $this->assertError('Unable to open the register session.');
    }

    public function testCloseWithoutOpenSession(): void
    {
        $this->signIn();
        $this->posSessionService->method('current')->willReturn(null);
        $this->posSessionService->expects($this->never())->method('close');

        $this->dispatch(Close::class);

        $this->assertError('There is no open register session to close.');
    }

    public function testCloseCurrentSession(): void
    {
        $this->signIn();
        $this->body = ['counted_cash' => '149.5', 'note' => 'short'];
        $this->posSessionService->method('current')->willReturn(['session_id' => '7']);
        $this->posSessionService->expects($this->once())->method('close')->with(7, 149.5, 'short')->willReturn(['status' => 'closed']);

        $this->dispatch(Close::class);

        $this->assertSuccess(['status' => 'closed']);
    }

    public function testCloseUnexpectedFailure(): void
    {
        $this->signIn();
        $this->posSessionService->method('current')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Close::class);

        $this->assertError('Unable to close the register session.');
    }

    public function testCurrentSession(): void
    {
        $this->signIn();
        $this->posSessionService->method('current')->willReturn(null);

        $this->dispatch(Current::class);

        $this->assertSuccess(null);
    }

    public function testCurrentFailures(): void
    {
        $this->signIn();
        $this->posSessionService->method('current')->willThrowException(new \RuntimeException('x'));
        $this->logger->expects($this->once())->method('error');

        $this->dispatch(Current::class);

        $this->assertError('Unable to load the current register session.');
    }

    public function testMovementRequiresCashPermission(): void
    {
        $this->signIn();
        $this->denyPermission('can_cash_inout');

        $this->dispatch(Movement::class);

        $this->assertError('You do not have permission to perform this action.');
    }

    public function testMovementPassesValues(): void
    {
        $this->signIn();
        $this->body = ['type' => 'out', 'amount' => '20', 'reason' => '  bank  '];
        $this->posSessionService->expects($this->once())->method('addMovement')->with('out', 20.0, 'bank')->willReturn(['movement_id' => 3]);

        $this->dispatch(Movement::class);

        $this->assertSuccess(['movement_id' => 3]);
    }

    public function testMovementServiceError(): void
    {
        $this->signIn();
        $this->posSessionService->method('addMovement')->willThrowException($this->localized('Invalid cash movement type.'));

        $this->dispatch(Movement::class);

        $this->assertError('Invalid cash movement type.');
    }

    public function testMovementUnexpectedFailure(): void
    {
        $this->signIn();
        $this->posSessionService->method('addMovement')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Movement::class);

        $this->assertError('Unable to record the cash movement.');
    }

    public function testXreportRequiresReportPermission(): void
    {
        $this->signIn();
        $this->denyPermission('can_view_reports');

        $this->dispatch(Xreport::class);

        $this->assertError('You do not have permission to perform this action.', 'forbidden', 403);
    }

    public function testXreportForExplicitSession(): void
    {
        $this->signIn();
        $this->params = ['session_id' => '4'];
        $this->posSessionService->expects($this->never())->method('current');
        $this->posSessionService->expects($this->once())->method('xReport')->with(4)->willReturn(['gross' => 1]);

        $this->dispatch(Xreport::class);

        $this->assertSuccess(['gross' => 1]);
    }

    public function testXreportDefaultsToCurrentSession(): void
    {
        $this->signIn();
        $this->posSessionService->method('current')->willReturn(['session_id' => 7]);
        $this->posSessionService->expects($this->once())->method('xReport')->with(7)->willReturn([]);

        $this->dispatch(Xreport::class);

        $this->assertSuccess([]);
    }

    public function testXreportWithoutOpenSession(): void
    {
        $this->signIn();
        $this->posSessionService->method('current')->willReturn(null);

        $this->dispatch(Xreport::class);

        $this->assertError('There is no open register session.');
    }

    public function testXreportForUnknownSession(): void
    {
        $this->signIn();
        $this->params = ['session_id' => '4'];
        $this->posSessionService->method('xReport')->willThrowException(new NoSuchEntityException(__('x')));

        $this->dispatch(Xreport::class);

        $this->assertError('The requested register session does not exist.');
    }

    public function testXreportUnexpectedFailure(): void
    {
        $this->signIn();
        $this->params = ['session_id' => '4'];
        $this->posSessionService->method('xReport')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Xreport::class);

        $this->assertError('Unable to build the X report.');
    }
}
