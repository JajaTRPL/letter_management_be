<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ActivityLog;
use Carbon\Carbon;

$today = Carbon::today();
$logs = ActivityLog::where('type', 'login')->whereDate('created_at', $today)->get(['created_at', 'ip_address']);

echo "Today's logs (" . $today->toDateString() . "):\n";
foreach ($logs as $log) {
    echo $log->created_at . " from " . $log->ip_address . "\n";
}

echo "\nConfig timezone: " . config('app.timezone') . "\n";
echo "Carbon now: " . Carbon::now() . "\n";
