<?php
declare(strict_types=1);

namespace Panth\MagePos\Helper;

use Panth\Core\Helper\AbstractConfig;

class Config extends AbstractConfig
{
    private const XML_PATH = 'panth_pos';

    protected function getConfigValue(string $group, string $field, $storeId = null)
    {
        return $this->getConfig(self::XML_PATH . '/' . $group . '/' . $field, $storeId);
    }

    public function isEnabled($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/general/enabled', $storeId);
    }

    public function getIdleLockMinutes($storeId = null): int
    {
        $raw = $this->getConfigValue('general', 'idle_lock_minutes', $storeId);
        if ($raw === null || $raw === '') {
            return 5;
        }
        return max(0, (int)$raw);
    }

    public function getDefaultRegisterId($storeId = null): ?int
    {
        $raw = $this->getConfigValue('general', 'default_register', $storeId);
        if ($raw === null || $raw === '' || (int)$raw <= 0) {
            return null;
        }
        return (int)$raw;
    }

    public function getBarcodeAttribute($storeId = null): string
    {
        $value = trim((string)$this->getConfigValue('catalog', 'barcode_attribute', $storeId));
        return $value !== '' ? $value : 'sku';
    }

    public function getSearchPageSize($storeId = null): int
    {
        $size = (int)$this->getConfigValue('catalog', 'search_page_size', $storeId);
        return $size > 0 ? $size : 20;
    }

    public function isShowOutOfStock($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/catalog/show_out_of_stock', $storeId);
    }

    public function getCatalogCacheLimit($storeId = null): int
    {
        $limit = (int)$this->getConfigValue('catalog', 'offline_catalog_limit', $storeId);
        return $limit > 0 ? $limit : 2000;
    }

    public function getGuestEmail($storeId = null): string
    {
        $value = trim((string)$this->getConfigValue('customer', 'guest_email', $storeId));
        return $value !== '' ? $value : 'pos-guest@example.com';
    }

    public function getDefaultCustomerGroupId($storeId = null): int
    {
        $raw = $this->getConfigValue('customer', 'default_customer_group', $storeId);
        if ($raw === null || $raw === '') {
            return 1;
        }
        return max(0, (int)$raw);
    }

    public function getCustomProductSku($storeId = null): string
    {
        $value = trim((string)$this->getConfigValue('custom_product', 'sku', $storeId));
        return $value !== '' ? $value : 'pos-custom-sale';
    }

    public function getCustomProductDefaultTaxClassId($storeId = null): int
    {
        $raw = $this->getConfigValue('custom_product', 'default_tax_class', $storeId);
        if ($raw === null || $raw === '') {
            return 2;
        }
        return max(0, (int)$raw);
    }

    public function getOrderNotePrefix($storeId = null): string
    {
        $raw = $this->getConfigValue('checkout', 'order_note_prefix', $storeId);
        if ($raw === null || $raw === '') {
            return 'POS';
        }
        return (string)$raw;
    }

    public function isAutoInvoiceOffline($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/checkout/auto_invoice_offline', $storeId);
    }

    public function isSessionRequired($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/checkout/require_session', $storeId);
    }

    public function getReceiptLogo($storeId = null): ?string
    {
        $value = trim((string)$this->getConfigValue('receipt', 'logo', $storeId));
        return $value !== '' ? $value : null;
    }

    public function getReceiptHeader($storeId = null): string
    {
        return (string)$this->getConfigValue('receipt', 'header', $storeId);
    }

    public function getReceiptFooter($storeId = null): string
    {
        $raw = $this->getConfigValue('receipt', 'footer', $storeId);
        if ($raw === null || $raw === '') {
            return 'Thank you for your purchase!';
        }
        return (string)$raw;
    }

    public function isShowTaxBreakdown($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/receipt/show_tax_breakdown', $storeId);
    }

    public function isAutoEmailReceipt($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/receipt/auto_email', $storeId);
    }

    public function isOfflineModeEnabled($storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH . '/offline/enabled', $storeId);
    }
}
