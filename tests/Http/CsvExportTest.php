<?php

namespace RadioChatBox\Tests\Http;

use PHPUnit\Framework\TestCase;
use RadioChatBox\Controllers\ReportController;
use RadioChatBox\Http\Csv;

/**
 * The CSV export helper (UTF-8 BOM + header row + escaped cells) and that the
 * reports export endpoint returns a downloadable text/csv response.
 */
class CsvExportTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    /** build() writes a BOM, the header row, and each row; nulls become blanks. */
    public function testBuildProducesBomHeadersAndRows(): void
    {
        $csv = Csv::build(['a', 'b'], [[1, 'x'], [2, null]]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'has a UTF-8 BOM');
        $this->assertStringContainsString("a,b", $csv);
        $this->assertStringContainsString("1,x", $csv);
        $this->assertStringContainsString("2,", $csv); // null -> empty cell
    }

    /** A cell with a comma/quote is quoted per RFC 4180. */
    public function testBuildQuotesSpecialCells(): void
    {
        $csv = Csv::build(['h'], [['a, b'], ['say "hi"']]);
        $this->assertStringContainsString('"a, b"', $csv);
        $this->assertStringContainsString('"say ""hi"""', $csv);
    }

    /** download() sets a text/csv content type and an attachment filename. */
    public function testDownloadSetsCsvHeaders(): void
    {
        $r = Csv::download('x', 'reports.csv');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $r->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="reports.csv"', (string) $r->getHeaderLine('Content-Disposition'));
    }

    /** The reports export endpoint returns a CSV download with the header row. */
    public function testReportsExportReturnsCsv(): void
    {
        $_GET = ['status' => 'all'];
        $response = (new ReportController())->export();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('id,created_at,reason', $response->getBody());
    }
}
