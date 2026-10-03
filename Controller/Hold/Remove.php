<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Hold;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\HoldService;

class Remove extends AbstractPosController implements HttpPostActionInterface
{
    public const CODE_NOT_FOUND = 'not_found';

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly HoldService $holdService
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

        $holdId = (int)$this->getRequestValue('hold_id', 0);
        if ($holdId <= 0) {
            return $this->jsonError((string)__('hold_id is required.'));
        }

        try {
            $this->holdService->delete($holdId);
        } catch (NoSuchEntityException $e) {
            return $this->jsonError((string)__('This hold no longer exists.'), self::CODE_NOT_FOUND);
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage());
        }

        return $this->jsonSuccess(['hold_id' => $holdId], (string)__('Hold removed.'));
    }
}
