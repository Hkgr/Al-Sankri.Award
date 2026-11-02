<?php

use App\Auth\RoleExclusivity;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (preg_match('/^[A-Za-z0-9_]+_schema_test$/', DB::connection()->getDatabaseName()) !== 1) {
    throw new RuntimeException('Role concurrency tests require a disposable *_schema_test database.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user_id']);
$policy = app(RoleExclusivity::class);
match ($input['operation']) {
    'grant' => $policy->grantPlatform($user, $input['code'], 'concurrency_test'),
    'revoke' => $policy->revokePlatform($user, $input['code']),
    'reconcile' => $policy->reconcile($user),
};
echo json_encode(['roles' => array_column($policy->currentRoles($user), 'code')]);
