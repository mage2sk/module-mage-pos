<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit;

use PHPUnit\Framework\MockObject\MockObject;

trait MagicCallsTrait
{
    private function stubMagicCalls(MockObject $mock, array $handlers): void
    {
        $mock->method('__call')->willReturnCallback(
            static function (string $method, array $args = []) use ($handlers) {
                if (!array_key_exists($method, $handlers)) {
                    throw new \BadMethodCallException(sprintf('Unexpected magic call %s().', $method));
                }
                $handler = $handlers[$method];

                return $handler instanceof \Closure ? $handler(...$args) : $handler;
            }
        );
    }
}
