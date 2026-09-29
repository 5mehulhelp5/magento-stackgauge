<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Api\Field;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\Field\Field;

class VarcharFieldTest extends TestCase
{
    public function testJsonSerializeOmitsCriticalValuesAndSeverityByDefault(): void
    {
        $field = (new Field())->varchar('Name', 'Magento_Catalog');

        $this->assertSame(['type' => 'varchar', 'label' => 'Name', 'value' => 'Magento_Catalog'], $field->jsonSerialize());
    }

    public function testJsonSerializeIncludesCriticalValuesWhenSet(): void
    {
        $field = (new Field())->varchar('Status', 'Suspended', ['Reindex required', 'Suspended']);

        $this->assertSame(
            [
                'type' => 'varchar',
                'label' => 'Status',
                'value' => 'Suspended',
                'critical_values' => ['Reindex required', 'Suspended'],
            ],
            $field->jsonSerialize()
        );
    }

    public function testJsonSerializeIncludesSeverityWhenSet(): void
    {
        $field = (new Field())->varchar('Schedule Status', 'idle (0 in backlog)', severity: Field::SEVERITY_OK);

        $this->assertSame(
            [
                'type' => 'varchar',
                'label' => 'Schedule Status',
                'value' => 'idle (0 in backlog)',
                'severity' => 'ok',
            ],
            $field->jsonSerialize()
        );
    }

    public function testRejectsAnUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Field())->varchar('Schedule Status', 'idle (0 in backlog)', severity: 'terrible');
    }

    public function testRejectsBothCriticalValuesAndSeverityAtOnce(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Field())->varchar('Status', 'Suspended', criticalValues: ['Suspended'], severity: Field::SEVERITY_CRITICAL);
    }

    /**
     * A real byte sequence seen in production: \xD1 expects a UTF-8 continuation byte in
     * 0x80-0xBF but \x40 isn't one. Magento's Json::serialize() throws on the first invalid
     * byte anywhere in a report payload, killing every reporter's data - not just this field's
     * - so construction must scrub rather than pass invalid bytes through untouched.
     */
    public function testScrubsInvalidUtf8InsteadOfThrowing(): void
    {
        $field = (new Field())->varchar('Line', "Bearer \xD1\x40 is not valid header value.");

        $value = $field->getValue();

        $this->assertTrue(mb_check_encoding($value, 'UTF-8'));
        $this->assertNotFalse(json_encode(['v' => $field->jsonSerialize()]));
    }
}
