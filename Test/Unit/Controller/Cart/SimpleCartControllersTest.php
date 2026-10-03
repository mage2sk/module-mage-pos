<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Cart;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Panth\MagePos\Controller\Cart\Clear;
use Panth\MagePos\Controller\Cart\Create;
use Panth\MagePos\Controller\Cart\Customer;
use Panth\MagePos\Controller\Cart\Get;
use Panth\MagePos\Controller\Cart\Note;
use Panth\MagePos\Controller\Cart\Remove;
use Panth\MagePos\Controller\Cart\Update;
use Panth\MagePos\Service\CartService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class SimpleCartControllersTest extends AbstractControllerTestCase
{
    private CartService&MockObject $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartService = $this->createMock(CartService::class);
    }

    private function dispatch(string $class): void
    {
        (new $class(...[...$this->baseArgs(), $this->cartService]))->execute();
    }

    public static function controllerProvider(): array
    {
        return [
            [Clear::class], [Create::class], [Customer::class], [Get::class], [Note::class], [Remove::class], [Update::class],
        ];
    }

    #[DataProvider('controllerProvider')]
    public function testEveryCartActionRequiresSignedInCashier(string $class): void
    {
        $this->signOut();
        $this->params = ['quote_id' => 3, 'item_id' => 4, 'qty' => 1];

        $this->dispatch($class);

        $this->assertError('unauthorized', 'unauthorized');
    }

    #[DataProvider('controllerProvider')]
    public function testEveryCartActionHonoursDisabledFlag(string $class): void
    {
        $this->enabled = false;

        $this->dispatch($class);

        $this->assertError('POS is disabled.', 'disabled');
    }

    public static function quoteRequiredProvider(): array
    {
        return [
            [Clear::class, 'quote_id is required.'],
            [Customer::class, 'quote_id is required.'],
            [Get::class, 'quote_id is required.'],
            [Note::class, 'quote_id is required.'],
            [Remove::class, 'quote_id and item_id are required.'],
            [Update::class, 'quote_id and item_id are required.'],
        ];
    }

    #[DataProvider('quoteRequiredProvider')]
    public function testQuoteIdIsValidated(string $class, string $message): void
    {
        $this->signIn();
        $this->params = ['quote_id' => '0', 'item_id' => 4];

        $this->dispatch($class);

        $this->assertError($message, 'bad_request');
    }

    public function testGetDoesNotRequireFormKey(): void
    {
        $this->signIn();
        $this->formKeyValid = false;
        $this->params = ['quote_id' => '5'];
        $this->cartService->expects($this->once())->method('get')->with(5)->willReturn(['quote_id' => 5]);

        $this->dispatch(Get::class);

        $this->assertSuccess(['quote_id' => 5]);
    }

    public function testGetMasksUnexpectedFailure(): void
    {
        $this->signIn();
        $this->params = ['quote_id' => '5'];
        $this->cartService->method('get')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Get::class);

        $this->assertError('Unable to load the cart.');
    }

    public function testMutatingActionsRequireFormKey(): void
    {
        $this->signIn();
        $this->formKeyValid = false;
        $this->cartService->expects($this->never())->method('clear');

        $this->dispatch(Clear::class);

        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    public function testCreateReturnsNewCartPayload(): void
    {
        $this->signIn();
        $this->cartService->method('create')->willReturn(42);
        $this->cartService->expects($this->once())->method('get')->with(42)->willReturn(['quote_id' => 42]);

        $this->dispatch(Create::class);

        $this->assertSuccess(['quote_id' => 42]);
    }

    public function testCreateMasksUnexpectedFailure(): void
    {
        $this->signIn();
        $this->cartService->method('create')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Create::class);

        $this->assertError('Unable to create a new cart.');
    }

    public function testClearDelegatesToService(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3];
        $this->cartService->expects($this->once())->method('clear')->with(3)->willReturn(['items' => []]);

        $this->dispatch(Clear::class);

        $this->assertSuccess(['items' => []]);
    }

    public static function customerIdProvider(): array
    {
        return [
            'numeric' => ['7', 7],
            'null string' => ['NULL', null],
            'empty' => ['', null],
            'zero' => ['0', null],
            'missing' => [null, null],
        ];
    }

    #[DataProvider('customerIdProvider')]
    public function testCustomerIdIsNormalised(?string $raw, ?int $expected): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'customer_id' => $raw];
        $this->cartService->expects($this->once())->method('setCustomer')->with(3, $expected)->willReturn([]);

        $this->dispatch(Customer::class);

        $this->assertSuccess([]);
    }

    public function testCustomerFailureIsMasked(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'customer_id' => 7];
        $this->cartService->method('setCustomer')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Customer::class);

        $this->assertError('Unable to assign the customer to the cart.');
    }

    public function testNoteDefaultsToEmptyString(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3];
        $this->cartService->expects($this->once())->method('setNote')->with(3, '')->willReturn(['note' => null]);

        $this->dispatch(Note::class);

        $this->assertSuccess(['note' => null]);
    }

    public function testRemoveReportsServiceError(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'item_id' => 4];
        $this->cartService->method('removeItem')->with(3, 4)->willThrowException($this->localized('Cart "3" was not found.'));

        $this->dispatch(Remove::class);

        $this->assertError('Cart "3" was not found.');
    }

    public function testUpdateRequiresSomethingToChange(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'item_id' => 4, 'qty' => '', 'price' => ''];
        $this->cartService->expects($this->never())->method('updateItem');

        $this->dispatch(Update::class);

        $this->assertError('Nothing to update.', 'bad_request');
    }

    public function testUpdatePassesParsedValues(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'item_id' => 4, 'qty' => '2', 'price' => '9.5', 'note' => 5];
        $this->cartService->expects($this->once())->method('updateItem')->with(3, 4, 2.0, 9.5, '5')->willReturn(['ok' => true]);

        $this->dispatch(Update::class);

        $this->assertSuccess(['ok' => true]);
    }

    public function testUpdateWithOnlyNote(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'item_id' => 4, 'note' => ''];
        $this->cartService->expects($this->once())->method('updateItem')->with(3, 4, null, null, '')->willReturn([]);

        $this->dispatch(Update::class);

        $this->assertSuccess([]);
    }

    public function testUpdateMasksUnexpectedFailure(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'item_id' => 4, 'qty' => 1];
        $this->cartService->method('updateItem')->willThrowException(new \Error('x'));

        $this->dispatch(Update::class);

        $this->assertError('Unable to update the cart item.');
    }
}
