<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Customer;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CustomerService;

class Search extends AbstractPosController implements HttpGetActionInterface
{
    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly CustomerService $customerService
    ) {
        parent::__construct($jsonFactory, $formKeyValidator, $authService, $request, $config);
    }

    public function execute(): Json
    {
        if ($disabled = $this->checkEnabled()) {
            return $disabled;
        }
        if ($unauthorized = $this->checkAuthenticated()) {
            return $unauthorized;
        }

        $query = (string)$this->request->getParam('q', '');

        try {
            $customers = $this->customerService->search($query);
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        }

        return $this->jsonSuccess($customers);
    }
}
