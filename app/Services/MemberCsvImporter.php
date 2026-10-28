<?php

namespace App\Services;

use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\Username;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class MemberCsvImporter
{
    private const REQUIRED_HEADERS = [
        'phone_number',
        'first_name',
        'wallet_balance',
        'withdrawable_balance',
        'contact_confirmed',
        'wallet_active',
    ];

    private const MAX_ROWS = 10_000;

    /** @return array{created:int,updated:int,skipped:int,total:int} */
    public function import(UploadedFile $file): array
    {
        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('The uploaded CSV could not be opened.');
        }

        try {
            $headers = fgetcsv($stream);

            if (! is_array($headers)) {
                throw new RuntimeException('The uploaded CSV is empty.');
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map($this->normalizeHeader(...), $headers);
            $missingHeaders = array_diff(self::REQUIRED_HEADERS, $headers);

            if ($missingHeaders !== []) {
                throw new RuntimeException('Missing required columns: '.implode(', ', $missingHeaders).'.');
            }

            $rows = [];
            $skipped = 0;
            $line = 1;

            while (($values = fgetcsv($stream)) !== false) {
                $line++;

                if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }

                if ($line > self::MAX_ROWS + 1) {
                    throw new RuntimeException('The CSV exceeds the 10,000 member import limit.');
                }

                $values = array_pad($values, count($headers), null);
                $row = array_combine($headers, array_slice($values, 0, count($headers)));
                $phone = preg_replace('/\s+/', '', trim((string) ($row['phone_number'] ?? '')));
                $rowText = strtoupper(implode(' ', array_map(fn ($value) => trim((string) $value), $values)));

                // The source export ends with TOTAL MEMBERS and TOTAL BALANCE rows.
                if (str_contains($rowText, 'TOTAL MEMBERS') || str_contains($rowText, 'TOTAL BALANCE')) {
                    $skipped++;
                    continue;
                }

                // Accept any spelling the export uses, but store exactly one canonical form
                // so the same person cannot be imported alongside their own account.
                $phone = PhoneNumber::normalize($phone) ?? $phone;

                if (! preg_match('/^251\d{9}$/', $phone)) {
                    throw new RuntimeException("Line {$line} has an invalid Ethiopian phone number.");
                }

                if (isset($rows[$phone])) {
                    throw new RuntimeException("Line {$line} duplicates phone number {$phone}.");
                }

                $credits = $this->money($row['wallet_balance'] ?? null, $line, 'Wallet Balance');
                $withdrawable = $this->money($row['withdrawable_balance'] ?? null, $line, 'Withdrawable Balance');
                $nonWithdrawable = $this->money($row['non_withdrawable_balance'] ?? 0, $line, 'Non Withdrawable Balance');

                if (bccomp($withdrawable, $credits, 2) === 1) {
                    throw new RuntimeException("Line {$line} has a withdrawable balance above its wallet balance.");
                }

                if (bccomp(bcadd($withdrawable, $nonWithdrawable, 2), $credits, 2) !== 0) {
                    throw new RuntimeException("Line {$line} has wallet balances that do not add up.");
                }

                $firstName = trim((string) ($row['first_name'] ?? ''));
                $lastName = trim((string) ($row['last_name'] ?? ''));

                if ($firstName === '') {
                    throw new RuntimeException("Line {$line} is missing the first name.");
                }

                $rows[$phone] = [
                    'import_source_id' => $this->nullableInteger($row['id'] ?? null),
                    'name' => trim($firstName.' '.$lastName),
                    'first_name' => Str::limit($firstName, 80, ''),
                    'last_name' => Str::limit($lastName, 80, ''),
                    'phone' => $phone,
                    'member_type' => $this->nullableString($row['member_type'] ?? null, 50),
                    'affiliate_id' => $this->nullableString($row['affiliate_id'] ?? null, 100),
                    'affiliate_provider' => $this->nullableString($row['affiliate_provider'] ?? null, 100),
                    'hashed_username' => $this->nullableString($row['hashed_username'] ?? null, 191),
                    'deposit_setting' => $this->nullableString($row['deposit_setting'] ?? null, 100),
                    'withdraw_setting' => $this->nullableString($row['withdraw_setting'] ?? null, 100),
                    'credits' => $credits,
                    'withdrawable_balance' => $withdrawable,
                    'withdrawal_cap' => $this->nullableInteger($row['withdrawal_cap'] ?? null),
                    'transfer_cap' => $this->nullableInteger($row['transfer_cap'] ?? null),
                    'deposit_cap' => $this->nullableInteger($row['deposit_cap'] ?? null),
                    'contact_confirmed' => $this->boolean($row['contact_confirmed'] ?? null),
                    'active' => $this->boolean($row['wallet_active'] ?? null),
                    'role' => User::ROLE_PLAYER,
                    'created_at' => $this->date($row['created_at'] ?? null),
                    'updated_at' => $this->date($row['updated_at'] ?? null),
                    'imported_at' => now(),
                ];
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw new RuntimeException('No member records were found in the CSV.');
        }

        $phones = array_keys($rows);
        $protectedPhones = User::query()
            ->whereIn('phone', $phones)
            ->where('role', '!=', User::ROLE_PLAYER)
            ->pluck('phone')
            ->all();

        if ($protectedPhones !== []) {
            throw new RuntimeException('The import was stopped because one or more phone numbers belong to administrator accounts.');
        }

        // Archived members keep their number reserved; upserting onto one would fail on the
        // unique index, so the import stops with an explanation instead.
        $archivedPhones = User::onlyTrashed()->whereIn('phone', $phones)->pluck('phone')->all();

        if ($archivedPhones !== []) {
            throw new RuntimeException(
                'The import was stopped because '.count($archivedPhones)
                .' phone number(s) belong to archived members. Restore or permanently delete them first: '
                .implode(', ', array_slice($archivedPhones, 0, 5)).'.'
            );
        }

        $existing = User::query()
            ->where('role', User::ROLE_PLAYER)
            ->whereIn('phone', $phones)
            ->count();

        $password = Hash::make(Str::random(64));

        DB::transaction(function () use ($rows, $password): void {
            collect(array_values($rows))->chunk(500)->each(function ($chunk) use ($password): void {
                $records = $chunk->map(function (array $row) use ($password): array {
                    // Imported members are identified by phone number and handle, never by an
                    // invented email address.
                    return $row + [
                        'username' => Username::unique(
                            [$row['hashed_username'] ?? null, $row['name'] ?? null],
                            $row['phone'] ?? null,
                        ),
                        'password' => $password,
                    ];
                })->all();

                DB::table('users')->upsert(
                    $records,
                    ['phone'],
                    [
                        'import_source_id', 'name', 'first_name', 'last_name', 'member_type',
                        'affiliate_id', 'affiliate_provider', 'hashed_username', 'deposit_setting',
                        // Balances are set when a member is CREATED by the import and never
                        // touched on an existing one: a spreadsheet is not a ledger, and an
                        // import that could rewrite credits was a way to mint money.
                        'withdraw_setting', 'withdrawal_cap',
                        'transfer_cap', 'deposit_cap', 'contact_confirmed', 'active', 'updated_at',
                        'imported_at',
                    ]
                );
            });
        });

        return [
            'created' => count($rows) - $existing,
            'updated' => $existing,
            'skipped' => $skipped,
            'total' => count($rows),
        ];
    }

    private function normalizeHeader(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))), '_');
    }

    private function money(mixed $value, int $line, string $column): string
    {
        $normalized = str_replace([',', ' '], '', trim((string) $value));

        if ($normalized === '' || ! is_numeric($normalized) || bccomp($normalized, '0', 2) === -1) {
            throw new RuntimeException("Line {$line} has an invalid {$column}.");
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function nullableInteger(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function boolean(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'active'], true);
    }

    private function date(mixed $value): Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }
    }
}
