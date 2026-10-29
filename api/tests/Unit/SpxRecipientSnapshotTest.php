<?php

namespace Tests\Unit;

use App\Services\SpxBookingCsvExport;
use Fleetbase\FleetOps\Models\Place;
use Tests\TestCase;

/**
 * Boots the app because constructing a Fleet-Ops Place resolves the request
 * from the container (HasApiModelBehavior).
 */
class SpxRecipientSnapshotTest extends TestCase
{
    public function test_snapshot_copies_the_recipient_place_into_meta(): void
    {
        $place = new Place([
            'name'        => 'Su Yati Chit',
            'phone'       => '+6582160753',
            'street1'     => '452A Bukit Batok West Avenue 6',
            'street2'     => '#19-739',
            'building'    => 'Block 452A',
            'postal_code' => '651452',
        ]);

        $meta = SpxBookingCsvExport::withRecipientSnapshot(['delivery_type' => 'NDD', 'recipient_name' => 'Synced Name'], $place);

        $this->assertSame([
            'delivery_type'         => 'NDD',
            'recipient_name'        => 'Synced Name',
            'recipient_phone'       => '+6582160753',
            'recipient_street'      => '452A Bukit Batok West Avenue 6',
            'recipient_building'    => 'Block 452A',
            'recipient_unit_no'     => '#19-739',
            'recipient_postal_code' => '651452',
        ], $meta);
    }

    public function test_snapshot_leaves_meta_alone_when_an_address_is_already_recorded(): void
    {
        $place = new Place(['name' => 'SPX Hub', 'street1' => 'Hub Street', 'postal_code' => '018989']);

        $withStreet = ['recipient_street' => '452A Bukit Batok'];
        $withPostal = ['recipient_postal_code' => '651452'];

        $this->assertSame($withStreet, SpxBookingCsvExport::withRecipientSnapshot($withStreet, $place));
        $this->assertSame($withPostal, SpxBookingCsvExport::withRecipientSnapshot($withPostal, $place));
        $this->assertSame([], SpxBookingCsvExport::withRecipientSnapshot([], null));
    }
}
