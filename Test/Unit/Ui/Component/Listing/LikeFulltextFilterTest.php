<?php
declare(strict_types=1);

namespace Panth\MagePos\Test\Unit\Ui\Component\Listing;

require_once __DIR__ . '/../../../autoload.php';

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\MagePos\Ui\Component\Listing\LikeFulltextFilter;
use PHPUnit\Framework\TestCase;

class LikeFulltextFilterTest extends TestCase
{
    private function makeCollection(Select $select): AbstractDb
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($c) => '`' . $c . '`');
        $connection->method('quoteInto')->willReturnCallback(
            static fn ($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );
        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);

        return $collection;
    }

    private function filter(mixed $value): Filter
    {
        return new Filter(['value' => $value]);
    }

    public function testBuildsOrLikeConditionAcrossConfiguredColumns(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('where')
            ->with("`name` LIKE '%50\\%\\_off%' OR `code` LIKE '%50\\%\\_off%'");

        (new LikeFulltextFilter(['name', 7, 'code']))->apply($this->makeCollection($select), $this->filter(' 50%_off '));
    }

    public function testLongSearchIsTruncated(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('where')
            ->with("`name` LIKE '%" . str_repeat('a', 200) . "%'");

        (new LikeFulltextFilter(['name']))->apply($this->makeCollection($select), $this->filter(str_repeat('a', 250)));
    }

    public function testBlankOrNonScalarValuesAreIgnored(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->never())->method('where');
        $filter = new LikeFulltextFilter(['name']);

        $filter->apply($this->makeCollection($select), $this->filter('   '));
        $filter->apply($this->makeCollection($select), $this->filter(['x']));
    }

    public function testWithoutColumnsOrDbCollectionNothingHappens(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->never())->method('where');

        (new LikeFulltextFilter([]))->apply($this->makeCollection($select), $this->filter('x'));
        (new LikeFulltextFilter(['name']))->apply($this->createStub(Collection::class), $this->filter('x'));
    }
}
