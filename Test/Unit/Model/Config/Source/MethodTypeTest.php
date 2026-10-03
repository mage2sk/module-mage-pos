<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model\Config\Source;

require_once __DIR__ . '/../../../autoload.php';

use Panth\MagePos\Api\Data\PaymentMethodInterface;
use Panth\MagePos\Model\Config\Source\MethodType;
use PHPUnit\Framework\TestCase;

class MethodTypeTest extends TestCase
{
    public function testOffersTheThreeSupportedMethodTypesInOrder(): void
    {
        $options = (new MethodType())->toOptionArray();

        $this->assertSame(
            [PaymentMethodInterface::TYPE_CASH, PaymentMethodInterface::TYPE_OFFLINE, PaymentMethodInterface::TYPE_ONLINE],
            array_column($options, 'value')
        );
        foreach ($options as $option) {
            $this->assertNotSame('', (string) $option['label']);
        }
        $this->assertStringStartsWith('Cash', (string) $options[0]['label']);
    }
}
