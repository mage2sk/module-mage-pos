<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Customer;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CustomerService;

class Create extends AbstractPosController implements HttpPostActionInterface
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
        if ($invalid = $this->checkFormKey()) {
            return $invalid;
        }
        if ($unauthorized = $this->checkAuthenticated()) {
            return $unauthorized;
        }

        $data = $this->getPostData();

        try {
            $customer = $this->customerService->create([
                'firstname' => (string)($data['firstname'] ?? ''),
                'lastname' => (string)($data['lastname'] ?? ''),
                'email' => (string)($data['email'] ?? ''),
                'phone' => (string)($data['phone'] ?? ''),
            ]);
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        } catch (\Exception $e) {
            return $this->jsonError((string)__('Could not create the customer: %1', $e->getMessage()));
        }

        return $this->jsonSuccess($customer, (string)__('Customer created.'));
    }
}
