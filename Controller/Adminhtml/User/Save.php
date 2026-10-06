<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\User;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Ui\Component\Form\DataProvider\UserFormDataProvider;
use Panth\MagePos\Api\Data\PosUserInterface;
use Panth\MagePos\Api\PosUserRepositoryInterface;
use Panth\MagePos\Model\PosUserFactory;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::users';

    public function __construct(
        Context $context,
        private readonly PosUserRepositoryInterface $posUserRepository,
        private readonly PosUserFactory $posUserFactory,
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

        $id = (int) ($data[PosUserInterface::USER_ID] ?? 0);

        try {
            $user = $this->loadOrCreate($id);

            $username = trim((string) ($data[PosUserInterface::USERNAME] ?? ''));
            if ($username === '') {
                throw new LocalizedException(__('Username is required.'));
            }

            $user->setUsername(mb_substr($username, 0, 64));
            $user->setName(mb_substr(trim((string) ($data[PosUserInterface::NAME] ?? '')), 0, 255));
            $user->setEmail(mb_substr(trim((string) ($data[PosUserInterface::EMAIL] ?? '')), 0, 255));

            $roleId = $data[PosUserInterface::ROLE_ID] ?? null;
            $user->setRoleId($roleId === null || $roleId === '' ? null : (int) $roleId);
            $user->setStatus((int) ($data[PosUserInterface::STATUS] ?? 1));

            $password = (string) ($data['password'] ?? '');
            if ($password !== '') {
                $user->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
            } elseif ($user->getUserId() === null) {
                throw new LocalizedException(__('A password is required for a new POS user.'));
            }

            $pin = (string) ($data['pin'] ?? '');
            if ($pin !== '') {
                if (!preg_match('/^\d{4,8}$/', $pin)) {
                    throw new LocalizedException(__('The PIN must be 4 to 8 digits.'));
                }
                $user->setPinHash(password_hash($pin, PASSWORD_DEFAULT));
            }

            $user = $this->posUserRepository->save($user);
            $this->dataPersistor->clear(UserFormDataProvider::PERSIST_KEY);
            $this->messageManager->addSuccessMessage(__('The POS user has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['id' => $user->getUserId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Something went wrong while saving the POS user: %1', $e->getMessage())
            );
        }

        $persisted = $data;
        unset($persisted['password'], $persisted['pin'], $persisted['password_hash'], $persisted['pin_hash']);
        $this->dataPersistor->set(UserFormDataProvider::PERSIST_KEY, $persisted);

        return $id > 0
            ? $resultRedirect->setPath('*/*/edit', ['id' => $id])
            : $resultRedirect->setPath('*/*/new');
    }

    private function loadOrCreate(int $id): PosUserInterface
    {
        if ($id > 0) {
            return $this->posUserRepository->getById($id);
        }

        return $this->posUserFactory->create();
    }
}
