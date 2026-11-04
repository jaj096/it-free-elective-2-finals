<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Week 14: requester ID numbers are encrypted at rest (User::$casts 'encrypted').
 *
 * Laravel's encrypter uses AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC) with APP_KEY,
 * so a stolen database file or backup shows only ciphertext. The column grows to TEXT
 * because ciphertext (~200 chars) is longer than the 20-char ID.
 * Existing plaintext rows are encrypted in place; already-encrypted rows are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('id_number')->nullable()->change();
        });

        DB::table('users')->whereNotNull('id_number')->orderBy('id')->each(function ($user) {
            if (! $this->isEncrypted($user->id_number)) {
                DB::table('users')->where('id', $user->id)->update(['id_number' => Crypt::encryptString($user->id_number)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('id_number')->orderBy('id')->each(function ($user) {
            if ($this->isEncrypted($user->id_number)) {
                DB::table('users')->where('id', $user->id)->update(['id_number' => Crypt::decryptString($user->id_number)]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('id_number', 20)->nullable()->change();
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
