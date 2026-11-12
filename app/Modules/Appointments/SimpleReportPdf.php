<?php

declare(strict_types=1);

namespace App\Modules\Appointments;

/**
 * Minimal PDF writer for administrative report tables (no external package).
 */
final class SimpleReportPdf
{
    private int $y = 800;

    /** @var list<string> */
    private array $content = [];

    /**
     * @param  list<string>  $summary
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function render(string $title, array $summary, array $headers, array $rows): string
    {
        $this->y = 800;
        $this->content = [];
        $this->text(50, $this->y, $this->latin($title), 16);
        $this->y -= 24;
        foreach ($summary as $line) {
            $this->text(50, $this->y, $this->latin($line), 10);
            $this->y -= 14;
        }
        $this->y -= 10;
        $this->text(50, $this->y, $this->latin(implode(' | ', $headers)), 9);
        $this->y -= 16;
        foreach ($rows as $row) {
            if ($this->y < 60) {
                $this->text(50, $this->y, $this->latin('(continúa...)'), 9);
                break;
            }
            $this->text(50, $this->y, $this->latin(implode(' | ', $row)), 8);
            $this->y -= 12;
        }

        $stream = implode("\n", $this->content)."\n";
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
        $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= '0 '.count($offsets)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer << /Size '.count($offsets).' /Root 1 0 R >>'."\n";
        $pdf .= "startxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function text(int $x, int $y, string $value, int $size): void
    {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
        $this->content[] = 'BT /F1 '.$size.' Tf '.$x.' '.$y.' Td ('.$safe.') Tj ET';
    }

    private function latin(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);

        return is_string($converted) ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
    }
}
