<?php

namespace App\Services;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpUnitsOfMeasure\PhysicalQuantity\Length;
use PhpUnitsOfMeasure\PhysicalQuantity\Mass;

/**
 * SPX courier booking CSV export.
 *
 * The pure builder turns SpxBookingCsvExportRow[] into the exact CSV the SPX
 * booking portal expects: LF endings, no BOM, no trailing newline, and a field
 * quoted only when it contains a quote, comma, or newline. The orchestrator
 * loads company-scoped orders, shapes them into rows, persists generated
 * customer references inside one transaction, and delegates to the builder.
 *
 * Number formatting (weights, dimensions, goods value) replicates JavaScript's
 * String(parseFloat(x)): "1.20" becomes "1.2", "2.00" becomes "2".
 *
 * Declared value is stored as integer cents (Fleetbase mutators strip
 * non-digits, so cents is the only lossless form); the export prints dollars.
 */
class SpxBookingCsvExport
{
    public const DEFAULT_ITEM_NAME = 'Parcel';

    public const MAX_SELECTIONS = 1000;

    private static ?array $columns = null;

    /**
     * The 14 SPX columns in wire order: header label plus row resolver. This
     * table is the single source of truth for the export shape. Every resolver
     * receives the id => reference map; only the reference column uses it.
     */
    private static function columns(): array
    {
        return static::$columns ??= [
            ['header' => '*Recipient Name', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->recipientName],
            ['header' => '*Phone Number', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => static::digitsOnlyPhone($row->recipientPhone)],
            ['header' => '*Postal Code', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->postalCode],
            ['header' => 'Street name, Building', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => static::streetBuilding($row->street, $row->building)],
            ['header' => 'Unit No', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->unitNo],
            ['header' => '*Parcel Weight (KG)', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->weightKg === null ? '' : static::formatNumber($row->weightKg)],
            ['header' => 'Parcel Length (CM)', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->lengthCm === null ? '' : static::formatNumber($row->lengthCm)],
            ['header' => 'Parcel Width (CM)', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->widthCm === null ? '' : static::formatNumber($row->widthCm)],
            ['header' => 'Parcel Height (CM)', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->heightCm === null ? '' : static::formatNumber($row->heightCm)],
            ['header' => '*Item Name', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->itemName],
            ['header' => 'Item Quantity', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => (string) $row->quantity],
            ['header' => 'Customer Reference Number', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $references[$row->id] ?? ''],
            ['header' => '*Parcel Value', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->parcelValue === null ? '' : static::formatNumber($row->parcelValue)],
            ['header' => 'Delivery Instruction', 'resolve' => fn (SpxBookingCsvExportRow $row, array $references) => $row->remark],
        ];
    }

    /**
     * Build the export CSV. Sorts rows (recipient phone, then creation time),
     * assigns one reference letter per duplicate phone in that order, and joins
     * every line with "\n". Rows already carrying a reference keep it.
     *
     * @param SpxBookingCsvExportRow[] $rows
     * @param array<string, string>|null $references optional precomputed id => reference map
     */
    public static function buildCsv(array $rows, ?array $references = null): string
    {
        $sorted = static::sortRows($rows);
        $references ??= static::assignReferences($sorted);

        $lines = [implode(',', array_map([static::class, 'escapeCsvField'], array_column(static::columns(), 'header')))];

        foreach ($sorted as $row) {
            $fields = [];
            foreach (static::columns() as $column) {
                $fields[] = static::escapeCsvField(($column['resolve'])($row, $references));
            }
            $lines[] = implode(',', $fields);
        }

        return implode("\n", $lines);
    }

    /**
     * Legacy comparator: normalized phone string ascending (JS localeCompare
     * over digit-only strings is byte-wise), then creation time ascending.
     * PHP sorts are stable, matching the JS engine for full ties.
     *
     * @param SpxBookingCsvExportRow[] $rows
     * @return SpxBookingCsvExportRow[]
     */
    public static function sortRows(array $rows): array
    {
        usort($rows, fn (SpxBookingCsvExportRow $a, SpxBookingCsvExportRow $b) => static::compareRows($a, $b));

        return $rows;
    }

    private static function compareRows(SpxBookingCsvExportRow $a, SpxBookingCsvExportRow $b): int
    {
        $phoneA = static::digitsOnlyPhone($a->recipientPhone);
        $phoneB = static::digitsOnlyPhone($b->recipientPhone);
        if ($phoneA !== $phoneB) {
            return $phoneA <=> $phoneB;
        }

        return $a->createdAt <=> $b->createdAt;
    }

