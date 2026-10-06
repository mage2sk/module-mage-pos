<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Model;

require_once __DIR__ . '/../autoload.php';

use Panth\MagePos\Model\NameDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameDecoderTest extends TestCase
{
    public static function nameProvider(): array
    {
        return [
            'named entity' => ['Pursuit Lumaflex&trade; Tone Band', "Pursuit Lumaflex\u{2122} Tone Band"],
            'numeric entity' => ['Quest Lumaflex&#8482; Band', "Quest Lumaflex\u{2122} Band"],
            'hex entity' => ['Caf&#xE9; Mug', "Caf\u{E9} Mug"],
            'quotes' => ['&quot;Zing&quot; &amp; &#039;Go&#039;', '"Zing" & \'Go\''],
            'plain text unchanged' => ['Affirm Water Bottle', 'Affirm Water Bottle'],
            'bare ampersand kept' => ['Salt & Pepper', 'Salt & Pepper'],
            'empty' => ['', ''],
            'decoded once only' => ['Lumaflex&amp;trade;', 'Lumaflex&trade;'],
        ];
    }

    #[DataProvider('nameProvider')]
    public function testDecodesEntitiesOnce(string $stored, string $expected): void
    {
        $this->assertSame($expected, NameDecoder::decode($stored));
    }

    public function testNonScalarValuesBecomeEmptyStrings(): void
    {
        $this->assertSame('', NameDecoder::decode(null));
        $this->assertSame('', NameDecoder::decode(['x']));
        $this->assertSame('42', NameDecoder::decode(42));
    }

    public function testDecodedMarkupIsReturnedAsPlainTextForEscapingAtOutput(): void
    {
        $decoded = NameDecoder::decode('&lt;script&gt;alert(1)&lt;/script&gt; Band');

        $this->assertSame('<script>alert(1)</script> Band', $decoded);
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt; Band',
            htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
        );
    }
}
