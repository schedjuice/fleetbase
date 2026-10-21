<?php

namespace Tests\Unit;

use App\Services\SpxBookingCsvExport;
use App\Services\SpxBookingCsvExportRow;
use PHPUnit\Framework\TestCase;

class SpxBookingCsvExportTest extends TestCase
{
    private const EXPECTED_HEADER = '*Recipient Name,*Phone Number,*Postal Code,"Street name, Building",Unit No,*Parcel Weight (KG),Parcel Length (CM),Parcel Width (CM),Parcel Height (CM),*Item Name,Item Quantity,Customer Reference Number,*Parcel Value,Delivery Instruction';

    /**
     * Minimal RFC-4180-style CSV line parser: respects quoted fields and ""
     * escapes, mirroring the legacy test's round-trip check.
     */
    private function parseCsvLine(string $line): array
    {
        $fields  = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $ch = $line[$i];
            if ($inQuotes) {
                if ($ch === '"') {
                    if (($line[$i + 1] ?? '') === '"') {
                        $current .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $current .= $ch;
                }
            } elseif ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === ',') {
                $fields[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        $fields[] = $current;

        return $fields;
    }

    /**
     * Builds a row the same way the orchestrator does: parcel aggregates from
     * plain entity arrays, then the DTO. Defaults mirror the legacy fixture.
     */
    private function row(array $overrides = [], ?array $entities = null): SpxBookingCsvExportRow
    {
        // A weight-only, nameless entity: the legacy fixture shape (known
        // weight, no parcel metadata -> "Parcel" item name, blank value).
        $entities ??= [$this->entity(['name' => null])];
        $parcels = SpxBookingCsvExport::parcelFields($entities);

        return new SpxBookingCsvExportRow(
            id: $overrides['id'] ?? 'o1',
            recipientName: $overrides['recipientName'] ?? 'Su Yati Chit',
            recipientPhone: $overrides['recipientPhone'] ?? '+6582160753',
            postalCode: $overrides['postalCode'] ?? '651452',
            street: $overrides['street'] ?? '452A Bukit Batok West Avenue 6',
            building: $overrides['building'] ?? '',
            unitNo: $overrides['unitNo'] ?? '#19-739',
            weightKg: $parcels['weightKg'],
            lengthCm: $parcels['lengthCm'],
            widthCm: $parcels['widthCm'],
            heightCm: $parcels['heightCm'],
            itemName: $parcels['itemName'],
            quantity: $overrides['quantity'] ?? 1,
            reference: $overrides['reference'] ?? null,
            parcelValue: $parcels['parcelValue'],
            remark: $overrides['remark'] ?? 'Door step',
            createdAt: $overrides['createdAt'] ?? strtotime('2026-07-29T10:00:00Z'),
            serviceDate: $overrides['serviceDate'] ?? '29072026',
        );
    }

    private function entity(array $overrides = []): array
    {
        return array_merge([
            'name'           => 'Food',
            'weight'         => '1.20',
            'weightUnit'     => 'kg',
            'length'         => null,
            'width'          => null,
            'height'         => null,
            'dimensionsUnit' => 'cm',
            'declaredValue'  => null,
        ], $overrides);
    }

    public function test_formats_service_date_as_ddmmyyyy(): void
    {
        $this->assertSame('29072026', SpxBookingCsvExport::formatServiceDate('2026-07-29'));
    }

    public function test_builds_customer_reference_with_recipient_phone_digits(): void
    {
        $reference = SpxBookingCsvExport::buildReference(
            SpxBookingCsvExport::formatServiceDate('2026-07-29'),
            SpxBookingCsvExport::digitsOnlyPhone('+6582160753'),
            'A'
        );

        $this->assertSame('29072026-A-82160753', $reference);
    }

    public function test_normalizes_recipient_phone_digits(): void
    {
        $this->assertSame('82160753', SpxBookingCsvExport::digitsOnlyPhone('+6582160753'));
        $this->assertSame('82160753', SpxBookingCsvExport::digitsOnlyPhone('6582160753'));
        $this->assertSame('82160753', SpxBookingCsvExport::digitsOnlyPhone('82160753'));
        $this->assertSame('91964153', SpxBookingCsvExport::digitsOnlyPhone('+65 9196 4153'));
        $this->assertSame('', SpxBookingCsvExport::digitsOnlyPhone(''));
    }

    public function test_assigns_sequential_letters_per_recipient_phone(): void
    {
        $rows = [
            $this->row(['id' => 'o2', 'createdAt' => strtotime('2026-07-29T11:00:00Z')]),
            $this->row(['id' => 'o1', 'createdAt' => strtotime('2026-07-29T10:00:00Z')]),
            $this->row(['id' => 'o3', 'recipientPhone' => '+6591964153', 'createdAt' => strtotime('2026-07-29T12:00:00Z')]),
        ];

        $references = SpxBookingCsvExport::assignReferences(SpxBookingCsvExport::sortRows($rows));

        $this->assertSame('29072026-A-82160753', $references['o1']);
        $this->assertSame('29072026-B-82160753', $references['o2']);
        $this->assertSame('29072026-A-91964153', $references['o3']);
    }

    public function test_reuses_existing_reference_when_present(): void
    {
        $rows = [$this->row(['id' => 'o1', 'reference' => 'existing-ref'])];

        $references = SpxBookingCsvExport::assignReferences(SpxBookingCsvExport::sortRows($rows));

        $this->assertSame('existing-ref', $references['o1']);
    }

    public function test_supports_letters_beyond_z(): void
    {
        $this->assertSame('Z', SpxBookingCsvExport::letterForIndex(25));
        $this->assertSame('AA', SpxBookingCsvExport::letterForIndex(26));
        $this->assertSame('AB', SpxBookingCsvExport::letterForIndex(27));
    }

    public function test_blanks_degrade_when_no_parcel_metadata(): void
    {
        $this->assertSame([
            'weightKg'    => null,
            'lengthCm'    => null,
            'widthCm'     => null,
            'heightCm'    => null,
            'itemName'    => 'Parcel',
            'parcelValue' => null,
        ], SpxBookingCsvExport::parcelFields([]));
    }

    public function test_uses_max_dimensions_joins_distinct_items_and_sums_value(): void
    {
        $parcels = SpxBookingCsvExport::parcelFields([
            $this->entity([
                'name' => 'Electronics', 'weight' => 2, 'length' => 30,
                'width' => 20, 'height' => 15, 'declaredValue' => 100,
            ]),
            $this->entity([
                'name' => 'Clothing', 'weight' => 1, 'length' => 40,
                'width' => 25, 'height' => 10, 'declaredValue' => 50,
            ]),
        ]);

        $this->assertSame(2.0, $parcels['weightKg']);
        $this->assertSame(40.0, $parcels['lengthCm']);
        $this->assertSame(25.0, $parcels['widthCm']);
        $this->assertSame(15.0, $parcels['heightCm']);
        $this->assertSame('Electronics, Clothing', $parcels['itemName']);
        $this->assertSame(150.0, $parcels['parcelValue']);
    }

    public function test_weight_normalizes_to_natural_decimals(): void
    {
        $parcels = SpxBookingCsvExport::parcelFields([$this->entity(['weight' => '1.20'])]);
        $this->assertSame('1.2', SpxBookingCsvExport::formatNumber($parcels['weightKg']));

        $parcels = SpxBookingCsvExport::parcelFields([$this->entity(['weight' => '2.00'])]);
        $this->assertSame('2', SpxBookingCsvExport::formatNumber($parcels['weightKg']));
    }

    public function test_missing_unit_defaults_to_kg(): void
    {
        $parcels = SpxBookingCsvExport::parcelFields([$this->entity(['weight' => 1, 'weightUnit' => null])]);

        $this->assertSame(1.0, $parcels['weightKg']);
    }

    public function test_header_is_the_quoted_template_with_exactly_14_fields(): void
    {
        $csv = SpxBookingCsvExport::buildCsv([$this->row()]);
        $lines = explode("\n", $csv);
        $header = $lines[0];

        $this->assertSame(self::EXPECTED_HEADER, $header);
        $this->assertStringContainsString('"Street name, Building"', $header);
        $this->assertCount(14, $this->parseCsvLine($header));
    }

    public function test_maps_row_fields_with_default_item_name_and_blank_value(): void
    {
        $csv = SpxBookingCsvExport::buildCsv([$this->row()]);
        $lines = explode("\n", $csv);

        $this->assertSame(
            'Su Yati Chit,82160753,651452,452A Bukit Batok West Avenue 6,#19-739,1.2,,,,Parcel,1,29072026-A-82160753,,Door step',
            $lines[1]
        );

        $fields = $this->parseCsvLine($lines[1]);
        $this->assertSame('Parcel', $fields[9]);
        $this->assertSame('', $fields[12]);
        $this->assertSame('1.2', $fields[5]);
    }

    public function test_exports_parcel_dimensions_and_goods_value(): void
    {
        $row = $this->row(
            ['recipientName' => 'Alex', 'street' => 'Example Street', 'unitNo' => '#01-01', 'remark' => ''],
            [$this->entity([
                'name' => 'Electronics', 'weight' => 2, 'length' => 30,
                'width' => 20, 'height' => 15, 'declaredValue' => 100,
            ])]
        );

        $csv = SpxBookingCsvExport::buildCsv([$row]);
        $line = explode("\n", $csv)[1];

        $this->assertSame(
            'Alex,82160753,651452,Example Street,#01-01,2,30,20,15,Electronics,1,29072026-A-82160753,100,',
            $line
        );

        $fields = $this->parseCsvLine($line);
        $this->assertSame('100', $fields[12]);
    }

    public function test_joins_lines_with_lf_and_no_trailing_newline(): void
    {
        $rows = [
            $this->row(['id' => 'o1']),
            $this->row(['id' => 'o2', 'recipientPhone' => '+6591964153']),
        ];

        $csv = SpxBookingCsvExport::buildCsv($rows);
        $lines = explode("\n", $csv);

        $this->assertCount(3, $lines);
        $this->assertStringEndsNotWith("\n", $csv);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $csv);
        // byte-wise phone order: 82160753 before 91964153
        $this->assertSame('Su Yati Chit', $this->parseCsvLine($lines[1])[0]);
        $this->assertSame('29072026-A-82160753', $this->parseCsvLine($lines[1])[11]);
        $this->assertSame('29072026-A-91964153', $this->parseCsvLine($lines[2])[11]);
    }

    public function test_sorts_by_phone_then_created_at_across_the_whole_export(): void
    {
        $rows = [
            $this->row(['id' => 'late', 'createdAt' => strtotime('2026-07-29T15:00:00Z')]),
            $this->row(['id' => 'first', 'recipientPhone' => '+6591111111', 'createdAt' => strtotime('2026-07-29T09:00:00Z')]),
            $this->row(['id' => 'early', 'createdAt' => strtotime('2026-07-29T08:00:00Z')]),
        ];

        $sorted = SpxBookingCsvExport::sortRows($rows);

        $this->assertSame(['early', 'late', 'first'], array_map(fn ($row) => $row->id, $sorted));
    }

    public function test_quotes_a_field_only_when_needed(): void
    {
        $this->assertSame('plain', SpxBookingCsvExport::escapeCsvField('plain'));
        $this->assertSame('"a,b"', SpxBookingCsvExport::escapeCsvField('a,b'));
        $this->assertSame('"say ""hi"""', SpxBookingCsvExport::escapeCsvField('say "hi"'));
        $this->assertSame("\"line\nbreak\"", SpxBookingCsvExport::escapeCsvField("line\nbreak"));
    }

    public function test_street_building_joins_parts_with_comma_space(): void
    {
        $this->assertSame('Blk 10, Tower B', SpxBookingCsvExport::streetBuilding('Blk 10', 'Tower B'));
        $this->assertSame('Blk 10', SpxBookingCsvExport::streetBuilding('Blk 10', ''));
        $this->assertSame('Tower B', SpxBookingCsvExport::streetBuilding('', 'Tower B'));
        $this->assertSame('', SpxBookingCsvExport::streetBuilding('', ''));
    }

    public function test_recipient_name_falls_back_to_meta_customer_name(): void
    {
        $this->assertSame('Mashi', SpxBookingCsvExport::resolveRecipientName(null, ['customer_name' => 'Mashi']));
        $this->assertSame('Mashi', SpxBookingCsvExport::resolveRecipientName('', ['customer_name' => '  Mashi  ']));
        $this->assertSame('', SpxBookingCsvExport::resolveRecipientName(null, []));
        $this->assertSame('', SpxBookingCsvExport::resolveRecipientName(null, ['customer_name' => '   ']));
    }

    public function test_recipient_phone_falls_back_to_meta_then_place(): void
    {
        $this->assertSame('+6582160753', SpxBookingCsvExport::resolveRecipientPhone('', ['customer_phone' => '+6582160753'], '+6591111111'));
        $this->assertSame('+6591111111', SpxBookingCsvExport::resolveRecipientPhone('', [], '+6591111111'));
        $this->assertSame('+6591111111', SpxBookingCsvExport::resolveRecipientPhone(null, null, '+6591111111'));
        $this->assertSame('', SpxBookingCsvExport::resolveRecipientPhone('', [], null));
    }

    public function test_customer_morph_wins_over_meta_fallback(): void
    {
        $this->assertSame('Su Yati Chit', SpxBookingCsvExport::resolveRecipientName('Su Yati Chit', ['customer_name' => 'Mashi']));
        $this->assertSame('+6582160753', SpxBookingCsvExport::resolveRecipientPhone('+6582160753', ['customer_phone' => '+6599999999'], '+6591111111'));
    }
}
