<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Register;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Model\RegisterFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::registers';

    public function __construct(
        Context $context,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly RegisterFactory $registerFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = (array) $this->getRequest()->getPostValue();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int) ($data['register_id'] ?? 0);

        try {
            $register = $this->loadOrCreate($id);
            $this->hydrate($register, $data);
            $register = $this->registerRepository->save($register);
            $id = (int) $register->getRegisterId();

            $this->messageManager->addSuccessMessage(__('The register has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['register_id' => $id]);
            }
            return $resultRedirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while saving the register.')
            );
        }

        if ($id > 0) {
            return $resultRedirect->setPath('*/*/edit', ['register_id' => $id]);
        }
        return $resultRedirect->setPath('*/*/new');
    }

    private function loadOrCreate(int $id): RegisterInterface
    {
        if ($id > 0) {
            return $this->registerRepository->getById($id);
        }
        return $this->registerFactory->create();
    }

    private function hydrate(RegisterInterface $register, array $data): void
    {
        $name = trim((string) ($data['name'] ?? ''));
        $code = trim((string) ($data['code'] ?? ''));
        if ($name === '') {
            throw new LocalizedException(__('The register name is required.'));
        }
        if ($code === '') {
            throw new LocalizedException(__('The register code is required.'));
        }

        $receiptHeader = trim((string) ($data['receipt_header'] ?? ''));
        $receiptFooter = trim((string) ($data['receipt_footer'] ?? ''));

        $register->setName(mb_substr($name, 0, 255));
        $register->setCode(mb_substr($code, 0, 64));
        $register->setStoreId((int) ($data['store_id'] ?? 1));
        $register->setStatus((int) ($data['status'] ?? 1));
        $register->setReceiptHeader($receiptHeader === '' ? null : $receiptHeader);
        $register->setReceiptFooter($receiptFooter === '' ? null : $receiptFooter);

        $sourceCode = trim((string) ($data['source_code'] ?? ''));
        $register->setSourceCode($sourceCode === '' ? null : mb_substr($sourceCode, 0, 255));
    }
}
