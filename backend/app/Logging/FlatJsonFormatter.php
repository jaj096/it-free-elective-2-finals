<?php

namespace App\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Writes one flat JSON object per line, e.g.
 * {"timestamp":"2026-10-29T10:31:04+08:00","level":"info","event":"generation.started","correlation_id":"c-8f2d1e6a","request_id":42}
 *
 * The correlation ID comes from Laravel's Context, which follows a request into its queued jobs.
 */
class FlatJsonFormatter extends NormalizerFormatter
{
    public function __construct()
    {
        parent::__construct('Y-m-d\TH:i:sP');
    }

    public function format(LogRecord $record): string
    {
        $data = [
            'timestamp' => $record->datetime->format('Y-m-d\TH:i:sP'),
            'level' => strtolower($record->level->getName()),
            'event' => $record->message,
        ];

        // Laravel adds Context data (correlation_id) to "extra".
        foreach ($this->normalize($record->extra) as $key => $value) {
            $data[$key] = $value;
        }
        foreach ($this->normalize($record->context) as $key => $value) {
            $data[$key] = $value;
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
