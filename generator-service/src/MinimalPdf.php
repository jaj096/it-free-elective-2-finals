<?php

declare(strict_types=1);

/**
 * Builds a small, valid one-page PDF with plain text lines.
 * No libraries are needed, so the mock service has zero dependencies.
 */
final class MinimalPdf
{
    /**
     * @param array<int, array{text: string, size: int, y: int}> $lines
     */
    public static function render(array $lines): string
    {
        $content = '';
        foreach ($lines as $line) {
            $text = self::escape($line['text']);
            $size = $line['size'];
            // Rough centring for Helvetica: average glyph width is about 0.46 em.
            $x = max(40, (int) round((612 - strlen($line['text']) * $size * 0.46) / 2));
            $content .= "BT /F1 {$size} Tf {$x} {$line['y']} Td ({$text}) Tj ET\n";
        }
        // Simple border around the certificate.
        $content .= "2 w 36 36 540 720 re S\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private static function escape(string $text): string
    {
        // Keep printable ASCII only and escape PDF string delimiters.
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
