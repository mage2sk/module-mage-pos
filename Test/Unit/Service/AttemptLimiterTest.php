<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Service;

require_once __DIR__ . '/../autoload.php';

use Magento\Framework\App\CacheInterface;
use Panth\MagePos\Service\AttemptLimiter;
use PHPUnit\Framework\TestCase;

class AttemptLimiterTest extends TestCase
{
    private array $store = [];
    private array $lifetimes = [];

    private function makeLimiter(): AttemptLimiter
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->store[$key] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key, array $tags = [], $lifetime = null) {
                $this->store[$key] = $data;
                $this->lifetimes[$key] = $lifetime;
                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(function (string $key) {
            unset($this->store[$key]);
            return true;
        });

        return new AttemptLimiter($cache);
    }

    public function testFreshSubjectIsNotBlocked(): void
    {
        $this->assertFalse($this->makeLimiter()->isBlocked('login', 'alice'));
    }

    public function testFailuresAccumulateUntilBlockedWithLockoutLifetime(): void
    {
        $limiter = $this->makeLimiter();

        for ($i = 1; $i < AttemptLimiter::MAX_ATTEMPTS; $i++) {
            $this->assertSame($i, $limiter->registerFailure('login', 'alice'));
            $this->assertFalse($limiter->isBlocked('login', 'alice'));
        }
        $this->assertSame(AttemptLimiter::MAX_ATTEMPTS, $limiter->registerFailure('login', 'alice'));
        $this->assertTrue($limiter->isBlocked('login', 'alice'));
        $this->assertSame([AttemptLimiter::LOCKOUT_SECONDS], array_values(array_unique($this->lifetimes)));
    }

    public function testSubjectIsNormalisedAndScopesAreIsolated(): void
    {
        $limiter = $this->makeLimiter();
        $limiter->registerFailure('login', '  Alice ');
        $limiter->registerFailure('login', 'alice');

        $this->assertTrue($limiter->isBlocked('login', 'ALICE', 2));
        $this->assertFalse($limiter->isBlocked('pin', 'alice', 1));
        $this->assertCount(1, $this->store);
        $key = array_key_first($this->store);
        $this->assertStringStartsWith('panth_pos_attempts_login_', $key);
        $this->assertStringNotContainsString('alice', $key);
    }

    public function testResetClearsFailures(): void
    {
        $limiter = $this->makeLimiter();
        $limiter->registerFailure('pin', 'bob');
        $limiter->reset('pin', 'BOB');

        $this->assertFalse($limiter->isBlocked('pin', 'bob', 1));
        $this->assertSame([], $this->store);
    }

    public function testCorruptCacheValueCountsAsZero(): void
    {
        $limiter = $this->makeLimiter();
        $limiter->registerFailure('pin', 'bob');
        $key = array_key_first($this->store);
        $this->store[$key] = 'garbage';

        $this->assertFalse($limiter->isBlocked('pin', 'bob', 1));
        $this->assertSame(1, $limiter->registerFailure('pin', 'bob'));
    }

    public function testLockoutMinutesRoundsUp(): void
    {
        $this->assertSame(15, $this->makeLimiter()->getLockoutMinutes());
    }
}
