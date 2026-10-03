<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Payment;

require_once __DIR__ . '/../../autoload.php';

use Panth\MagePos\Api\PaymentProcessorInterface;
use Panth\MagePos\Model\Payment\ProcessorPool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ProcessorPoolTest extends TestCase
{
    public function testGetForMethodReturnsFirstSupportingProcessor(): void
    {
        $stripe = $this->makeProcessor(['stripe_terminal']);
        $first = $this->makeProcessor(['payment_link']);
        $second = $this->makeProcessor(['payment_link']);

        $pool = new ProcessorPool([
            'stripe' => $stripe,
            'first' => $first,
            'second' => $second,
        ]);

        $this->assertSame($first, $pool->getForMethod('payment_link'));
        $this->assertSame($stripe, $pool->getForMethod('stripe_terminal'));
    }

    public function testGetForMethodReturnsNullWhenNoProcessorSupportsCode(): void
    {
        $pool = new ProcessorPool(['stripe' => $this->makeProcessor(['stripe_terminal'])]);

        $this->assertNull($pool->getForMethod('bank_transfer'));
    }

    public function testEmptyPoolResolvesNothing(): void
    {
        $pool = new ProcessorPool();

        $this->assertNull($pool->getForMethod('payment_link'));
    }

    public function testConstructorRejectsProcessorNotImplementingContract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"acme_gateway" must implement');

        new ProcessorPool(['acme_gateway' => new \stdClass()]);
    }

    private function makeProcessor(array $supportedCodes): PaymentProcessorInterface
    {
        $processor = $this->createMock(PaymentProcessorInterface::class);
        $processor->method('supports')->willReturnCallback(
            static fn (string $code): bool => in_array($code, $supportedCodes, true)
        );

        return $processor;
    }
}
