<?php
declare(strict_types=1);

namespace Panth\MagePos\Controller\Auth;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Panth\MagePos\Controller\AbstractPosController;

class Lock extends AbstractPosController implements HttpPostActionInterface
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

        $this->authService->lock();

        return $this->jsonSuccess(['locked' => true]);
    }
}
