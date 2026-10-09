<?php

namespace App\Http\Controllers;

use App\Documents\DocumentService;
use App\Documents\Exceptions\DocumentBusyException;
use App\Documents\Exceptions\InvalidStatusTransitionException;
use App\Documents\Exceptions\StaleDocumentStatusException;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Document;
use App\Rag\VectorStore\Exceptions\QdrantException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(): View
    {
        [$documents, $duplicates] = $this->documents->paginateWithDuplicates();

        return view('documents.index', ['documents' => $documents, 'duplicates' => $duplicates]);
    }

    /** 列表頁輪詢用：只回傳狀態，狀態有變化時前端才重新載入頁面 */
    public function statuses(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->query('ids', []))));

        return new JsonResponse($this->documents->statuses(array_slice($ids, 0, 100)));
    }

    public function create(): View
    {
        return view('documents.upload', ['maxKb' => config('documents.max_upload_kb')]);
    }

    public function store(UploadDocumentRequest $request): RedirectResponse
    {
        $document = $this->documents->upload($request->file('file'));
        $same = $this->documents->namesWithSameContent($document);

        return redirect()->route('documents.index')
            ->with('success', "已上傳「{$document->name}」，正在背景解析。".($same === [] ? '' : '內容與既有的「'.implode('」、「', $same).'」相同。'));
    }

    public function show(Document $document, Request $request): View
    {
        $tab = $request->query('tab') === 'chunks' ? 'chunks' : 'pages';

        return view('documents.show', [
            'document' => $document,
            'tab' => $tab,
            'pages' => $tab === 'pages' ? $document->pages : collect(),
            'chunks' => $tab === 'chunks' ? $document->chunks : collect(),
            'chunkCount' => $document->chunks()->count(),
            'maxTokens' => config('rag.chunking.max_tokens'),
        ]);
    }

    public function destroy(Document $document): RedirectResponse
    {
        try {
            $this->documents->delete($document);
        } catch (DocumentBusyException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QdrantException $e) {
            return back()->with('error', "向量索引刪除失敗，文件沒有被刪除，請稍後再試。（{$e->getMessage()}）");
        }

        return redirect()->route('documents.index')->with('success', "已刪除「{$document->name}」與它的向量索引。");
    }

    public function reindex(Document $document): RedirectResponse
    {
        try {
            $this->documents->reindex($document);
        } catch (InvalidStatusTransitionException) {
            return back()->with('error', "「{$document->name}」目前的狀態無法重新索引，請重新整理頁面後再試。");
        }

        return back()->with('success', "已排入重新索引：「{$document->name}」。");
    }

    public function reprocess(Document $document): RedirectResponse
    {
        try {
            $this->documents->reprocess($document);
        } catch (InvalidStatusTransitionException|StaleDocumentStatusException) {
            return back()->with('error', "「{$document->name}」目前的狀態無法重新處理，請重新整理頁面後再試。");
        }

        return back()->with('success', "已重新排入處理：「{$document->name}」。");
    }
}
