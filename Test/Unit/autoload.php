<?php
declare(strict_types=1);

if (!defined('PANTH_MAGEPOS_TEST_AUTOLOAD_SHIM')) {
    define('PANTH_MAGEPOS_TEST_AUTOLOAD_SHIM', true);

    if (!class_exists(\Panth\MagePos\Model\Payment\ProcessorPool::class)) {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Panth\\MagePos\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = dirname(__DIR__, 2) . '/'
                . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}

require_once __DIR__ . '/MagicCallsTrait.php';
