<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Auth;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Panth\MagePos\Controller\AbstractPosController;

class Logout extends AbstractPosController implements HttpPostActionInterface
{
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

        $this->authService->logout();

        return $this->jsonSuccess(null, (string)__('You have been signed out.'));
    }
}
