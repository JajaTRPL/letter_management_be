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

    /**
     * Proxy to fetch live PDF content from Google Docs
     */
    public function proxyGoogleDoc($id)
    {
        try {
            $url = "https://docs.google.com/document/d/{$id}/export?format=pdf";
            
            // Fetch content using file_get_contents with a context to handle redirects
            $options = [
                'http' => [
                    'follow_location' => true,
                    'max_redirects' => 10,
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
                ]
            ];
            $context = stream_context_create($options);
            $content = @file_get_contents($url, false, $context);

            if ($content === false) {
                throw new \Exception('Gagal mengambil konten dari Google Docs. Pastikan dokumen bersifat publik (Anyone with the link can view).');
            }

            return response($content)
                ->header('Content-Type', 'application/pdf')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil pratinjau live',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
