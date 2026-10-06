<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Quickkey;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Ui\Component\Form\DataProvider\QuickkeyFormDataProvider;
use Panth\MagePos\Api\Data\QuickKeyInterface;
use Panth\MagePos\Api\QuickKeyRepositoryInterface;
use Panth\MagePos\Model\QuickKeyFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::quickkeys';

    public function __construct(
        Context $context,
        private readonly QuickKeyRepositoryInterface $quickKeyRepository,
        private readonly QuickKeyFactory $quickKeyFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $data = (array) $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int) ($data[QuickKeyInterface::QUICK_KEY_ID] ?? 0);

        $productId = (int) ($data['product_id'] ?? 0);
        if ($productId <= 0) {
            $this->messageManager->addErrorMessage(__('A numeric product ID greater than zero is required.'));
            return $this->redirectBack($resultRedirect, $id);
        }

        try {
            $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Product ID %1 does not exist.', $productId));
            return $this->redirectBack($resultRedirect, $id);
        }
        $color = trim((string) ($data['color'] ?? ''));
        if ($color !== '' && !preg_match('/^#[0-9a-f]{3,8}$/i', $color)) {
            $this->messageManager->addErrorMessage(__('The colour must be a hex value such as #2563eb.'));
            return $this->redirectBack($resultRedirect, $id);
        }
        $registerId = trim((string) ($data['register_id'] ?? ''));
        $page = (int) ($data['page'] ?? 1);

        try {
            if ($id > 0) {
                $quickKey = $this->quickKeyRepository->getById($id);
            } else {
                $quickKey = $this->quickKeyFactory->create();
            }

            $quickKey->setRegisterId($registerId === '' || (int) $registerId <= 0 ? null : (int) $registerId);
            $quickKey->setProductId($productId);
            $quickKey->setLabel($this->nullableString($data['label'] ?? null));
            $quickKey->setColor($this->nullableString($data['color'] ?? null));
            $quickKey->setPosition((int) ($data['position'] ?? 0));
            $quickKey->setPage($page > 0 ? $page : 1);

            $saved = $this->quickKeyRepository->save($quickKey);
            $this->dataPersistor->clear(QuickkeyFormDataProvider::PERSIST_KEY);
            $this->messageManager->addSuccessMessage(__('The POS quick key has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $saved->getQuickKeyId()]);
            }
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This POS quick key no longer exists.'));
            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectBack($resultRedirect, $id);
        }

        return $resultRedirect->setPath('*/*/');
    }

    private function redirectBack(Redirect $resultRedirect, int $id): Redirect
    {
        $this->dataPersistor->set(QuickkeyFormDataProvider::PERSIST_KEY, (array) $this->getRequest()->getPostValue());
        if ($id > 0) {
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        return $resultRedirect->setPath('*/*/new');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
