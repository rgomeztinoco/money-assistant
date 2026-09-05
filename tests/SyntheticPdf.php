<?php

namespace Tests;

final class SyntheticPdf
{
    public static function fromText(string $text): string
    {
        return self::fromPages([$text]);
    }

    /** @param list<string> $pages */
    public static function fromPages(array $pages): string
    {
        $streams = array_map(function (string $text): string {
            $lines = preg_split('/\R/u', trim($text));
            $stream = "BT\n/F1 8 Tf\n40 800 Td\n10 TL\n";

            foreach ($lines as $line) {
                $escapedLine = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $stream .= "({$escapedLine}) Tj\nT*\n";
            }

            return $stream."ET\n";
        }, $pages);

        $pageCount = count($streams);
        $fontObjectNumber = 3 + $pageCount;
        $firstContentObjectNumber = $fontObjectNumber + 1;
        $pageObjectNumbers = range(3, 2 + $pageCount);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', array_map(
                fn (int $objectNumber): string => "{$objectNumber} 0 R",
                $pageObjectNumbers,
            ))."] /Count {$pageCount} >>",
        ];

        foreach ($pageObjectNumbers as $index => $pageObjectNumber) {
            $contentObjectNumber = $firstContentObjectNumber + $index;
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 {$fontObjectNumber} 0 R >> >> /Contents {$contentObjectNumber} 0 R >>";
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        foreach ($streams as $stream) {
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $objectNumber = $index + 1;
            $pdf .= "{$objectNumber} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $objectCount = count($objects);
        $pdf .= "xref\n0 ".($objectCount + 1)."\n0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n<< /Size ".($objectCount + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
