<?php

namespace RadioChatBox\Http;

use Pramnos\Http\Response;

/**
 * Tiny CSV helper for admin exports: build a UTF-8 CSV string (with a BOM so
 * Excel opens Greek correctly) and wrap it in a downloadable Response.
 */
final class Csv
{
    /**
     * @param list<string>              $headers Column headers
     * @param iterable<array<int,mixed>> $rows   Each row is a list of cell values
     */
    public static function build(array $headers, iterable $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn ($v): string => $v === null ? '' : (string) $v, $row));
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        // UTF-8 BOM so Excel/LibreOffice detect the encoding and show Greek right.
        return "\xEF\xBB\xBF" . $csv;
    }

    /** A downloadable text/csv Response with the given filename. */
    public static function download(string $csv, string $filename): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.csv';
        return Response::make($csv, 200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
