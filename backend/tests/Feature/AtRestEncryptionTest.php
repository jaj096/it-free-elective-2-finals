<?php

namespace Tests\Feature;

use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Week 14: requester ID numbers are ciphertext in the database; passwords are bcrypt hashes. */
class AtRestEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_id_number_is_stored_as_ciphertext_and_read_back_as_plaintext(): void
    {
        $user = User::factory()->create(['id_number' => '2026-00001']);

        $raw = DB::table('users')->where('id', $user->id)->value('id_number');
        $this->assertNotSame('2026-00001', $raw);
        $this->assertStringNotContainsString('2026-00001', $raw);
        $this->assertSame('2026-00001', Crypt::decryptString($raw)); // Laravel encrypter, APP_KEY
        $this->assertSame('2026-00001', $user->fresh()->id_number);  // model decrypts transparently

        // Same value twice -> different ciphertext (random IV), so equal IDs can't be spotted in the table.
        $other = User::factory()->create(['id_number' => '2026-00001']);
        $this->assertNotSame($raw, DB::table('users')->where('id', $other->id)->value('id_number'));
    }

    public function test_altered_ciphertext_in_database_is_detected(): void
    {
        $user = User::factory()->create(['id_number' => '2026-00001']);
        $raw = DB::table('users')->where('id', $user->id)->value('id_number');
        $payload = json_decode(base64_decode($raw), true);
        $payload['value'] = base64_encode(strrev(base64_decode($payload['value'])));
        DB::table('users')->where('id', $user->id)->update(['id_number' => base64_encode(json_encode($payload))]);

        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $user->fresh()->id_number;
    }

    public function test_password_is_a_bcrypt_hash_not_reversible_ciphertext(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);
        $raw = DB::table('users')->where('id', $user->id)->value('password');

        $this->assertStringStartsWith('$2y$', $raw);
        $this->assertTrue(Hash::check('Password123!', $raw));
    }

    public function test_migration_encrypts_existing_plaintext_rows_once(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Legacy User', 'email' => 'legacy@example.test', 'password' => Hash::make('x'),
            'role' => 'requester', 'id_number' => '2026-09999', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_04_000100_encrypt_user_id_numbers.php');
        $migration->up();
        $first = DB::table('users')->where('id', $id)->value('id_number');
        $migration->up(); // running again must not double-encrypt

        $this->assertSame($first, DB::table('users')->where('id', $id)->value('id_number'));
        $this->assertSame('2026-09999', User::find($id)->id_number);
    }

    public function test_api_never_returns_the_full_id_number(): void
    {
        $requester = User::factory()->create(['id_number' => '2026-00001']);
        $request = CertificateRequest::factory()->for($requester, 'requester')->create();

        Sanctum::actingAs($requester);
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $this->assertStringNotContainsString('2026-00001', $me->getContent());
        $this->assertStringNotContainsString('2026-00001', $this->getJson("/api/v1/requests/{$request->id}")->getContent());

        Sanctum::actingAs(User::factory()->approver()->create());
        $this->getJson("/api/v1/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.requester.id_number_masked', '2026-***01')
            ->assertJsonPath('data.requester.has_id_number', true)
            ->assertJsonMissingPath('data.requester.id_number');
    }
}
