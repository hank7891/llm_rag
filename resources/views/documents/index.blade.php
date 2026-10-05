@extends('layouts.app')

@section('title', '文件列表')

@section('content')
    <div class="mb-6 flex items-end justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">文件列表</h1>
            <p class="mt-1 text-sm text-slate-500">
                上傳後在背景解析；需另開終端機執行 <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">php artisan queue:listen</code>。
                @if ($autoRefresh)
                    <span class="text-amber-700">有文件處理中，每 3 秒自動更新。</span>
                @endif
            </p>
        </div>
        <a href="{{ route('documents.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">上傳文件</a>
    </div>

    @if ($documents->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-slate-500">
            還沒有文件，<a href="{{ route('documents.create') }}" class="text-indigo-600 hover:underline">上傳第一份文件</a>。
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">#</th>
                        <th class="px-4 py-3 font-medium">檔名</th>
                        <th class="px-4 py-3 font-medium">狀態</th>
                        <th class="px-4 py-3 text-right font-medium whitespace-nowrap">頁數</th>
                        <th class="px-4 py-3 text-right font-medium">大小</th>
                        <th class="px-4 py-3 font-medium">上傳時間</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($documents as $document)
                        <tr class="align-top">
                            <td class="px-4 py-3 text-slate-400">{{ $document->id }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900">{{ $document->name }}</div>
                                <div class="text-xs text-slate-400">{{ $document->mime_type }}</div>
                                @if ($duplicates[$document->id] ?? [])
                                    <div class="mt-1 text-xs text-amber-700">與 #{{ implode('、#', $duplicates[$document->id]) }} 內容相同</div>
                                @endif
                                @if ($document->error_message)
                                    <div class="mt-1 text-xs text-rose-700">{{ $document->error_message }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">@include('documents._status', ['status' => $document->status_key])</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $document->page_count ?? '—' }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-slate-500">{{ Illuminate\Support\Number::fileSize($document->size) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $document->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if ($document->status_key->hasPages())
                                    <a href="{{ route('documents.show', $document) }}" class="text-indigo-600 hover:underline">逐頁檢視</a>
                                @endif
                                @if ($document->status_key->canReprocess())
                                    <form method="POST" action="{{ route('documents.reprocess', $document) }}" class="ml-3 inline">
                                        @csrf
                                        <button class="text-slate-500 hover:text-slate-900">重新處理</button>
                                    </form>
                                @endif
                                @if ($document->status_key->canDelete())
                                    <form method="POST" action="{{ route('documents.destroy', $document) }}" class="ml-3 inline"
                                        onsubmit="return confirm('確定要刪除「{{ $document->name }}」？文件、切段與向量索引都會一併刪除。')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-rose-600 hover:text-rose-800">刪除</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $documents->links() }}</div>
    @endif
@endsection
