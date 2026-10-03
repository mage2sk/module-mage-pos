<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Customer;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Panth\MagePos\Controller\Customer\Create;
use Panth\MagePos\Controller\Customer\Search;
use Panth\MagePos\Service\CustomerService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class CustomerControllersTest extends AbstractControllerTestCase
{
    private CustomerService&MockObject $customerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerService = $this->createMock(CustomerService::class);
    }

    private function dispatch(string $class): void
    {
        (new $class(...[...$this->baseArgs(), $this->customerService]))->execute();
    }

    public function testSearchRequiresSignIn(): void
    {
        $this->signOut();
        $this->customerService->expects($this->never())->method('search');

        $this->dispatch(Search::class);

        $this->assertError('unauthorized', 'unauthorized');
    }

    public function testSearchPassesQuery(): void
    {
        $this->signIn();
        $this->params = ['q' => 'ann'];
        $this->customerService->expects($this->once())->method('search')->with('ann')->willReturn([['id' => 1]]);

        $this->dispatch(Search::class);

        $this->assertSuccess([['id' => 1]]);
    }

    public function testSearchReportsError(): void
    {
        $this->signIn();
        $this->customerService->method('search')->willThrowException($this->localized('Nope.'));

        $this->dispatch(Search::class);

        $this->assertError('Nope.');
    }

    public function testCreateRequiresFormKey(): void
    {
        $this->signIn();
        $this->formKeyValid = false;
        $this->customerService->expects($this->never())->method('create');

        $this->dispatch(Create::class);

        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    public function testCreatePassesOnlyKnownFieldsAsStrings(): void
    {
        $this->signIn();
        $this->body = ['firstname' => 'Ann', 'lastname' => 'Lee', 'email' => 'a@b.co', 'phone' => 123, 'group_id' => 4];
        $this->customerService->expects($this->once())->method('create')
            ->with(['firstname' => 'Ann', 'lastname' => 'Lee', 'email' => 'a@b.co', 'phone' => '123'])
            ->willReturn(['id' => 9]);

        $this->dispatch(Create::class);

        $this->assertSuccess(['id' => 9]);
        $this->assertSame('Customer created.', $this->result['message']);
    }

    public function testCreateValidationError(): void
    {
        $this->signIn();
        $this->customerService->method('create')->willThrowException($this->localized('First name and last name are required.'));

        $this->dispatch(Create::class);

        $this->assertError('First name and last name are required.');
    }

    public function testCreateUnexpectedErrorIncludesReason(): void
    {
        $this->signIn();
        $this->customerService->method('create')->willThrowException(new \RuntimeException('duplicate'));

        $this->dispatch(Create::class);

        $this->assertError('Could not create the customer: duplicate');
    }
}
