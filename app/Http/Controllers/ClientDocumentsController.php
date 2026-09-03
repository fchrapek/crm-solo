<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Client-scoped documents (signed contracts, GDPR clauses, sub-processing
 * agreements). Files live LOCALLY on the `local` disk under
 * storage/app/private/client-documents/{client_id}/. Mirrors
 * TaskAttachmentsController; Client route binding is account-scoped.
 */
final class ClientDocumentsController extends Controller
{
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'odt', 'rtf',
        'png', 'jpg', 'jpeg', 'webp',
        'txt', 'md', 'csv', 'xls', 'xlsx',
    ];

    private const ALLOWED_MIMETYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/rtf', 'text/rtf',
        'image/png', 'image/jpeg', 'image/webp',
        'text/plain', 'text/markdown', 'text/x-markdown', 'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
    ];

    // 50 MB — signed contract scans can be large.
    private const MAX_FILE_KB = 51_200;

    public function store(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_KB,
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
                'mimetypes:'.implode(',', self::ALLOWED_MIMETYPES),
            ],
            'category' => ['nullable', 'string', 'max:50'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $validated['file'];
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $filename = Str::uuid()->toString().'.'.Str::lower($extension);
        $relativePath = "client-documents/{$client->id}/{$filename}";

        Storage::disk('local')->putFileAs("client-documents/{$client->id}", $file, $filename);

        $client->documents()->create([
            'account_id' => $client->account_id,
            'file_path' => $relativePath,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'category' => $validated['category'] ?? null,
            'label' => $validated['label'] ?? null,
        ]);

        return back()->with('flash', ['success' => __('Document uploaded.')]);
    }

    public function show(Client $client, ClientDocument $document): BinaryFileResponse|Response
    {
        $this->authorizeDocument($client, $document);

        $absolutePath = Storage::disk('local')->path($document->file_path);
        if (! is_file($absolutePath)) {
            abort(404);
        }

        return response()->file($absolutePath, [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function destroy(Client $client, ClientDocument $document): RedirectResponse
    {
        $this->authorizeDocument($client, $document);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return back()->with('flash', ['success' => __('Document deleted.')]);
    }

    private function authorizeDocument(Client $client, ClientDocument $document): void
    {
        if ($document->client_id !== $client->id || $client->account_id !== Auth::user()->account_id) {
            abort(403);
        }
    }
}
