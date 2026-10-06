<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Preference;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\PreferenceService;

class Save extends AbstractPosController implements HttpPostActionInterface
{
    public const CODE_FORBIDDEN = 'forbidden';
    public const PERMISSION_EDIT_LAYOUT = 'can_edit_layout';

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly PreferenceService $preferenceService
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

        $layout = $this->normalizePart($this->getRequestValue('layout'));
        $theme = $this->normalizePart($this->getRequestValue('theme'));

        if ($layout === null && $theme === null) {
            return $this->jsonError((string)__('Nothing to save: provide a layout and/or theme object.'));
        }

        try {
            $user = $this->authService->requireUser();
            if ($layout !== null) {
                $this->authService->requirePermission(self::PERMISSION_EDIT_LAYOUT);
            }
            $this->preferenceService->save((int)$user->getUserId(), $layout, $theme);
        } catch (LocalizedException $e) {
            return $this->jsonError($e->getMessage(), $layout !== null ? self::CODE_FORBIDDEN : null);
        }

        return $this->jsonSuccess(
            $this->preferenceService->get((int)$user->getUserId()),
            (string)__('Preferences saved.')
        );
    }

    private function normalizePart(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
