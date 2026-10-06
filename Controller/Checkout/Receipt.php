<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\ReceiptService;
use Psr\Log\LoggerInterface;

class Receipt implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly ReceiptService $receiptService,
        private readonly AuthService $authService,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);

        if (!$this->config->isEnabled()) {
            return $this->errorPage($result, 404, (string)__('POS is disabled.'));
        }

        $orderId = (int)$this->request->getParam('order_id');
        if ($orderId <= 0) {
            return $this->errorPage($result, 400, (string)__('Missing or invalid order_id.'));
        }

        try {
            $data = $this->receiptService->getReceiptData($orderId);
        } catch (NoSuchEntityException $e) {
            return $this->errorPage($result, 404, (string)__('Receipt not found.'));
        } catch (\Throwable $e) {
            $this->logger->error('[PanthMagePos] receipt render failed: ' . $e->getMessage());
            return $this->errorPage($result, 500, (string)__('Unable to render the receipt.'));
        }

        if (!$this->isAuthorized((string)($data['receipt_token'] ?? ''), (int)($data['pos_user_id'] ?? 0))) {
            return $this->errorPage($result, 403, (string)__('You are not allowed to view this receipt.'));
        }

        try {
            $result->setContents($this->receiptService->renderHtml($orderId));
        } catch (\Throwable $e) {
            $this->logger->error('[PanthMagePos] receipt render failed: ' . $e->getMessage());
            return $this->errorPage($result, 500, (string)__('Unable to render the receipt.'));
        }
        return $result;
    }

    private function isAuthorized(string $receiptToken, int $ownerUserId): bool
    {
        $token = trim((string)$this->request->getParam('token'));
        if ($token !== '' && $receiptToken !== '' && hash_equals($receiptToken, $token)) {
            return true;
        }
        try {
            $user = $this->authService->getCurrentUser();
            if ($user === null || $this->authService->isLocked()) {
                return false;
            }
            return $ownerUserId > 0 && (int)$user->getUserId() === $ownerUserId;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function errorPage(Raw $result, int $httpCode, string $message): Raw
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string)__('Receipt'), ENT_QUOTES, 'UTF-8');
        $result->setHttpResponseCode($httpCode);
        $result->setContents(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $title . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f3f4f6;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . 'p{background:#fff;border-radius:8px;padding:24px 32px;color:#374151;'
            . 'box-shadow:0 1px 4px rgba(0,0,0,.12)}</style></head>'
            . '<body><p>' . $safeMessage . '</p></body></html>'
        );
        return $result;
    }
}
