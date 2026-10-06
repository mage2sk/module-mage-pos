<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Controller\Catalog;

require_once __DIR__ . '/../AbstractControllerTestCase.php';

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Session\SessionManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\MagePos\Api\Data\SessionInterface;
use Panth\MagePos\Api\SessionRepositoryInterface;
use Panth\MagePos\Controller\Catalog\Barcode;
use Panth\MagePos\Controller\Catalog\Bestsellers;
use Panth\MagePos\Controller\Catalog\Categories;
use Panth\MagePos\Controller\Catalog\Options;
use Panth\MagePos\Controller\Catalog\Product;
use Panth\MagePos\Controller\Catalog\Products;
use Panth\MagePos\Controller\Catalog\QuickkeyRemove;
use Panth\MagePos\Controller\Catalog\QuickkeySave;
use Panth\MagePos\Controller\Catalog\Quickkeys;
use Panth\MagePos\Controller\Catalog\Search;
use Panth\MagePos\Controller\Catalog\Snapshot;
use Panth\MagePos\Service\AuthService;
use Panth\MagePos\Service\CatalogService;
use Panth\MagePos\Test\Unit\Controller\AbstractControllerTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class CatalogControllersTest extends AbstractControllerTestCase
{
    private CatalogService&MockObject $catalogService;
    private StoreManagerInterface&MockObject $storeManager;
    private SessionManager&MockObject $sessionManager;
    private SessionRepositoryInterface&MockObject $sessionRepository;
    private ?int $posSessionId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->posSessionId = null;
        $this->catalogService = $this->createMock(CatalogService::class);
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(2);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->sessionManager = $this->createMock(SessionManager::class);
        $this->sessionManager->method('getData')->willReturnCallback(
            fn ($key) => $key === AuthService::SESSION_KEY_SESSION_ID ? $this->posSessionId : null
        );
        $this->sessionRepository = $this->createMock(SessionRepositoryInterface::class);
    }

    private function dispatch(string $class): void
    {
        $base = $this->baseArgs();
        $args = match ($class) {
            Barcode::class, Categories::class => [...$base, $this->catalogService, $this->storeManager],
            Snapshot::class => [$base[0], $base[1], $base[2], $base[3], $this->catalogService, $this->storeManager, $base[4]],
            QuickkeyRemove::class, QuickkeySave::class, Quickkeys::class => [
                ...$base, $this->catalogService, $this->sessionManager, $this->sessionRepository,
            ],
            default => [...$base, $this->catalogService, $this->storeManager, $this->sessionManager, $this->sessionRepository],
        };
        (new $class(...$args))->execute();
    }

    private function openSessionOnRegister(int $registerId): void
    {
        $this->posSessionId = 30;
        $session = $this->createStub(SessionInterface::class);
        $session->method('getRegisterId')->willReturn($registerId);
        $this->sessionRepository->method('getById')->with(30)->willReturn($session);
    }

    public static function allControllersProvider(): array
    {
        return [
            [Barcode::class], [Bestsellers::class], [Categories::class], [Options::class], [Product::class],
            [Products::class], [QuickkeyRemove::class], [QuickkeySave::class], [Quickkeys::class], [Search::class],
            [Snapshot::class],
        ];
    }

    #[DataProvider('allControllersProvider')]
    public function testAnonymousRequestsAreRejected(string $class): void
    {
        $this->signOut();

        $this->dispatch($class);

        $this->assertError(self::SESSION_EXPIRED, 'unauthorized');
    }

    #[DataProvider('allControllersProvider')]
    public function testDisabledPosRejectsRequests(string $class): void
    {
        $this->enabled = false;

        $this->dispatch($class);

        $this->assertError('POS is disabled.', 'disabled');
    }

    public function testLockedTerminalCannotReadProductDetail(): void
    {
        $this->signIn();
        $this->authService->method('isLocked')->willReturn(true);
        $this->catalogService->expects($this->never())->method('productDetail');

        $this->dispatch(Product::class);

        $this->assertError(self::TERMINAL_LOCKED, 'unauthorized');
        $this->assertTrue($this->result['locked']);
    }

    public function testBarcodeIsRequired(): void
    {
        $this->signIn();
        $this->params = ['code' => '  '];

        $this->dispatch(Barcode::class);

        $this->assertError('Barcode is required.');
    }

    public function testUnknownBarcodeIsNotFound(): void
    {
        $this->signIn();
        $this->params = ['code' => ' 123 '];
        $this->catalogService->expects($this->once())->method('byBarcode')->with('123', 2)->willReturn(null);

        $this->dispatch(Barcode::class);

        $this->assertError('No product found for barcode "123".', 'not_found');
    }

    public function testBarcodeHit(): void
    {
        $this->signIn();
        $this->params = ['code' => '123'];
        $this->catalogService->method('byBarcode')->willReturn(['id' => 1]);

        $this->dispatch(Barcode::class);

        $this->assertSuccess(['id' => 1]);
    }

    public function testBarcodeUnexpectedFailure(): void
    {
        $this->signIn();
        $this->params = ['code' => '123'];
        $this->catalogService->method('byBarcode')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Barcode::class);

        $this->assertError('Barcode lookup failed. Please try again.');
    }

    public function testBestsellersPassesClampedParamsAndRegister(): void
    {
        $this->signIn();
        $this->openSessionOnRegister(4);
        $this->params = ['limit' => '10', 'page' => '-2', 'group_id' => '-1'];
        $this->catalogService->expects($this->once())->method('bestSellers')->with(2, 10, 0, 4, 1)->willReturn(['items' => []]);

        $this->dispatch(Bestsellers::class);

        $this->assertSuccess(['items' => []]);
    }

    public function testBestsellersWithStaleSessionUsesNoRegister(): void
    {
        $this->signIn();
        $this->posSessionId = 30;
        $this->sessionRepository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));
        $this->catalogService->expects($this->once())->method('bestSellers')->with(2, 24, 0, null, 1)->willReturn([]);

        $this->dispatch(Bestsellers::class);

        $this->assertSuccess([]);
    }

    public function testBestsellersFailure(): void
    {
        $this->signIn();
        $this->catalogService->method('bestSellers')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Bestsellers::class);

        $this->assertError('Could not load top sellers. Please try again.');
    }

    public function testCategoriesUseCurrentStore(): void
    {
        $this->signIn();
        $this->catalogService->expects($this->once())->method('categoryTree')->with(2)->willReturn([['id' => 3]]);

        $this->dispatch(Categories::class);

        $this->assertSuccess([['id' => 3]]);
    }

    public function testCategoriesFailure(): void
    {
        $this->signIn();
        $this->catalogService->method('categoryTree')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Categories::class);

        $this->assertError('Could not load categories. Please try again.');
    }

    public function testOptionsForMissingProduct(): void
    {
        $this->signIn();
        $this->params = ['id' => '5', 'group_id' => '3'];
        $this->catalogService->expects($this->once())->method('productOptions')->with(5, 2, 3, null)->willReturn(null);

        $this->dispatch(Options::class);

        $this->assertError('Product not found.');
    }

    public function testOptionsFound(): void
    {
        $this->signIn();
        $this->openSessionOnRegister(6);
        $this->params = ['id' => '5'];
        $this->catalogService->expects($this->once())->method('productOptions')->with(5, 2, 0, 6)->willReturn(['type' => 'simple']);

        $this->dispatch(Options::class);

        $this->assertSuccess(['type' => 'simple']);
    }

    public function testOptionsErrors(): void
    {
        $this->signIn();
        $this->catalogService->method('productOptions')->willThrowException($this->localized('Bad product.'));

        $this->dispatch(Options::class);

        $this->assertError('Bad product.');
    }

    public function testOptionsUnexpectedFailure(): void
    {
        $this->signIn();
        $this->catalogService->method('productOptions')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Options::class);

        $this->assertError('Could not load the product options. Please try again.');
    }

    public function testProductDetail(): void
    {
        $this->signIn();
        $this->params = ['id' => '5'];
        $this->catalogService->method('productDetail')->with(5, 2, 0, null)->willReturn(['id' => 5]);

        $this->dispatch(Product::class);

        $this->assertSuccess(['id' => 5]);
    }

    public function testProductDetailMissingAndFailure(): void
    {
        $this->signIn();
        $this->catalogService->method('productDetail')->willReturnOnConsecutiveCalls(null, $this->throwException(new \RuntimeException('x')));

        $this->dispatch(Product::class);
        $this->assertError('Product not found.');

        $this->dispatch(Product::class);
        $this->assertError('Could not load the product. Please try again.');
    }

    public function testProductsRequireCategory(): void
    {
        $this->signIn();

        $this->dispatch(Products::class);

        $this->assertError('Category id is required.');
    }

    public function testProductsOfCategory(): void
    {
        $this->signIn();
        $this->params = ['category_id' => '8', 'page' => '2', 'group_id' => '1'];
        $this->catalogService->expects($this->once())->method('byCategory')->with(8, 2, 2, 1, null)->willReturn(['items' => []]);

        $this->dispatch(Products::class);

        $this->assertSuccess(['items' => []]);
    }

    public function testProductsOfDeletedCategory(): void
    {
        $this->signIn();
        $this->params = ['category_id' => '8'];
        $this->catalogService->method('byCategory')->willThrowException(new NoSuchEntityException(__('x')));

        $this->dispatch(Products::class);

        $this->assertError('Category no longer exists.', 'not_found');
    }

    public function testProductsUnexpectedFailure(): void
    {
        $this->signIn();
        $this->params = ['category_id' => '8'];
        $this->catalogService->method('byCategory')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Products::class);

        $this->assertError('Could not load products. Please try again.');
    }

    public function testQuickkeyEditingRequiresLayoutPermission(): void
    {
        $this->signIn();
        $this->denyPermission('can_edit_layout');
        $this->catalogService->expects($this->never())->method('quickKeySave');
        $this->catalogService->expects($this->never())->method('quickKeyRemove');

        $this->dispatch(QuickkeySave::class);
        $this->assertError('You do not have permission to perform this action.');

        $this->dispatch(QuickkeyRemove::class);
        $this->assertError('You do not have permission to perform this action.');
    }

    public function testQuickkeyEditingRequiresFormKey(): void
    {
        $this->signIn();
        $this->formKeyValid = false;

        $this->dispatch(QuickkeySave::class);

        $this->assertError('Invalid form key.', 'invalid_form_key', 403);
    }

    public function testQuickkeySavePassesBodyAndRegister(): void
    {
        $this->signIn();
        $this->openSessionOnRegister(4);
        $this->body = ['product_id' => 10, 'label' => 'Tee'];
        $this->catalogService->expects($this->once())->method('quickKeySave')
            ->with(['product_id' => 10, 'label' => 'Tee'], 4)->willReturn(['quick_key_id' => 1]);

        $this->dispatch(QuickkeySave::class);

        $this->assertSuccess(['quick_key_id' => 1]);
        $this->assertSame('Quick key saved.', $this->result['message']);
    }

    public function testQuickkeySaveErrors(): void
    {
        $this->signIn();
        $this->catalogService->method('quickKeySave')->willThrowException($this->localized('Quick key not found.'));

        $this->dispatch(QuickkeySave::class);

        $this->assertError('Quick key not found.');
    }

    public function testQuickkeySaveUnexpectedFailure(): void
    {
        $this->signIn();
        $this->catalogService->method('quickKeySave')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(QuickkeySave::class);

        $this->assertError('Could not save the quick key. Please try again.');
    }

    public function testQuickkeyRemoveNormalisesId(): void
    {
        $this->signIn();
        $this->body = ['quick_key_id' => 'abc'];
        $this->catalogService->expects($this->once())->method('quickKeyRemove')->with(0, null);

        $this->dispatch(QuickkeyRemove::class);

        $this->assertSuccess(['quick_key_id' => 0]);
        $this->assertSame('Quick key removed.', $this->result['message']);
    }

    public function testQuickkeyRemoveErrors(): void
    {
        $this->signIn();
        $this->body = ['quick_key_id' => '5'];
        $this->catalogService->method('quickKeyRemove')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(QuickkeyRemove::class);

        $this->assertError('Could not remove the quick key. Please try again.');
    }

    public function testQuickkeysPreferExplicitRegisterParam(): void
    {
        $this->signIn();
        $this->openSessionOnRegister(4);
        $this->params = ['register_id' => '9'];
        $this->catalogService->expects($this->once())->method('quickKeys')->with(9)->willReturn([]);

        $this->dispatch(Quickkeys::class);

        $this->assertSuccess([]);
    }

    public function testQuickkeysFallBackToSessionRegister(): void
    {
        $this->signIn();
        $this->openSessionOnRegister(4);
        $this->catalogService->expects($this->once())->method('quickKeys')->with(4)->willReturn([['id' => 1]]);

        $this->dispatch(Quickkeys::class);

        $this->assertSuccess([['id' => 1]]);
    }

    public function testQuickkeysFailure(): void
    {
        $this->signIn();
        $this->catalogService->method('quickKeys')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Quickkeys::class);

        $this->assertError('Could not load quick keys. Please try again.');
    }

    public function testSearchPassesQueryAndPaging(): void
    {
        $this->signIn();
        $this->params = ['q' => 'tee', 'page' => '3', 'group_id' => '2'];
        $this->catalogService->expects($this->once())->method('search')->with('tee', 2, 3, 2, null)->willReturn(['items' => []]);

        $this->dispatch(Search::class);

        $this->assertSuccess(['items' => []]);
    }

    public function testSearchFailure(): void
    {
        $this->signIn();
        $this->catalogService->method('search')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Search::class);

        $this->assertError('Product search failed. Please try again.');
    }

    public function testSnapshotRequiresOfflineMode(): void
    {
        $this->signIn();
        $this->config->method('isOfflineModeEnabled')->with(2)->willReturn(false);
        $this->catalogService->expects($this->never())->method('catalogSnapshot');

        $this->dispatch(Snapshot::class);

        $this->assertError('Offline mode is disabled.', 'offline_disabled');
    }

    public function testSnapshotReturnsCatalog(): void
    {
        $this->signIn();
        $this->config->method('isOfflineModeEnabled')->willReturn(true);
        $this->catalogService->method('catalogSnapshot')->with(2)->willReturn([['sku' => 'A']]);

        $this->dispatch(Snapshot::class);

        $this->assertSuccess([['sku' => 'A']]);
    }

    public function testSnapshotFailure(): void
    {
        $this->signIn();
        $this->config->method('isOfflineModeEnabled')->willReturn(true);
        $this->catalogService->method('catalogSnapshot')->willThrowException(new \RuntimeException('x'));

        $this->dispatch(Snapshot::class);

        $this->assertError('Could not build the offline catalog snapshot.');
    }
}