    /**
     * Reference per row, walking the sorted order so duplicate phones get
     * sequential letters (A, B, ... Z, AA, AB...). An existing reference wins.
     *
     * @param SpxBookingCsvExportRow[] $sortedRows
     * @return array<string, string> row id => reference
     */
    public static function assignReferences(array $sortedRows): array
    {
        $counts = [];
        $references = [];

        foreach ($sortedRows as $row) {
            $phone = static::digitsOnlyPhone($row->recipientPhone);
            $index = $counts[$phone] ?? 0;
            $counts[$phone] = $index + 1;

            $references[$row->id] = filled($row->reference)
                ? $row->reference
                : static::buildReference($row->serviceDate, $phone, static::letterForIndex($index));
        }

        return $references;
    }

    /**
     * Strip non-digits, drop a leading country code 65, keep 8 digits.
     */
    public static function digitsOnlyPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '65')) {
            $digits = substr($digits, 2);
        }

        return substr($digits, 0, 8);
    }

    public static function letterForIndex(int $index): string
    {
        $n = $index;
        $result = '';
        do {
            $result = chr(65 + ($n % 26)) . $result;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $result;
    }

    public static function buildReference(string $ddMMyyyy, string $phone, string $letter): string
    {
        return "{$ddMMyyyy}-{$letter}-{$phone}";
    }

    /**
     * "2026-07-29" -> "29072026". Unparseable input falls back to today.
     */
    public static function formatServiceDate($date): string
    {
        try {
            return Carbon::parse($date)->format('dmY');
        } catch (\Throwable $e) {
            return Carbon::today()->format('dmY');
        }
    }

    /**
     * Quote only when the field contains a quote, comma, or newline; embedded
     * quotes double.
     */
    public static function escapeCsvField(string $value): string
    {
        if (preg_match('/[",\n\r]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * Street (street1) and building joined with ", " when both are non-empty.
     */
    public static function streetBuilding(string $street, string $building): string
    {
        return implode(', ', array_filter([$street, $building], fn (string $part) => $part !== ''));
    }

    /**
     * JavaScript String(parseFloat(x)) semantics: shortest decimal form, so
     * 1.2 prints "1.2" and 2 prints "2".
     */
    public static function formatNumber(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1.0E+15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
    }

    /**
     * Per-order parcel aggregates from entity-shaped arrays (no Eloquent, so
     * this is unit-testable): max weight in KG, max dimensions in CM, distinct
     * item names, summed declared value.
     *
     * Each entity array: name, weight, weightUnit, length, width, height,
     * dimensionsUnit, declaredValue. declaredValue is integer cents (the sync
     * worker stores cents because Fleetbase mutators strip non-digits); the
     * sum is dollars.
     */
    public static function parcelFields(iterable $entities): array
    {
        $weightKg = [];
        $lengthCm = [];
        $widthCm  = [];
        $heightCm = [];
        $names    = [];
        $value    = 0.0;

        foreach ($entities as $entity) {
            $weight = static::toUnit($entity['weight'] ?? null, $entity['weightUnit'] ?? null, Mass::class, 'kg');
            if ($weight !== null) {
                $weightKg[] = $weight;
            }
            $length = static::toUnit($entity['length'] ?? null, $entity['dimensionsUnit'] ?? null, Length::class, 'cm');
            if ($length !== null) {
                $lengthCm[] = $length;
            }
            $width = static::toUnit($entity['width'] ?? null, $entity['dimensionsUnit'] ?? null, Length::class, 'cm');
            if ($width !== null) {
                $widthCm[] = $width;
            }
            $height = static::toUnit($entity['height'] ?? null, $entity['dimensionsUnit'] ?? null, Length::class, 'cm');
            if ($height !== null) {
                $heightCm[] = $height;
            }
            $name = trim((string) ($entity['name'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
            if (isset($entity['declaredValue']) && is_numeric($entity['declaredValue'])) {
                $value += ((float) $entity['declaredValue']) / 100;
            }
        }

        return [
            'weightKg'    => $weightKg ? max($weightKg) : null,
            'lengthCm'    => $lengthCm ? max($lengthCm) : null,
            'widthCm'     => $widthCm ? max($widthCm) : null,
            'heightCm'    => $heightCm ? max($heightCm) : null,
            'itemName'    => $names ? implode(', ', array_keys($names)) : static::DEFAULT_ITEM_NAME,
            'parcelValue' => $value > 0 ? $value : null,
        ];
    }

    /**
     * Convert a quantity through the units library, defaulting a missing unit
     * to the SPX column unit. A non-numeric value or an unknown unit degrades
     * to null (or the raw value when only the unit name fails), mirroring how
     * the export blanks missing measurements.
     */
    private static function toUnit($value, $unit, string $quantityClass, string $defaultUnit): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        try {
            return (new $quantityClass((float) $value, filled($unit) ? $unit : $defaultUnit))->toUnit($defaultUnit);
        } catch (\Throwable $e) {
            return (float) $value;
        }
    }

    /**
     * Company-scoped export for the selected order uuids. Generated references
     * are merged into order meta (existing keys untouched) in one transaction;
     * re-exports reuse them.
     *
     * @throws InvalidArgumentException when no order matches
     */
    public function exportForSelections(array $selections): string
    {
        $orders = Order::with(['payload.dropoff', 'payload.entities', 'customer'])
            ->where('company_uuid', session('company'))
            ->whereIn('uuid', $selections)
            ->get();

        if ($orders->isEmpty()) {
            throw new InvalidArgumentException('No eligible orders found to export.');
        }

        $rows = static::sortRows($orders->map(fn (Order $order) => $this->buildRow($order))->all());
        $references = static::assignReferences($rows);

        $this->persistReferences($orders, $references);

        return static::buildCsv($rows, $references);
    }

    private function buildRow(Order $order): SpxBookingCsvExportRow
    {
        $payload = $order->payload;
        $dropoff = $payload?->dropoff;
        $entities = $payload?->entities ?? collect();
        $parcels = static::parcelFields($entities->map(fn (Entity $entity) => [
            'name'           => $entity->name,
            'weight'         => $entity->weight,
            'weightUnit'     => $entity->weight_unit,
            'length'         => $entity->length,
            'width'          => $entity->width,
            'height'         => $entity->height,
            'dimensionsUnit' => $entity->dimensions_unit,
            'declaredValue'  => $entity->declared_value,
        ])->all());

        return new SpxBookingCsvExportRow(
            id: $order->uuid,
            recipientName: static::resolveRecipientName(trim((string) $order->customer?->name), $order->meta),
            recipientPhone: static::resolveRecipientPhone(trim((string) $order->customer?->phone), $order->meta, $dropoff?->phone),
            postalCode: trim((string) $dropoff?->postal_code),
            street: trim((string) $dropoff?->street1),
            building: trim((string) $dropoff?->building),
            unitNo: static::unitNo($dropoff),
            weightKg: $parcels['weightKg'],
            lengthCm: $parcels['lengthCm'],
            widthCm: $parcels['widthCm'],
            heightCm: $parcels['heightCm'],
            itemName: $parcels['itemName'],
            quantity: $entities->count(),
            reference: $this->existingReference($order),
            parcelValue: $parcels['parcelValue'],
            remark: trim((string) $order->notes),
            createdAt: $order->created_at?->getTimestamp() ?? 0,
            serviceDate: static::formatServiceDate($order->scheduled_at),
        );
    }

    /**
     * The recipient is the order's customer morph (Contact or Vendor). Synced
     * orders often carry a NULL morph, so the order meta snapshot
     * (customer_name / customer_phone) applies next, and the phone falls back
     * once more to the dropoff place before giving up.
     */
    public static function resolveRecipientName(?string $morphName, $meta): string
    {
        foreach ([$morphName, data_get($meta, 'customer_name')] as $candidate) {
            $name = trim((string) $candidate);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    public static function resolveRecipientPhone(?string $morphPhone, $meta, ?string $placePhone): string
    {
        foreach ([$morphPhone, data_get($meta, 'customer_phone'), $placePhone] as $candidate) {
            $phone = trim((string) $candidate);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    /**
     * Places have no unit column. Synced orders carry the unit in place meta
     * (unit, unit_no, unit_number) or in street2; meta wins, then street2,
     * else blank.
     */
    private static function unitNo(?Place $dropoff): string
    {
        if (!$dropoff) {
            return '';
        }

        foreach (['unit', 'unit_no', 'unit_number'] as $key) {
            $unit = trim((string) data_get($dropoff->meta, $key));
            if ($unit !== '') {
                return $unit;
            }
        }

        return trim((string) $dropoff->street2);
    }

    private function existingReference(Order $order): ?string
    {
        $reference = trim((string) data_get($order->meta, 'customer_reference'));

        return $reference !== '' ? $reference : null;
    }

    private function persistReferences(Collection $orders, array $references): void
    {
        DB::transaction(function () use ($orders, $references) {
            foreach ($orders as $order) {
                $reference = $references[$order->uuid] ?? null;
                if (!$reference || filled(data_get($order->meta, 'customer_reference'))) {
                    continue;
                }

                $order->meta = array_merge((array) $order->meta, ['customer_reference' => $reference]);
                $order->save();
            }
        });
    }
}

/**
 * One export line's resolved fields. The pure builder consumes these and the
 * orchestrator produces them, so the builder never touches the database.
 */
final class SpxBookingCsvExportRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $recipientName,
        public readonly string $recipientPhone,
        public readonly string $postalCode,
        public readonly string $street,
        public readonly string $building,
        public readonly string $unitNo,
        public readonly ?float $weightKg,
        public readonly ?float $lengthCm,
        public readonly ?float $widthCm,
        public readonly ?float $heightCm,
        public readonly string $itemName,
        public readonly int $quantity,
        public readonly ?string $reference,
        public readonly ?float $parcelValue,
        public readonly string $remark,
        public readonly int $createdAt,
        public readonly string $serviceDate,
    ) {
    }
}
