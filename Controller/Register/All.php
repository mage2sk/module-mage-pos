<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Register;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Panth\MagePos\Api\Data\RegisterInterface;
use Panth\MagePos\Api\RegisterRepositoryInterface;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Psr\Log\LoggerInterface;

class All extends AbstractPosController implements HttpGetActionInterface
{
    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly RegisterRepositoryInterface $registerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
    }

    public function execute()
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        if ($unauthorized = $this->checkAuthenticated()) {
            return $unauthorized;
        }
        try {
            $sortOrder = $this->sortOrderBuilder
                ->setField(RegisterInterface::NAME)
                ->setAscendingDirection()
                ->create();
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter(RegisterInterface::STATUS, 1)
                ->addSortOrder($sortOrder)
                ->create();

            $registers = [];
            foreach ($this->registerRepository->getList($searchCriteria)->getItems() as $register) {
                if (!$register instanceof RegisterInterface) {
                    continue;
                }
                $registers[] = [
                    'id' => (int)$register->getRegisterId(),
                    'name' => $register->getName(),
                    'code' => $register->getCode(),
                    'store_id' => (int)$register->getStoreId(),
                ];
            }

            return $this->jsonSuccess($registers);
        } catch (\Throwable $e) {
            $this->logger->error('[Panth_MagePos] register/all failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->jsonError((string)__('Unable to load registers.'));
        }
    }
}
