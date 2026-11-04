<?php

namespace App\Services;

use App\Exceptions\GeneratorException;
use App\Models\CertificateRequest;
use App\Security\SecureRequestBuilder;
use App\Security\SignedRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

/**
 * The only code that talks to the external Certificate Generator Service.
 * It turns every outcome into either PDF bytes or a GeneratorException that says
 * whether the failure is transient (retry) or permanent (send to a human).
 *
 * Week 14: the request is minimised, the personal fields are encrypted, the whole
 * request is HMAC-signed (see App\Security), it is only sent over HTTPS, and the
 * generator's TLS certificate is verified.
 */
class GeneratorClient
{
    public function __construct(private SecureRequestBuilder $builder) {}

    public function generate(CertificateRequest $request): string
    {
        // Throws a permanent GeneratorException if the keys are missing or invalid.
        $signed = $this->builder->build($this->printedFields($request));

        try {
            $response = $this->send($signed);
        } catch (ConnectionException) {
            throw GeneratorException::transient('Generator did not respond in time or could not be reached.');
        }

        $status = $response->status();

        if ($status >= 500 || $status === 429 || $status === 408) {
            throw GeneratorException::transient("Generator temporarily unavailable (HTTP {$status}).", $status);
        }

        if (in_array($status, [401, 403, 409], true)) {
            // Security rejections (bad signature, expired, replay, unknown key ID, HTTPS required).
            // Retrying the same thing cannot help, so a human checks the configuration.
            $code = $this->safeCode($response);
            throw GeneratorException::permanent("Generator refused the request security check ({$code}). Check the generator keys and URL.", $status);
        }

        if ($status >= 400) {
            $message = $response->json('error.message') ?? 'Generator rejected the request.';
            throw GeneratorException::permanent("Generator rejected the record (HTTP {$status}): {$message}", $status);
        }

        $pdf = base64_decode((string) $response->json('pdf_base64'), true);
        if ($pdf === false || ! str_starts_with($pdf, '%PDF')) {
            throw GeneratorException::permanent('Generator returned an invalid PDF.', $status);
        }

        return $pdf;
    }

    /**
     * Data minimisation: only what the certificate prints. No email, user ID or role.
     *
     * @return array<string, string>
     */
    public function printedFields(CertificateRequest $request): array
    {
        return [
            'job_key' => (string) $request->generation_key,
            'certificate_type' => $request->certificate_type->value,
            'purpose' => (string) $request->purpose,
            'requester_name' => (string) $request->requester->name,
            'requester_id_number' => (string) $request->requester->id_number,
        ];
    }

    /**
     * Sends a signed request. Refuses plain HTTP unless $allowPlainHttp is set
     * (only the failure-injection command does that, to prove the receiver also refuses it).
     *
     * @throws GeneratorException when the URL is not HTTPS
     * @throws ConnectionException
     */
    public function send(SignedRequest $signed, ?string $baseUrl = null, bool $allowPlainHttp = false): Response
    {
        $baseUrl ??= (string) config('certificates.generator.url');

        if (! $allowPlainHttp && config('certificates.generator.require_https') && ! str_starts_with(strtolower($baseUrl), 'https://')) {
            throw GeneratorException::permanent('Refusing to send personal data over plain HTTP. Set GENERATOR_URL to an https:// address.');
        }

        return self::http($baseUrl)
            ->timeout((int) config('certificates.generator.timeout'))
            ->connectTimeout(5)
            ->acceptJson()
            ->withHeaders($signed->headers + ['X-Correlation-ID' => (string) Context::get('correlation_id')])
            ->withBody($signed->body, 'application/json')
            ->send($signed->method, $signed->path);
    }

    /**
     * HTTP client for the generator with TLS certificate verification ON.
     * GENERATOR_CA_BUNDLE points to the local development CA; when empty the system trust store is used.
     * Verification is never switched off.
     */
    public static function http(?string $baseUrl = null): PendingRequest
    {
        $ca = (string) config('certificates.generator.ca_bundle');

        return Http::baseUrl($baseUrl ?? (string) config('certificates.generator.url'))
            ->withOptions(['verify' => $ca !== '' ? $ca : true]);
    }

    private function safeCode(Response $response): string
    {
        $code = (string) $response->json('error.code');

        return preg_match('/^[A-Z_]{3,40}$/', $code) ? $code : 'HTTP_'.$response->status();
    }
}
