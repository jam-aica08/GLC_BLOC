<?php

namespace App\Controllers;

use App\Libraries\AuditLog;
use App\Libraries\DemoData;
use App\Libraries\ImportWorkbook;
use App\Services\DataImportService;

/**
 * Admin: bring a cooperative's existing records in from an Excel workbook.
 */
class DataImport extends BaseController
{
    private const MAX_UPLOAD_MB = 4;   // Vercel accepts request bodies up to 4.5 MB.

    public function index(): string
    {
        return view('admin/import', ['result' => null]);
    }

    /** GET: the empty workbook to fill in. */
    public function template()
    {
        return $this->download('glc-bloc-import-template.xlsx', []);
    }

    /** GET: the template filled with made-up demo records. */
    public function sample()
    {
        return $this->download('glc-bloc-import-sample.xlsx', DemoData::build());
    }

    /** POST: check the uploaded workbook, and import it when asked and error-free. */
    public function upload(): string
    {
        $file   = $this->request->getFile('workbook');
        $import = $this->request->getPost('action') === 'import';
        $result = ['import' => $import, 'name' => $file?->getClientName() ?? '', 'errors' => [], 'warnings' => [], 'counts' => [], 'done' => null];

        if (! $file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $result['errors'][] = ['sheet' => '', 'row' => 0, 'column' => '', 'message' => 'Choose an .xlsx file to upload.'];
            return view('admin/import', ['result' => $result]);
        }
        if (! $file->isValid()) {
            $result['errors'][] = ['sheet' => '', 'row' => 0, 'column' => '', 'message' => 'The upload did not arrive: ' . $file->getErrorString()];
            return view('admin/import', ['result' => $result]);
        }
        if (strtolower($file->getClientExtension()) !== 'xlsx') {
            $result['errors'][] = ['sheet' => '', 'row' => 0, 'column' => '', 'message' => 'Upload an Excel workbook (.xlsx). Save the template as "Excel Workbook" if you edited it elsewhere.'];
            return view('admin/import', ['result' => $result]);
        }
        if ($file->getSize() > self::MAX_UPLOAD_MB * 1024 * 1024) {
            $result['errors'][] = ['sheet' => '', 'row' => 0, 'column' => '', 'message' => 'The file is larger than ' . self::MAX_UPLOAD_MB . ' MB. Split it into smaller files.'];
            return view('admin/import', ['result' => $result]);
        }

        set_time_limit(120);
        $service = new DataImportService();
        try {
            $check = $service->validate($service->read($file->getTempName()));
        } catch (\Throwable $e) {
            // Details (paths, library errors) go to the log, not the page.
            log_message('error', 'Import read failed: ' . $e->getMessage());
            $result['errors'][] = ['sheet' => '', 'row' => 0, 'column' => '', 'message' => 'This file could not be read as an Excel workbook. Open it in Excel, save it as "Excel Workbook (.xlsx)" and try again.'];
            return view('admin/import', ['result' => $result]);
        }

        $result = array_merge($result, ['errors' => $check['errors'], 'warnings' => $check['warnings'], 'counts' => $check['counts']]);

        if ($import && $check['errors'] === []) {
            try {
                $result['done'] = $service->import($check['plan'], (int) session()->get('user_id'));
                AuditLog::record('Imported data', null, null, $result['name'] . ': ' . implode(', ', array_map(
                    fn($k, $v) => "{$v} {$k}", array_keys($result['done']['counts']), $result['done']['counts'])));
            } catch (\Throwable $e) {
                log_message('error', 'Import failed: ' . $e->getMessage());
                $result['errors'][] = ['sheet' => '', 'row' => 0, 'column' => '', 'message' => 'The import stopped and nothing was saved. Please try again; if it keeps failing, the details are in the server log (' . date('Y-m-d H:i') . ').'];
            }
        }

        return view('admin/import', ['result' => $result]);
    }

    private function download(string $name, array $data)
    {
        $path = tempnam(sys_get_temp_dir(), 'glc') . '.xlsx';
        ImportWorkbook::write($path, $data);
        $body = file_get_contents($path);
        @unlink($path);

        return $this->response->download($name, $body)
            ->setContentType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
