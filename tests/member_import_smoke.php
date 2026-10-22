<?php

use App\Services\MemberCsvImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config([
    'database.default' => 'member_import_test',
    'database.connections.member_import_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ],
]);
DB::purge('member_import_test');

Schema::connection('member_import_test')->create('users', function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('import_source_id')->nullable()->index();
    $table->string('name');
    $table->string('first_name')->nullable();
    $table->string('last_name')->nullable();
    $table->string('username', 60)->nullable()->unique();
    $table->string('email')->nullable()->unique();
    $table->string('phone')->nullable()->unique();
    $table->string('password');
    $table->string('role')->default('player');
    $table->string('member_type')->nullable();
    $table->string('affiliate_id')->nullable();
    $table->string('affiliate_provider')->nullable();
    $table->string('hashed_username')->nullable()->index();
    $table->string('deposit_setting')->nullable();
    $table->string('withdraw_setting')->nullable();
    $table->decimal('credits', 18, 2)->default(10);
    $table->decimal('withdrawable_balance', 18, 2)->default(0);
    $table->unsignedBigInteger('withdrawal_cap')->nullable();
    $table->unsignedBigInteger('transfer_cap')->nullable();
    $table->unsignedBigInteger('deposit_cap')->nullable();
    $table->boolean('contact_confirmed')->default(false);
    $table->boolean('active')->default(true);
    $table->timestamps();
    $table->softDeletes();
    $table->timestamp('imported_at')->nullable();
});

$csvPath = $argv[1] ?? null;

if (! is_string($csvPath) || ! is_file($csvPath)) {
    throw new RuntimeException('Pass the source members CSV as the first argument.');
}

$file = new UploadedFile($csvPath, 'members.csv', 'text/csv', null, true);
$importer = app(MemberCsvImporter::class);
$first = $importer->import($file);

if ($first !== ['created' => 2195, 'updated' => 0, 'skipped' => 2, 'total' => 2195]) {
    throw new RuntimeException('Unexpected first import result: '.json_encode($first));
}

if (DB::table('users')->count() !== 2195) {
    throw new RuntimeException('The member count does not match the source export.');
}

if (number_format((float) DB::table('users')->sum('credits'), 2, '.', '') !== '2587.61') {
    throw new RuntimeException('The wallet balance total was not preserved.');
}

if (number_format((float) DB::table('users')->sum('withdrawable_balance'), 2, '.', '') !== '884.61') {
    throw new RuntimeException('The withdrawable balance total was not preserved.');
}

$second = $importer->import($file);

if ($second !== ['created' => 0, 'updated' => 2195, 'skipped' => 2, 'total' => 2195] || DB::table('users')->count() !== 2195) {
    throw new RuntimeException('The second import was not idempotent.');
}

echo "Member import smoke test passed: 2,195 members, balances preserved, re-import safe.\n";
