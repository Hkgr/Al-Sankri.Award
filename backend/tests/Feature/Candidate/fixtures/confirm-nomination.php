<?php

/*
 * Runs the real Stage 2 confirmation service (NominationDetails::confirm) in
 * its own PHP process and database session, for
 * NominationConfirmationLockingTest. The database comes from the DB_*
 * environment the test passes; anything not named *_schema_test is refused.
 *
 * Input, as JSON on stdin: {"user_id": int, "journey_key": string, "data": {...}}.
 * Output, as JSON: {"outcome": string, "application_id": int|null}.
 */

use App\Candidates\NominationDetails;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) DB::connection()->getDatabaseName();
if (preg_match('/^[A-Za-z0-9_]+_schema_test$/', $database) !== 1) {
    fwrite(STDERR, "Refusing to run against [{$database}].\n");
    exit(2);
}

$input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

[$outcome, $journey] = $app->make(NominationDetails::class)
    ->confirm(User::query()->findOrFail($input['user_id']), $input['data'], $input['journey_key']);

echo json_encode(['outcome' => $outcome, 'application_id' => $journey?->application_id]);
