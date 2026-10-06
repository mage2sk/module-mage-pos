<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Cart;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Panth\MagePos\Controller\Cart\Add;
use Panth\MagePos\Service\CartService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class AddTest extends AbstractControllerTestCase
{
    private CartService&MockObject $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cartService = $this->createMock(CartService::class);
    }

    private function execute(): void
    {
        (new Add(...[...$this->baseArgs(), $this->cartService]))->execute();
    }

    public function testDisabledPosShortCircuits(): void
    {
        $this->enabled = false;
        $this->cartService->expects($this->never())->method('addProduct');

        $this->execute();

        $this->assertError('POS is disabled.', 'disabled');
    }

    public function testInvalidFormKeyIsForbidden(): void
    {
        $this->formKeyValid = false;
        $this->signIn();

        $this->execute();

        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    public function testFormKeyFromJsonBodyIsCopiedIntoRequestParams(): void
    {
        $this->signIn();
        $this->body = ['form_key' => 'abc', 'quote_id' => 3, 'sku' => 'TEE'];
        $this->cartService->method('addProduct')->willReturn(['quote_id' => 3]);

        $this->execute();

        $this->assertSame('abc', $this->params['form_key']);
        $this->assertSuccess(['quote_id' => 3]);
    }

    public function testAnonymousUserIsUnauthorized(): void
    {
        $this->signOut();

        $this->execute();

        $this->assertError(self::SESSION_EXPIRED, 'unauthorized');
    }

    public function testQuoteIdIsRequired(): void
    {
        $this->signIn();
        $this->body = ['sku' => 'TEE'];

        $this->execute();

        $this->assertError('quote_id is required.', 'bad_request');
    }

    public function testProductOrSkuIsRequired(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'product_id' => '0', 'sku' => '  '];

        $this->execute();

        $this->assertError('product_id or sku is required.', 'bad_request');
    }

    public function testProductIdTakesPrecedenceAndDefaultsQty(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => '3', 'product_id' => '12', 'sku' => 'TEE'];
        $this->cartService->expects($this->once())->method('addProduct')->with(3, 12, 1.0, [])->willReturn(['ok' => 1]);

        $this->execute();

        $this->assertSuccess(['ok' => 1]);
    }

    public function testSkuQtyAndJsonEncodedOptionsArePassed(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'sku' => ' TEE ', 'qty' => '2.5', 'options' => '{"super_attribute":{"93":"5"}}'];
        $this->cartService->expects($this->once())->method('addProduct')
            ->with(3, 'TEE', 2.5, ['super_attribute' => ['93' => '5']])->willReturn([]);

        $this->execute();

        $this->assertSuccess([]);
    }

    public function testInvalidOptionsJsonIsIgnored(): void
    {
        $this->signIn();
        $this->params = ['quote_id' => 3, 'sku' => 'TEE', 'options' => '{bad'];
        $this->cartService->expects($this->once())->method('addProduct')->with(3, 'TEE', 1.0, [])->willReturn([]);

        $this->execute();

        $this->assertSuccess([]);
    }

    public function testServiceErrorsAreReported(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'sku' => 'TEE'];
        $this->cartService->method('addProduct')->willThrowException($this->localized('Out of stock.'));

        $this->execute();

        $this->assertError('Out of stock.');
    }

    public function testUnexpectedErrorsAreMasked(): void
    {
        $this->signIn();
        $this->body = ['quote_id' => 3, 'sku' => 'TEE'];
        $this->cartService->method('addProduct')->willThrowException(new \RuntimeException('SQL'));

        $this->execute();

        $this->assertError('Unable to add the product to the cart.');
    }

    public function testPosApiOptsOutOfCoreCsrfInFavourOfFormKeyCheck(): void
    {
        $controller = new Add(...[...$this->baseArgs(), $this->cartService]);

        $this->assertTrue($controller->validateForCsrf($this->request));
        $this->assertNull($controller->createCsrfValidationException($this->request));
    }
}
