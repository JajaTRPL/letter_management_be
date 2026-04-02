<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ActivityLog;
use Carbon\Carbon;

for ($i = 6; $i >= 0; $i--) {
    $date = Carbon::today()->subDays($i);
    $count = ActivityLog::where('type', 'login')->whereDate('created_at', $date)->count();
    echo $date->format('Y-m-d') . ": " . $count . "\n";
}
