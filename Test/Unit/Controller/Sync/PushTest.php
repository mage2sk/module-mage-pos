<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Sync;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Panth\MagePos\Controller\Sync\Push;
use Panth\MagePos\Service\SyncService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class PushTest extends AbstractControllerTestCase
{
    private SyncService&MockObject $syncService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncService = $this->createMock(SyncService::class);
    }

    private function dispatch(): void
    {
        (new Push(...[...$this->baseArgs(), $this->syncService]))->execute();
    }

    public function testNothingToSync(): void
    {
        $this->signIn();
        $this->body = ['orders' => 'garbage'];
        $this->syncService->expects($this->never())->method('pushOrders');

        $this->dispatch();

        $this->assertTrue($this->result['success']);
        $this->assertEquals((object) [], $this->result['data']['results']);
        $this->assertSame('Nothing to sync.', $this->result['message']);
    }

    public function testOrdersAreReindexedAndPushed(): void
    {
        $this->signIn();
        $this->body = ['orders' => ['a' => ['client_uuid' => 'u1'], 'b' => ['client_uuid' => 'u2']]];
        $this->syncService->expects($this->once())->method('pushOrders')
            ->with([['client_uuid' => 'u1'], ['client_uuid' => 'u2']])
            ->willReturn(['u1' => ['status' => 'created']]);

        $this->dispatch();

        $this->assertTrue($this->result['success']);
        $this->assertEquals((object) ['u1' => ['status' => 'created']], $this->result['data']['results']);
    }

    public function testJsonStringOrdersAreDecoded(): void
    {
        $this->signIn();
        $this->params = ['orders' => '[{"client_uuid":"u1"}]'];
        $this->syncService->expects($this->once())->method('pushOrders')->with([['client_uuid' => 'u1']])->willReturn([]);

        $this->dispatch();

        $this->assertTrue($this->result['success']);
    }

    public function testServiceError(): void
    {
        $this->signIn();
        $this->body = ['orders' => [['client_uuid' => 'u1']]];
        $this->syncService->method('pushOrders')->willThrowException($this->localized('unauthorized'));

        $this->dispatch();

        $this->assertError('unauthorized');
    }

    public function testRequiresFormKeyAndSignIn(): void
    {
        $this->formKeyValid = false;
        $this->signIn();
        $this->dispatch();
        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    public function testAnonymousRejected(): void
    {
        $this->signOut();
        $this->dispatch();
        $this->assertError(self::SESSION_EXPIRED, 'unauthorized');
    }
}
