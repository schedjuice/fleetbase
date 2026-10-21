<?php

require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$companyUuid = 'bfe65457-edfa-4ae2-b3ca-22f95014bfb5';
session(['company' => $companyUuid]);

$phone = '+959400253545';
$user = Fleetbase\Models\User::where('phone', $phone)
    ->whereHas('driver')
    ->whereNull('deleted_at')
    ->first();

echo $user ? "scoped session: {$user->name}\n" : "scoped session: not found\n";
