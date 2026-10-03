<?php
declare(strict_types=1);

namespace Panth\MagePos\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class ReplaceEmojiPaymentIcons implements DataPatchInterface
{
    private const LEGACY_EMOJI_ICONS = ['💵', '💳', '🔗'];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public static function getDependencies(): array
    {
        return [CreateDefaultData::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('panth_pos_payment_method');

        $connection->update(
            $table,
            ['icon' => null],
            ['icon IN (?)' => self::LEGACY_EMOJI_ICONS]
        );

        return $this;
    }
}
