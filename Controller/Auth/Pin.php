<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Auth;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AttemptLimiter;
use Panth\MagePos\Service\AuthService;

class Pin extends AbstractPosController implements HttpPostActionInterface
{
    public const CODE_INVALID_PIN = 'invalid_pin';

    private const SCOPE_PIN = 'pin_user';

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly AttemptLimiter $attemptLimiter
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

        $current = $this->authService->getCurrentUser();
        $subject = $current !== null ? (string)$current->getUserId() : '';
        if ($this->attemptLimiter->isBlocked(self::SCOPE_PIN, $subject)) {
            return $this->signOutAfterTooManyAttempts($subject);
        }

        $username = (string)$this->getRequestValue('username', '');
        $pin = (string)$this->getRequestValue('pin', '');

        try {
            $user = $this->authService->pinUnlock($username, $pin);
        } catch (LocalizedException $e) {
            $failures = $this->attemptLimiter->registerFailure(self::SCOPE_PIN, $subject);
            if ($failures >= AttemptLimiter::MAX_ATTEMPTS) {
                return $this->signOutAfterTooManyAttempts($subject);
            }

            return $this->jsonError($e->getMessage(), self::CODE_INVALID_PIN);
        }

        $this->attemptLimiter->reset(self::SCOPE_PIN, $subject);

        return $this->jsonSuccess($user);
    }

    private function signOutAfterTooManyAttempts(string $subject): Json
    {
        $this->attemptLimiter->reset(self::SCOPE_PIN, $subject);
        $this->authService->logout();

        return $this->jsonError(
            (string)__('Too many incorrect PIN attempts. Please sign in with your username and password.'),
            self::CODE_INVALID_PIN
        );
    }
}
