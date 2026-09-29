<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;
use StackNuts\StackGauge\Model\Util\Clock;

class CustomerSignalsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param CustomerCollectionFactory $customerCollectionFactory
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param Clock $clock
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly Clock $clock,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the customer-signals reporter.
     */
    public function getName(): string
    {
        return 'customers';
    }

    /**
     * Human-readable label for the customer-signals reporter block.
     */
    public function getLabel(): string
    {
        return 'Customer Signals';
    }

    /**
     * One-line summary of what the customer-signals reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Counts of customers and recent activity.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Reports total and 30-day-new customer counts, plus a 30-day split of guest vs logged-in orders.
     */
    public function getStatus(): array
    {
        $totalCustomers = $this->customerCollectionFactory->create()->getSize();

        $thirtyDaysAgo = $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s');
        $newCustomers = $this->customerCollectionFactory->create()
            ->addFieldToFilter('created_at', ['gte' => $thirtyDaysAgo])
            ->getSize();

        $orders = $this->orderCollectionFactory->create();
        $orders->addFieldToFilter('created_at', ['gte' => $thirtyDaysAgo]);

        $totalOrders = $orders->getSize();
        $guestOrders = $this->orderCollectionFactory->create()
            ->addFieldToFilter('created_at', ['gte' => $thirtyDaysAgo])
            ->addFieldToFilter('customer_id', ['null' => true])
            ->getSize();

        $loggedInOrders = max(0, $totalOrders - $guestOrders);

        return ['general' => $this->section->facts('general', 'General', $this->getDescription(), [
            'total_customers' => $this->field->number('Total customers', $totalCustomers),
            'new_customers_30d' => $this->field->number('New customers (30d)', $newCustomers),
            'guest_orders_30d' => $this->field->number('Guest orders (30d)', $guestOrders),
            'logged_in_orders_30d' => $this->field->number('Logged-in orders (30d)', $loggedInOrders),
        ])];
    }
}
