<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class RolePermissions extends Column
{
    private const LABELS = [
        'can_price_override' => 'Override prices',
        'can_refund' => 'Refunds',
        'can_open_close' => 'Open / close sessions',
        'can_cash_inout' => 'Cash in / out',
        'can_custom_product' => 'Custom products',
        'can_edit_layout' => 'Edit layout',
        'can_view_reports' => 'View reports',
    ];

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Json $json,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = (string) $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $item[$name] = $this->summarize((string) ($item[$name] ?? ''));
        }
        unset($item);
        return $dataSource;
    }

    private function summarize(string $raw): string
    {
        $perms = [];
        if ($raw !== '') {
            try {
                $decoded = $this->json->unserialize($raw);
                $perms = is_array($decoded) ? $decoded : [];
            } catch (\InvalidArgumentException $e) {
                $perms = [];
            }
        }
        $allowed = [];
        foreach (self::LABELS as $key => $label) {
            if (!empty($perms[$key])) {
                $allowed[] = (string) __($label);
            }
        }
        $discount = isset($perms['max_discount_percent']) ? (float) $perms['max_discount_percent'] : 0.0;
        $lines = [];
        $lines[] = '<strong>' . $this->escaper->escapeHtml(
            (string) __('Max discount: %1%', rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.'))
        ) . '</strong>';
        $lines[] = $this->escaper->escapeHtml(
            (string) __('%1 of %2 permissions', count($allowed), count(self::LABELS))
            . ($allowed ? ': ' . implode(', ', $allowed) : '')
        );
        return implode('<br>', $lines);
    }
}
