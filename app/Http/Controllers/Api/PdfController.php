<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use RuntimeException;

class PdfController extends Controller
{
    /**
     * Convert a DOCX file to PDF using LibreOffice headless.
     * The resulting PDF is a pixel-identical mirror of the DOCX — the same as
     * opening the file in Word / LibreOffice and choosing "Save as PDF".
     *
     * POST /api/pdf/from-docx
     * Body: multipart/form-data  { file: <docx binary>, filename: "exam.docx" }
     */
    public function fromDocx(Request $request): Response
    {
        $request->validate([
            'file' => ['required', 'file'],
            'filename' => ['required', 'string', 'max:255'],
        ]);

        $soffice = $this->findSoffice();

        // Write the uploaded DOCX to a uniquely-named temp file
        $tmpDir = sys_get_temp_dir();
        $tmpBase = $tmpDir.DIRECTORY_SEPARATOR.'cnsync_'.uniqid();
        $docxPath = $tmpBase.'.docx';
        $pdfPath = $tmpBase.'.pdf';

        try {
            $request->file('file')->move($tmpDir, basename($docxPath));

            // LibreOffice outputs <filename>.pdf next to the source file
            $cmd = sprintf(
                '%s --headless --convert-to pdf %s --outdir %s 2>&1',
                $soffice,
                escapeshellarg($docxPath),
                escapeshellarg($tmpDir),
            );

            $output = shell_exec($cmd);
            $expected = $tmpDir.DIRECTORY_SEPARATOR.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

            if (! file_exists($expected)) {
                throw new RuntimeException('LibreOffice did not produce a PDF. Output: '.$output);
            }

            $pdfBytes = (string) file_get_contents($expected);
        } finally {
            if (file_exists($docxPath)) {
                @unlink($docxPath);
            }
            if (file_exists($pdfPath)) {
                @unlink($pdfPath);
            }
        }

        $safeName = preg_replace(
            '/[^A-Za-z0-9_\-.]/',
            '_',
            preg_replace('/\.docx$/i', '.pdf', $request->input('filename', 'exam.pdf')),
        );

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
        ]);
    }

    /**
     * Fallback: generate PDF from an HTML string via mPDF.
     * Used when no DOCX is available (e.g. assessments without a saved document).
     *
     * POST /api/pdf
     * Body: JSON  { html: "...", filename: "exam.pdf" }
     */
    public function generate(Request $request): Response
    {
        $validated = $request->validate([
            'html' => ['required', 'string'],
            'filename' => ['required', 'string', 'max:255'],
        ]);

        try {
            $mpdf = new Mpdf([
                'format' => 'Letter',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 15,
                'margin_bottom' => 15,
                'default_font' => 'dejavuserif',
                'mode' => 'utf-8',
            ]);

            $mpdf->SetTitle(pathinfo($validated['filename'], PATHINFO_FILENAME));
            $mpdf->WriteHTML((string) $validated['html']);
            $pdfBytes = $mpdf->Output('', 'S');
        } catch (MpdfException $e) {
            return response('PDF generation failed: '.$e->getMessage(), 500);
        }

        $safeName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $validated['filename']);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$safeName.'"',
        ]);
    }

    /**
     * Locate the LibreOffice binary across Windows, macOS, and Linux.
     */
    private function findSoffice(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            ];
            foreach ($candidates as $path) {
                if (file_exists($path)) {
                    return escapeshellarg($path);
                }
            }

            return 'soffice'; // hope it is in PATH
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $mac = '/Applications/LibreOffice.app/Contents/MacOS/soffice';
            if (file_exists($mac)) {
                return escapeshellarg($mac);
            }
        }

        // Linux / any other — expect `soffice` in PATH
        return 'soffice';
    }
}
