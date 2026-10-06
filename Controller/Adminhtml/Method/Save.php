<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Method;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Ui\Component\Form\DataProvider\MethodFormDataProvider;
use Panth\MagePos\Api\Data\PaymentMethodInterface;
use Panth\MagePos\Api\PaymentMethodRepositoryInterface;
use Panth\MagePos\Model\PaymentMethodFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::methods';

    private const ALLOWED_TYPES = [
        PaymentMethodInterface::TYPE_CASH,
        PaymentMethodInterface::TYPE_OFFLINE,
        PaymentMethodInterface::TYPE_ONLINE,
    ];

    public function __construct(
        Context $context,
        private readonly PaymentMethodRepositoryInterface $methodRepository,
        private readonly PaymentMethodFactory $methodFactory,
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

        $id = (int) ($data[PaymentMethodInterface::METHOD_ID] ?? 0);

        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $title = trim((string) ($data['title'] ?? ''));
        $type = (string) ($data['type'] ?? '');

        if ($code === '' || !preg_match('/^[a-z0-9][a-z0-9_\-]*$/', $code)) {
            $this->messageManager->addErrorMessage(
                __('Method code is required and may only contain letters, numbers, underscores and dashes.')
            );
            return $this->redirectBack($resultRedirect, $id);
        }
        if ($title === '') {
            $this->messageManager->addErrorMessage(__('Method title is required.'));
            return $this->redirectBack($resultRedirect, $id);
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $this->messageManager->addErrorMessage(__('Invalid method type. Allowed: cash, offline, online.'));
            return $this->redirectBack($resultRedirect, $id);
        }

        try {
            if ($id > 0) {
                $method = $this->methodRepository->getById($id);
            } else {
                $method = $this->methodFactory->create();
            }

            $method->setCode($code);
            $method->setTitle($title);
            $method->setType($type);
            $method->setIsActive((int) ($data['is_active'] ?? 1));
            $method->setSortOrder((int) ($data['sort_order'] ?? 0));
            $method->setIcon($this->nullableString($data['icon'] ?? null));
            $method->setRequiresReference((int) ($data['requires_reference'] ?? 0));
            $method->setInstructions($this->nullableString($data['instructions'] ?? null));
            $method->setOpenDrawer((int) ($data['open_drawer'] ?? 0));
            $method->setPaymentUrlTemplate($this->nullableString($data['payment_url_template'] ?? null));

            $saved = $this->methodRepository->save($method);
            $this->dataPersistor->clear(MethodFormDataProvider::PERSIST_KEY);
            $this->messageManager->addSuccessMessage(__('The POS payment method has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $saved->getMethodId()]);
            }
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This POS payment method no longer exists.'));
            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectBack($resultRedirect, $id);
        }

        return $resultRedirect->setPath('*/*/');
    }

    private function redirectBack(Redirect $resultRedirect, int $id): Redirect
    {
        $this->dataPersistor->set(MethodFormDataProvider::PERSIST_KEY, (array) $this->getRequest()->getPostValue());
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
