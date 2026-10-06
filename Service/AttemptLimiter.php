<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\App\CacheInterface;

class AttemptLimiter
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

    private const CACHE_PREFIX = 'panth_pos_attempts_';

    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function isBlocked(string $scope, string $subject, int $maxAttempts = self::MAX_ATTEMPTS): bool
    {
        return $this->getFailures($scope, $subject) >= $maxAttempts;
    }

    public function registerFailure(string $scope, string $subject): int
    {
        $failures = $this->getFailures($scope, $subject) + 1;
        $this->cache->save(
            (string)$failures,
            $this->buildKey($scope, $subject),
            [],
            self::LOCKOUT_SECONDS
        );

        return $failures;
    }

    public function reset(string $scope, string $subject): void
    {
        $this->cache->remove($this->buildKey($scope, $subject));
    }

    public function getLockoutMinutes(): int
    {
        return (int)ceil(self::LOCKOUT_SECONDS / 60);
    }

    private function getFailures(string $scope, string $subject): int
    {
        $value = $this->cache->load($this->buildKey($scope, $subject));

        return is_string($value) && ctype_digit($value) ? (int)$value : 0;
    }

    private function buildKey(string $scope, string $subject): string
    {
        return self::CACHE_PREFIX . $scope . '_' . hash('sha256', strtolower(trim($subject)));
    }
}
