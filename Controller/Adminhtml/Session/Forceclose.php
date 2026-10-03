<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Adminhtml\Session;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\MagePos\Api\CashMovementRepositoryInterface;
use Panth\MagePos\Api\Data\CashMovementInterface;
use Panth\MagePos\Api\Data\CashMovementInterfaceFactory;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Service\PosSessionService;

class Forceclose extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_MagePos::sessions';

    public function __construct(
        Context $context,
        private readonly SessionRepositoryInterface $sessionRepository,
        private readonly PosSessionService $posSessionService,
        private readonly CashMovementRepositoryInterface $cashMovementRepository,
        private readonly CashMovementInterfaceFactory $cashMovementFactory,
        private readonly DateTime $dateTime
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('*/*/index');

        $sessionId = (int)$this->getRequest()->getParam('session_id');

        try {
            $session = $this->sessionRepository->getById($sessionId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('POS session with id "%1" does not exist.', $sessionId));

            return $resultRedirect;
        }

        if ($session->getStatus() !== SessionInterface::STATUS_OPEN) {
            $this->messageManager->addErrorMessage(__('POS session #%1 is already closed.', $sessionId));

            return $resultRedirect;
        }

        try {
            $expectedCash = $this->posSessionService->expectedCash($sessionId);
            $closedAt = $this->dateTime->gmtDate();

            $report = $this->posSessionService->xReport($sessionId);
            $report['cash']['expected'] = $expectedCash;
            $report['cash']['counted'] = $expectedCash;
            $report['cash']['over_short'] = 0.0;
            $report['closed_at'] = $closedAt;

            $session->setStatus(SessionInterface::STATUS_CLOSED)
                ->setExpectedCash($expectedCash)
                ->setCountedCash($expectedCash)
                ->setOverShort(0.0)
                ->setTotalsJson(json_encode($report))
                ->setClosedAt($closedAt)
                ->setNote(__('Force closed from admin')->render());
            $this->sessionRepository->save($session);

            if ($expectedCash > 0) {
                $movement = $this->cashMovementFactory->create();
                $movement->setSessionId($sessionId)
                    ->setUserId((int)$session->getUserId())
                    ->setType(CashMovementInterface::TYPE_CLOSE)
                    ->setAmount(round(-$expectedCash, 4))
                    ->setReason(__('Drawer emptied at admin force close')->render());
                $this->cashMovementRepository->save($movement);
            }

            $this->messageManager->addSuccessMessage(
                __('POS session #%1 has been force closed (counted = expected).', $sessionId)
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Could not force close POS session #%1: %2', $sessionId, $e->getMessage())
            );
        }

        return $resultRedirect;
    }
}
