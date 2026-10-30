<?php

namespace App\Services;

use App\Exceptions\GeneratorException;
use App\Models\CertificateRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

/**
 * The only code that talks to the external Certificate Generator Service.
 * It turns every outcome into either PDF bytes or a GeneratorException that says
 * whether the failure is transient (retry) or permanent (send to a human).
 */
class GeneratorClient
{
    public function generate(CertificateRequest $request): string
    {
        $key = (string) config('certificates.generator.key');
        if ($key === '') {
            throw GeneratorException::permanent('Generator service key is not configured.');
        }

        // Data minimisation: only the fields printed on the certificate are sent.
        $payload = [
            'job_key' => $request->generation_key,
            'certificate_type' => $request->certificate_type->value,
            'requester_name' => $request->requester->name,
            'requester_id_number' => (string) $request->requester->id_number,
            'purpose' => $request->purpose,
        ];

        try {
            $response = Http::baseUrl(config('certificates.generator.url'))
                ->timeout(config('certificates.generator.timeout'))
                ->connectTimeout(5)
                ->acceptJson()
                ->withHeaders([
                    'X-Service-Key' => $key,
                    'X-Correlation-ID' => (string) Context::get('correlation_id'),
                ])
                ->post('/generate', $payload);
        } catch (ConnectionException) {
            throw GeneratorException::transient('Generator did not respond in time or could not be reached.');
        }

        $status = $response->status();

        if ($status >= 500 || $status === 429 || $status === 408) {
            throw GeneratorException::transient("Generator temporarily unavailable (HTTP {$status}).", $status);
        }

        if ($status === 401 || $status === 403) {
            throw GeneratorException::permanent('Generator rejected the service key. Check GENERATOR_SERVICE_KEY.', $status);
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
}
