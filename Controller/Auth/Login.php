<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Auth;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Panth\MagePos\Controller\AbstractPosController;
use Panth\MagePos\Helper\Config;
use Panth\MagePos\Service\AttemptLimiter;
use Panth\MagePos\Service\AuthService;

class Login extends AbstractPosController implements HttpPostActionInterface
{
    public const CODE_INVALID_CREDENTIALS = 'invalid_credentials';

    public const CODE_TOO_MANY_ATTEMPTS = 'too_many_attempts';

    private const SCOPE_USERNAME = 'login_user';

    private const SCOPE_ADDRESS = 'login_ip';

    private const MAX_ATTEMPTS_PER_ADDRESS = 20;

    public function __construct(
        JsonFactory $jsonFactory,
        FormKeyValidator $formKeyValidator,
        AuthService $authService,
        RequestInterface $request,
        Config $config,
        private readonly AttemptLimiter $attemptLimiter,
        private readonly RemoteAddress $remoteAddress
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

        $username = (string)$this->getRequestValue('username', '');
        $password = (string)$this->getRequestValue('password', '');
        $address = (string)$this->remoteAddress->getRemoteAddress();

        if ($this->attemptLimiter->isBlocked(self::SCOPE_USERNAME, $username)
            || $this->attemptLimiter->isBlocked(self::SCOPE_ADDRESS, $address, self::MAX_ATTEMPTS_PER_ADDRESS)
        ) {
            return $this->jsonError(
                (string)__(
                    'Too many failed sign-in attempts. Please try again in %1 minutes.',
                    $this->attemptLimiter->getLockoutMinutes()
                ),
                self::CODE_TOO_MANY_ATTEMPTS,
                429
            );
        }

        try {
            $user = $this->authService->login($username, $password);
        } catch (LocalizedException $e) {
            if (trim($username) !== '') {
                $this->attemptLimiter->registerFailure(self::SCOPE_USERNAME, $username);
                $this->attemptLimiter->registerFailure(self::SCOPE_ADDRESS, $address);
            }

            return $this->jsonError($e->getMessage(), self::CODE_INVALID_CREDENTIALS);
        }

        $this->attemptLimiter->reset(self::SCOPE_USERNAME, $username);

        return $this->jsonSuccess($user, (string)__('Welcome, %1!', $user['name']));
    }
}
