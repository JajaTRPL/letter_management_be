<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SuperAdmin\DashboardController;

$ctrl = new DashboardController();
$response = $ctrl->getStats();
echo $response->getContent();
