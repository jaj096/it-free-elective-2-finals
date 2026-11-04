<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints a NEW key pair for the backend <-> generator exchange (it does not write any file).
 * Keys come from the operating system's CSPRNG (random_bytes).
 */
class GenerateExchangeKeys extends Command
{
    protected $signature = 'generator:keys {--kid= : Key ID to use (default: k + current year/month)}';

    protected $description = 'Generate a new encryption + signing key pair for the generator service';

    public function handle(): int
    {
        $kid = (string) ($this->option('kid') ?: 'k'.now()->format('Ym'));
        if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $kid)) {
            $this->error('Key ID may only use letters, digits, - and _ (max 32).');

            return self::FAILURE;
        }

        $enc = 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        $sign = 'base64:'.base64_encode(random_bytes(32));

        $this->line('# backend/.env (sender: replaces the current key)');
        $this->line("GENERATOR_KEY_ID={$kid}");
        $this->line("GENERATOR_ENCRYPTION_KEY={$enc}");
        $this->line("GENERATOR_SIGNING_KEY={$sign}");
        $this->newLine();
        $this->line('# generator-service/.env (receiver: ADD this ID to GENERATOR_ACCEPTED_KEY_IDS first)');
        $this->line("GENERATOR_ENCRYPTION_KEY_{$kid}={$enc}");
        $this->line("GENERATOR_SIGNING_KEY_{$kid}={$sign}");
        $this->newLine();
        $this->comment('Rotation order: 1) add to receiver  2) switch sender  3) remove the old ID from the receiver.');

        return self::SUCCESS;
    }
}
