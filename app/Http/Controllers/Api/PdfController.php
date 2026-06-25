<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mpdf\Mpdf;
use Mpdf\MpdfException;

class PdfController extends Controller
{
    /**
     * Accept an HTML string from the frontend and return it as a downloaded PDF.
     * mPDF renders HTML natively — no html2canvas, no oklch issues.
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

        $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $validated['filename']);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
