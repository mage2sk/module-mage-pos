<?php
declare(strict_types=1);

namespace Panth\MagePos\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Panth\MagePos\Api\Data\SessionInterface;

class SessionActions extends Column
{
    private const URL_PATH_VIEW = 'panth_pos/session/view';
    private const URL_PATH_FORCE_CLOSE = 'panth_pos/session/forceclose';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item[SessionInterface::SESSION_ID])) {
                continue;
            }
            $id = (int)$item[SessionInterface::SESSION_ID];

            $actions = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_PATH_VIEW, ['session_id' => $id]),
                    'label' => __('View'),
                ],
            ];

            if (($item[SessionInterface::STATUS] ?? '') === SessionInterface::STATUS_OPEN) {
                $actions['forceclose'] = [
                    'href' => $this->urlBuilder->getUrl(self::URL_PATH_FORCE_CLOSE, ['session_id' => $id]),
                    'label' => __('Force Close'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Force Close Session'),
                        'message' => __(
                            'Are you sure you want to force close session #%1? '
                            . 'It will be closed with counted cash equal to expected cash.',
                            $id
                        ),
                    ],
                ];
            }

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
