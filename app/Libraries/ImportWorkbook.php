<?php

namespace App\Libraries;

use App\Services\DataImportService;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes the import workbook: an Instructions sheet plus one sheet per
 * record type with the column headers, optionally filled with rows
 * (the sample file is the demo data in the same format).
 */
class ImportWorkbook
{
    /**
     * @param array<string, list<array<string, mixed>>> $data sheet => rows (column => value)
     */
    public static function write(string $path, array $data = []): void
    {
        $options = new Options();
        $writer  = new Writer($options);
        $writer->openToFile($path);

        $title  = (new Style())->setFontBold()->setFontSize(14)->setFontColor('1B1890');
        $head   = (new Style())->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('2622B0');
        $bold   = (new Style())->setFontBold();
        $wrap   = (new Style())->setShouldWrapText();
        $date   = (new Style())->setFormat('yyyy-mm-dd');
        $money  = (new Style())->setFormat('#,##0.00');

        // Instructions
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Instructions');
        $options->setColumnWidth(26, 1);
        $options->setColumnWidth(90, 2);
        $writer->addRow(Row::fromValues(['GLC-BLOC data import'], $title));
        foreach ([
            'Fill in one sheet per kind of record, keep the header row, and upload the file on Import data in the admin panel.',
            'Columns marked * are required. Dates can be Excel dates or text like 2025-03-14.',
            'member_ref, account_ref and loan_ref are your own IDs; they only link the sheets together and are not stored.',
            'Everything is imported as already approved and added to the blockchain ledger in date order.',
            'Only released loans are imported. Payments are applied to the oldest unpaid month first, as on the payment date.',
            'Nothing is saved if any row has an error: fix the rows listed after "Check file" and upload again.',
        ] as $line) {
            $writer->addRow(Row::fromValues(['', $line], $wrap));
        }
        foreach (DataImportService::SHEETS as $name => $columns) {
            $writer->addRow(Row::fromValues(['']));
            $writer->addRow(Row::fromValues([$name . ' sheet'], $bold));
            foreach ($columns as $col => $help) {
                $writer->addRow(Row::fromValues([$col, $help], $wrap));
            }
        }

        // One sheet per record type
        foreach (DataImportService::SHEETS as $name => $columns) {
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $keys = array_keys($columns);
            foreach ($keys as $i => $col) {
                $sheet->setColumnWidth(max(14, strlen($col) + 4), $i + 1);
            }
            $writer->addRow(Row::fromValues($keys, $head));
            foreach ($data[$name] ?? [] as $record) {
                $cells = [];
                foreach ($keys as $col) {
                    $key   = rtrim($col, '*');
                    $value = $record[$key] ?? null;
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && str_contains($key, 'date')) {
                        $cells[] = Cell::fromValue(new \DateTimeImmutable($value), $date);
                    } elseif (is_float($value) || ($key === 'amount' || $key === 'loan_amount') && is_numeric($value)) {
                        $cells[] = Cell::fromValue((float) $value, $money);
                    } else {
                        $cells[] = Cell::fromValue($value);
                    }
                }
                $writer->addRow(new Row($cells));
            }
        }

        $writer->close();
    }
}
