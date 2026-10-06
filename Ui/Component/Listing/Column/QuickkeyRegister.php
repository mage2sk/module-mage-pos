<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class QuickkeyRegister extends Column
{
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = (string) $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $value = $item[$name] ?? null;
            if ($value === null || $value === '' || (int) $value === 0) {
                $item[$name] = (string) __('All registers');
            }
        }
        unset($item);
        return $dataSource;
    }
}
