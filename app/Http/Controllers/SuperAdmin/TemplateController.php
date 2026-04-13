<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    /**
     * Update an existing PDF template with new HTML content
     */
    public function updatePdf(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'html' => 'required|string',
        ]);

        try {
            // Slugify the name to use as filename
            $filename = Str::slug($request->name) . '.pdf';
            
            // Format HTML exactly as needed by DOMPDF
            $fontFamily = "Arial, Helvetica, sans-serif";
            $html = '
                <!DOCTYPE html>
                <html lang="id">
                <head>
                    <meta charset="UTF-8">
                    <style>
                        body {
                            font-family: ' . $fontFamily . ';
                            line-height: 1.6;
                            margin: 40px;
                        }
                    </style>
                </head>
                <body>
                    ' . $request->html . '
                </body>
                </html>
            ';

            // Generate PDF
            $pdf = Pdf::loadHTML($html);
            
            // Save to storage/app/public/templates
            $path = 'templates/' . $filename;
            Storage::disk('public')->put($path, $pdf->output());

            return response()->json([
                'message' => 'Template PDF berhasil diperbarui',
                'pdfUrl' => '/api/storage/' . $path
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memperbarui PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
