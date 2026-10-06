<?php
declare(strict_types=1);

namespace Panth\MagePos\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\CouldNotSaveException;
use Panth\MagePos\Api\Data\UserPreferenceInterface;
use Panth\MagePos\Api\UserPreferenceRepositoryInterface;
use Panth\MagePos\Model\UserPreferenceFactory;

class PreferenceService
{
    public function __construct(
        private readonly UserPreferenceRepositoryInterface $userPreferenceRepository,
        private readonly UserPreferenceFactory $userPreferenceFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function get(int $userId): array
    {
        $preference = $this->findByUserId($userId);
        if ($preference === null) {
            return ['layout' => null, 'theme' => null];
        }

        return [
            'layout' => $this->decode($preference->getLayoutJson()),
            'theme' => $this->decode($preference->getThemeJson()),
        ];
    }

    public function save(int $userId, ?array $layout, ?array $theme): void
    {
        if ($layout === null && $theme === null) {
            return;
        }

        $preference = $this->findByUserId($userId);
        if ($preference === null) {
            $preference = $this->userPreferenceFactory->create();
            $preference->setUserId($userId);
        }

        if ($layout !== null) {
            $preference->setLayoutJson($this->encode($layout));
        }
        if ($theme !== null) {
            $preference->setThemeJson($this->encode($theme));
        }

        $this->userPreferenceRepository->save($preference);
    }

    private function findByUserId(int $userId): ?UserPreferenceInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(UserPreferenceInterface::USER_ID, $userId)
            ->setPageSize(1)
            ->create();

        foreach ($this->userPreferenceRepository->getList($searchCriteria)->getItems() as $item) {
            if ($item instanceof UserPreferenceInterface) {
                return $item;
            }
        }

        return null;
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new CouldNotSaveException(__('Could not encode the POS preference payload.'));
        }

        return $json;
    }

    private function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
