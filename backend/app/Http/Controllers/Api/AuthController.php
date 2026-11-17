<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        // Week 15 (W2): always run one bcrypt check, even for an unknown email. Without this, an unknown
        // email answered in ~10 ms and a real one in ~230 ms, so the timing revealed which accounts exist.
        $passwordOk = Hash::check($data['password'], $user?->password ?? self::dummyHash());

        if (! $user || ! $passwordOk) {
            // Same message for unknown email and wrong password, so accounts can't be probed.
            Log::channel('automation')->notice('auth.login_failed', ['ip' => $request->ip()]);

            return ApiError::response(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
        }

        $token = $user->createToken('flutter-web', ['*'], now()->addHours(8));
        Log::channel('automation')->info('auth.login', ['user_id' => $user->id, 'role' => $user->role->value]);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * bcrypt hash (cost 12, same as BCRYPT_ROUNDS) of a random value nobody knows. It is not a secret:
     * it only makes the unknown-email path do the same amount of work as a real password check.
     * A fixed value is used because making a new hash on every request would cost a second bcrypt.
     */
    private const DUMMY_HASH = '$2y$12$3vJ2TguUuaSD27yq2ixf5.x.nf8e/cp0nZCnAcl9HHTmbeTkPA8hC';

    private static function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }
}
