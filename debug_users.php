<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

echo "Total Users: " . User::count() . "\n";
echo "Roles breakdown:\n";
foreach (User::select('role', \DB::raw('count(*) as count'))->groupBy('role')->get() as $res) {
    echo $res->role . ": " . $res->count . "\n";
}

echo "\nFirst User details:\n";
print_r(User::first()?->toArray());
