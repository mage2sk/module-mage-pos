<?php
declare(strict_types=1);

namespace Panth\MagePos\Block;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\Store;
use Panth\MagePos\Helper\Config;

class Terminal extends Template
{
    private const VERSION = '1.0.0';

    public function __construct(
        Template\Context $context,
        private readonly Config $config,
        private readonly FormKey $formKey,
        private readonly CurrencyInterface $localeCurrency,
        private readonly Json $serializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
        if (!$this->getTemplate()) {
            $this->setTemplate('Panth_MagePos::terminal.phtml');
        }
    }

    public function getApiBaseUrl(): string
    {
        return rtrim($this->getUrl('pos'), '/') . '/';
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getCurrencySymbol(): string
    {
        $currencyCode = $this->getCurrencyCode();
        if ($currencyCode === '') {
            return '';
        }

        try {
            $symbol = $this->localeCurrency->getCurrency($currencyCode)->getSymbol();
            if (is_string($symbol) && $symbol !== '') {
                return $symbol;
            }
        } catch (\Exception $e) {
        }

        return $currencyCode;
    }

    public function getCurrencyCode(): string
    {
        try {
            $store = $this->_storeManager->getStore();
            if ($store instanceof Store) {
                return (string)$store->getCurrentCurrency()->getCode();
            }
        } catch (\Exception $e) {
        }

        return '';
    }

    public function getStoreName(): string
    {
        try {
            $store = $this->_storeManager->getStore();
            if ($store instanceof Store) {
                $frontendName = (string)$store->getFrontendName();
                if ($frontendName !== '') {
                    return $frontendName;
                }
            }

            return (string)$store->getName();
        } catch (\Exception $e) {
            return '';
        }
    }

    public function getConfigJson(): string
    {
        $storeId = null;
        try {
            $storeId = (int)$this->_storeManager->getStore()->getId();
        } catch (\Exception $e) {
        }

        $config = [
            'api_base_url' => $this->getApiBaseUrl(),
            'form_key' => $this->getFormKey(),
            'currency_symbol' => $this->getCurrencySymbol(),
            'currency_code' => $this->getCurrencyCode(),
            'store_name' => $this->getStoreName(),
            'store_id' => $storeId,
            'idle_lock_minutes' => $this->config->getIdleLockMinutes($storeId),
            'offline_enabled' => $this->config->isOfflineModeEnabled($storeId),
            'version' => self::VERSION,
            'i18n' => $this->getI18n(),
        ];

        return (string)$this->serializer->serialize($config);
    }

    private function getI18n(): array
    {
        return [

            'ok' => (string)__('OK'),
            'cancel' => (string)__('Cancel'),
            'close' => (string)__('Close'),
            'confirm' => (string)__('Confirm'),
            'yes' => (string)__('Yes'),
            'no' => (string)__('No'),
            'save' => (string)__('Save'),
            'saved' => (string)__('Saved.'),
            'delete' => (string)__('Delete'),
            'remove' => (string)__('Remove'),
            'edit' => (string)__('Edit'),
            'apply' => (string)__('Apply'),
            'clear' => (string)__('Clear'),
            'back' => (string)__('Back'),
            'enter' => (string)__('Enter'),
            'search' => (string)__('Search'),
            'loading' => (string)__('Loading...'),
            'error' => (string)__('Something went wrong. Please try again.'),
            'network_error' => (string)__('Network error - check your connection.'),
            'invalid_response' => (string)__('Invalid server response.'),
            'no_permission' => (string)__('You do not have permission for this action.'),
            'unauthorized' => (string)__('Your session has expired. Please sign in again.'),
            'terminal_locked' => (string)__('The terminal is locked. Enter your PIN to continue.'),

            'sign_in' => (string)__('Sign in'),
            'signing_in' => (string)__('Signing in...'),
            'signed_in' => (string)__('Welcome back, %1!'),
            'signed_out' => (string)__('You have been signed out.'),
            'enter_credentials' => (string)__('Please enter your username and password.'),
            'login_failed' => (string)__('Sign-in failed. Check your username and password.'),
            'username' => (string)__('Username'),
            'password' => (string)__('Password'),
            'pin' => (string)__('PIN'),
            'unlock' => (string)__('Unlock'),
            'pin_failed' => (string)__('Wrong PIN. Try again.'),
            'locked' => (string)__('Terminal locked'),
            'lock' => (string)__('Lock'),
            'logout' => (string)__('Log out'),

            'online' => (string)__('Online'),
            'offline' => (string)__('Offline'),
            'offline_mode' => (string)__('Working offline - orders will be queued and synced.'),
            'back_online' => (string)__('Back online.'),
            'sync_pending' => (string)__('%1 queued order(s) waiting to sync.'),
            'synced' => (string)__('Offline orders synced.'),
            'queued_offline' => (string)__('Order queued offline - it will sync automatically.'),

            'register' => (string)__('Register'),
            'select_register' => (string)__('Select a register...'),
            'no_registers' => (string)__('No active registers are configured. Ask an administrator.'),
            'session' => (string)__('Session'),
            'session_open' => (string)__('Session open'),
            'session_closed' => (string)__('Session closed'),
            'no_session' => (string)__('No open session'),
            'open_session' => (string)__('Open session'),
            'close_session' => (string)__('Close session'),
            'opening_float' => (string)__('Opening float'),
            'counted_cash' => (string)__('Counted cash'),
            'expected_cash' => (string)__('Expected cash'),
            'over_short' => (string)__('Over / short'),
            'cash_in' => (string)__('Cash in'),
            'cash_out' => (string)__('Cash out'),
            'x_report' => (string)__('X report'),
            'z_report' => (string)__('Z report'),

            'search_products' => (string)__('Search products or scan a barcode...'),
            'product_not_found' => (string)__('Product not found.'),
            'cart' => (string)__('Cart'),
            'new_cart' => (string)__('New cart'),
            'cart_empty' => (string)__('Cart is empty.'),
            'qty' => (string)__('Qty'),
            'price' => (string)__('Price'),
            'subtotal' => (string)__('Subtotal'),
            'discount' => (string)__('Discount'),
            'tax' => (string)__('Tax'),
            'total' => (string)__('Total'),
            'grand_total' => (string)__('Grand total'),
            'coupon' => (string)__('Coupon'),
            'note' => (string)__('Note'),
            'custom_product' => (string)__('Custom product'),
            'price_override' => (string)__('Price override'),
            'max_discount_exceeded' => (string)__('Discount exceeds your allowed maximum of %1%.'),

            'customer' => (string)__('Customer'),
            'guest' => (string)__('Guest'),

            'holds' => (string)__('Holds'),
            'hold_saved' => (string)__('Cart parked.'),
            'hold_restored' => (string)__('Held cart restored.'),
            'restore' => (string)__('Restore'),
            'orders' => (string)__('Orders'),
            'orders_refunds' => (string)__('Orders / Refunds'),
            'refund' => (string)__('Refund'),
            'restock' => (string)__('Restock'),
            'reason' => (string)__('Reason'),

            'pay' => (string)__('Pay'),
            'tendered' => (string)__('Tendered'),
            'change_due' => (string)__('Change due'),
            'place_order' => (string)__('Place order'),
            'order_placed' => (string)__('Order %1 placed.'),
            'payment_incomplete' => (string)__('Payments do not cover the grand total yet.'),
            'reference' => (string)__('Reference'),
            'print_receipt' => (string)__('Print receipt'),
            'email_receipt' => (string)__('Email receipt'),
            'receipt_sent' => (string)__('Receipt emailed.'),
            'scan_qr_to_pay' => (string)__('Scan the QR code to pay'),

            'settings' => (string)__('Settings'),
            'layout' => (string)__('Layout'),
            'layout_edit_on' => (string)__('Layout edit mode on - drag panels to rearrange.'),
            'layout_edit_off' => (string)__('Layout edit mode off.'),
            'layout_saved' => (string)__('Layout saved.'),
        ];
    }
}
