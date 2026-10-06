<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Role;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Ui\Component\Form\DataProvider\RoleFormDataProvider;
use Panth\MagePos\Api\Data\RoleInterface;
use Panth\MagePos\Api\RoleRepositoryInterface;
use Panth\MagePos\Model\RoleFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::roles';

    private const BOOLEAN_PERMISSION_KEYS = [
        'can_price_override',
        'can_refund',
        'can_open_close',
        'can_cash_inout',
        'can_custom_product',
        'can_edit_layout',
        'can_view_reports',
    ];

    public function __construct(
        Context $context,
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly RoleFactory $roleFactory,
        private readonly DataPersistorInterface $dataPersistor
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

        $id = (int) ($data[RoleInterface::ROLE_ID] ?? 0);

        try {
            $role = $this->loadOrCreate($id);

            $name = trim((string) ($data[RoleInterface::NAME] ?? ''));
            if ($name === '') {
                throw new LocalizedException(__('Role name is required.'));
            }

            $role->setName(mb_substr($name, 0, 128));
            $role->setPermissions($this->buildPermissionsJson($data));

            $role = $this->roleRepository->save($role);
            $this->dataPersistor->clear(RoleFormDataProvider::PERSIST_KEY);
            $this->messageManager->addSuccessMessage(__('The POS role has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $role->getRoleId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Something went wrong while saving the POS role: %1', $e->getMessage())
            );
        }

        $persisted = $data;
        $this->dataPersistor->set(RoleFormDataProvider::PERSIST_KEY, $persisted);

        return $id > 0
            ? $resultRedirect->setPath('*/*/edit', ['id' => $id])
            : $resultRedirect->setPath('*/*/new');
    }

    private function buildPermissionsJson(array $data): string
    {
        $maxDiscount = (int) ($data['max_discount_percent'] ?? 0);
        $maxDiscount = max(0, min(100, $maxDiscount));

        $permissions = ['max_discount_percent' => $maxDiscount];
        foreach (self::BOOLEAN_PERMISSION_KEYS as $key) {
            $permissions[$key] = (bool) (int) ($data[$key] ?? 0);
        }

        $encoded = json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : '{}';
    }

    private function loadOrCreate(int $id): RoleInterface
    {
        if ($id > 0) {
            return $this->roleRepository->getById($id);
        }

        return $this->roleFactory->create();
    }
}
