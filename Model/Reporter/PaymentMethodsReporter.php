<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;

class PaymentMethodsReporter implements ReporterInterface, DeclaresCadenceInterface, DeclaresSectionInterface
{
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';

    /**
     * @param PaymentConfig $paymentConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly PaymentConfig $paymentConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the payment-methods reporter.
     */
    public function getName(): string
    {
        return 'payments';
    }

    /**
     * Human-readable label for the payment-methods reporter block.
     */
    public function getLabel(): string
    {
        return 'Payment Methods';
    }

    /**
     * One-line summary of what the payment-methods reporter covers, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Enabled payment methods and key configuration flags.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Overrides DeclaresCadenceInterface's default to keep payment method status on the hourly cadence.
     */
    public function getCadence(): string
    {
        return self::CADENCE_HOURLY;
    }

    /**
     * Reports each active payment method's code, title, and active flag.
     */
    public function getStatus(): array
    {
        $methods = [];
        $active = $this->paymentConfig->getActiveMethods();
        foreach ($active as $code => $method) {
            $title = '';
            if (is_object($method) && method_exists($method, 'getTitle')) {
                $title = (string)$method->getTitle();
            } else {
                $title = (string)$this->scopeConfig->getValue('payment/' . $code . '/title');
            }
            $activeFlag = (bool)$this->scopeConfig->getValue('payment/' . $code . '/active');
            $methods[] = $this->field->array('', [
                'code' => $this->field->varchar('Code', (string)$code),
                'title' => $this->field->varchar('Title', $title),
                'active' => $this->field->bool('Active', $activeFlag),
            ]);
        }

        return [
            'payments' => $this->section->table(
                'payments',
                'Active Methods',
                $this->getDescription(),
                $methods,
                keyName: 'code'
            ),
        ];
    }
}
