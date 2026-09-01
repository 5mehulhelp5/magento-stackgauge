<?php
declare(strict_types=1);

namespace StackNuts\ViewGento\Model\Reporter;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use StackNuts\ViewGento\Api\Field\Field;
use StackNuts\ViewGento\Api\ReporterInterface;

final class ProductHealthReporter implements ReporterInterface
{
    private const SCHEMA_VERSION = '1.0';

    public function __construct(private readonly ProductCollectionFactory $productCollectionFactory)
    {
    }

    public function getName(): string
    {
        return 'product_health';
    }

    public function getLabel(): string
    {
        return 'Product Health';
    }

    public function getDescription(): string
    {
        return 'Counts of common product data issues.';
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getStatus(): array
    {
        $missingImage = $this->productCollectionFactory->create()
            ->addAttributeToFilter('image', ['eq' => 'no_selection'])
            ->getSize();

        $noPrice = $this->productCollectionFactory->create()
            ->addAttributeToFilter('price', ['lte' => 0])
            ->getSize();

        return ['product_issues' => Field::array('Product Issues', [
            Field::number('missing_image', $missingImage),
            Field::number('no_price', $noPrice),
        ])];
    }
}
