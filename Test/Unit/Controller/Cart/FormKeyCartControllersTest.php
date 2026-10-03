<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Cart;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Magento\Framework\Data\Form\FormKey;
use Panth\MagePos\Controller\Cart\Coupon;
use Panth\MagePos\Controller\Cart\Custom;
use Panth\MagePos\Controller\Cart\Discount;
use Panth\MagePos\Service\CartService;
use Panth\MagePos\Service\DiscountService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class FormKeyCartControllersTest extends AbstractControllerTestCase
{
    private FormKey&MockObject $formKey;
    private DiscountService&MockObject $discountService;
    private CartService&MockObject $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formKey = $this->createMock(FormKey::class);
        $this->formKey->method('getFormKey')->willReturn('secret');
        $this->discountService = $this->createMock(DiscountService::class);
        $this->cartService = $this->createMock(CartService::class);
    }

    private function dispatch(string $class): void
    {
        $service = $class === Custom::class ? $this->cartService : $this->discountService;
        (new $class(...[...$this->baseArgs(), $this->formKey, $service]))->execute();
    }

    public static function controllerProvider(): array
    {
        return [[Coupon::class], [Custom::class], [Discount::class]];
    }

    #[DataProvider('controllerProvider')]
    public function testBodyFormKeyIsAcceptedWhenValidatorFails(string $class): void
    {
        $this->formKeyValid = false;
        $this->signIn();
        $this->body = ['form_key' => 'secret'];

        $this->dispatch($class);

        $this->assertError('quote_id is required.');
    }

    #[DataProvider('controllerProvider')]
    public function testWrongBodyFormKeyIsForbidden(string $class): void
    {
        $this->formKeyValid = false;
        $this->signIn();
        $this->body = ['form_key' => 'nope', 'quote_id' => 3];

        $this->dispatch($class);

        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    #[DataProvider('controllerProvider')]
    public function testAnonymousIsUnauthorized(string $class): void
    {
        $this->signOut();

        $this->dispatch($class);

        $this->assertError('unauthorized', 'unauthorized');
    }

    #[DataProvider('controllerProvider')]
    public function testDisabled(string $class): void
    {
        $this->enabled = false;

        $this->dispatch($class);

        $this->assertError('POS is disabled.', 'disabled');
    }

    public function testCouponRequiresCode(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'code' => '  '];

        $this->dispatch(Coupon::class);

        $this->assertError('A coupon code is required.');
    }

    public function testCouponApplyTrimsCode(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'code' => ' SAVE10 '];
        $this->discountService->expects($this->once())->method('applyCoupon')->with(3, 'SAVE10')
            ->willReturn(['coupon_code' => 'SAVE10']);

        $this->dispatch(Coupon::class);

        $this->assertSuccess(['coupon_code' => 'SAVE10']);
    }

    public function testCouponRemove(): void
    {
        $this->signIn();
        $this->params = ['quote_id' => 3, 'remove' => 'true'];
        $this->discountService->expects($this->once())->method('removeCoupon')->with(3)->willReturn([]);
        $this->discountService->expects($this->never())->method('applyCoupon');

        $this->dispatch(Coupon::class);

        $this->assertSuccess([]);
    }

    public function testCouponQueryParamsOverrideBody(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'code' => 'BODY'];
        $this->params = ['code' => 'PARAM'];
        $this->discountService->expects($this->once())->method('applyCoupon')->with(3, 'PARAM')->willReturn([]);

        $this->dispatch(Coupon::class);

        $this->assertSuccess([]);
    }

    public function testCouponServiceError(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'code' => 'X'];
        $this->discountService->method('applyCoupon')->willThrowException($this->localized('The coupon is not valid.'));

        $this->dispatch(Coupon::class);

        $this->assertError('The coupon is not valid.');
    }

    public function testCouponUnexpectedFailureIsMasked(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'code' => 'X'];
        $this->discountService->method('applyCoupon')->willThrowException(new \RuntimeException('db'));

        $this->dispatch(Coupon::class);

        $this->assertError('Unable to update the coupon. Please try again.');
    }

    public static function customValidationProvider(): array
    {
        return [
            [['quote_id' => 3, 'name' => ' '], 'A product name is required.'],
            [['quote_id' => 3, 'name' => 'Fee', 'price' => -1], 'The price cannot be negative.'],
            [['quote_id' => 3, 'name' => 'Fee', 'price' => 1, 'qty' => 0], 'The quantity must be greater than zero.'],
        ];
    }

    #[DataProvider('customValidationProvider')]
    public function testCustomItemValidation(array $body, string $message): void
    {
        $this->signIn();
        $this->body = $body;
        $this->cartService->expects($this->never())->method('addCustomItem');

        $this->dispatch(Custom::class);

        $this->assertError($message);
    }

    public function testCustomItemRequiresPermission(): void
    {
        $this->signIn();
        $this->denyPermission('can_custom_product');
        $this->body = ['quote_id' => 3, 'name' => 'Fee', 'price' => 2];
        $this->cartService->expects($this->never())->method('addCustomItem');

        $this->dispatch(Custom::class);

        $this->assertError('You do not have permission to perform this action.');
    }

    public function testCustomItemPassesParsedValues(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'name' => ' Fee ', 'price' => '2.5', 'qty' => '3', 'tax_class_id' => '0'];
        $this->cartService->expects($this->once())->method('addCustomItem')->with(3, 'Fee', 2.5, 3.0, 0)
            ->willReturn(['ok' => 1]);

        $this->dispatch(Custom::class);

        $this->assertSuccess(['ok' => 1]);
    }

    public function testCustomItemDefaultsQtyAndTaxClass(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'name' => 'Fee', 'tax_class_id' => ''];
        $this->cartService->expects($this->once())->method('addCustomItem')->with(3, 'Fee', 0.0, 1.0, null)
            ->willReturn([]);

        $this->dispatch(Custom::class);

        $this->assertSuccess([]);
    }

    public function testCustomItemUnexpectedFailureIsMasked(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'name' => 'Fee'];
        $this->cartService->method('addCustomItem')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Custom::class);

        $this->assertError('Unable to add the custom product. Please try again.');
    }

    public function testDiscountCartScopeIsDefault(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'type' => 'percent', 'value' => '10'];
        $this->discountService->expects($this->once())->method('applyCartDiscount')->with(3, 'percent', 10.0)
            ->willReturn(['a' => 1]);

        $this->dispatch(Discount::class);

        $this->assertSuccess(['a' => 1]);
    }

    public function testDiscountItemScopeNeedsItem(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'scope' => ' ITEM ', 'type' => 'fixed', 'value' => 1];

        $this->dispatch(Discount::class);

        $this->assertError('item_id is required for an item discount.');
    }

    public function testDiscountItemScope(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'scope' => 'item', 'item_id' => 8, 'type' => 'fixed', 'value' => '1.5'];
        $this->discountService->expects($this->once())->method('applyItemDiscount')->with(3, 8, 'fixed', 1.5)
            ->willReturn([]);

        $this->dispatch(Discount::class);

        $this->assertSuccess([]);
    }

    public function testDiscountRejectsUnknownScope(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'scope' => 'order'];

        $this->dispatch(Discount::class);

        $this->assertError('Discount scope must be "cart" or "item".');
    }

    public function testDiscountRemoveItem(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'scope' => 'item', 'item_id' => 8, 'remove' => '1'];
        $this->discountService->expects($this->once())->method('removeItemDiscount')->with(3, 8)->willReturn(['item' => 1]);

        $this->dispatch(Discount::class);

        $this->assertSuccess(['item' => 1]);
    }

    public function testDiscountRemoveItemWithoutIdFallsBackToCart(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'scope' => 'item', 'remove' => true];
        $this->discountService->expects($this->once())->method('removeCartDiscount')->with(3)->willReturn(['cart' => 1]);

        $this->dispatch(Discount::class);

        $this->assertSuccess(['cart' => 1]);
    }

    public function testDiscountServiceError(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'type' => 'percent', 'value' => 90];
        $this->discountService->method('applyCartDiscount')->willThrowException($this->localized('Exceeds your limit.'));

        $this->dispatch(Discount::class);

        $this->assertError('Exceeds your limit.');
    }

    public function testDiscountUnexpectedFailureIsMasked(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'type' => 'percent', 'value' => 9];
        $this->discountService->method('applyCartDiscount')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Discount::class);

        $this->assertError('Unable to update the discount. Please try again.');
    }
}
