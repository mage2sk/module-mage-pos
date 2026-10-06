<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Form\DataProvider;

trait PersistedFormDataTrait
{
    private function mergePersistedData(string $persistKey): void
    {
        $persisted = $this->dataPersistor->get($persistKey);
        if (!is_array($persisted) || $persisted === []) {
            return;
        }
        $this->dataPersistor->clear($persistKey);
        unset($persisted['form_key'], $persisted['key'], $persisted['back']);
        $id = (int) ($persisted[$this->primaryFieldName] ?? 0);
        $rowKey = $id > 0 ? $id : '';
        $this->loadedData[$rowKey] = array_merge($this->loadedData[$rowKey] ?? [], $persisted);
    }
}
