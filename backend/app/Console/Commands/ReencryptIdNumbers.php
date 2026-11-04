<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Week 14 key rotation for data at rest.
 *
 * After APP_KEY is rotated (new key in APP_KEY, old key in APP_PREVIOUS_KEYS), old rows can
 * still be read. This command re-writes every encrypted ID number with the CURRENT key, so the
 * previous key can then be removed from .env for good.
 */
class ReencryptIdNumbers extends Command
{
    protected $signature = 'security:reencrypt-id-numbers';

    protected $description = 'Re-encrypt users.id_number with the current APP_KEY (run after rotating APP_KEY)';

    public function handle(): int
    {
        $count = 0;
        DB::table('users')->whereNotNull('id_number')->orderBy('id')->each(function ($user) use (&$count) {
            // decryptString tries APP_KEY first, then each APP_PREVIOUS_KEYS entry.
            $plain = Crypt::decryptString($user->id_number);
            DB::table('users')->where('id', $user->id)->update(['id_number' => Crypt::encryptString($plain)]);
            $count++;
        });

        $this->info("Re-encrypted {$count} ID number(s) with the current APP_KEY. You may now remove the old key from APP_PREVIOUS_KEYS.");

        return self::SUCCESS;
    }
}
