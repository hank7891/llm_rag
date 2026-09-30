@extends('layouts.app')

@section('title', $document->name)

@section('content')
    <a href="{{ route('documents.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← 文件列表</a>

    <div class="mt-3 mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold text-slate-900">{{ $document->name }}</h1>
        @include('documents._status', ['status' => $document->status_key])
    </div>

    <dl class="mb-8 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-4 text-sm sm:grid-cols-4">
        <div><dt class="text-slate-400">頁數</dt><dd class="mt-1 font-medium">{{ $document->page_count ?? '—' }}</dd></div>
        <div><dt class="text-slate-400">類型</dt><dd class="mt-1 font-medium">{{ $document->mime_type }}</dd></div>
        <div><dt class="text-slate-400">大小</dt><dd class="mt-1 font-medium">{{ Illuminate\Support\Number::fileSize($document->size) }}</dd></div>
        <div><dt class="text-slate-400">SHA-256</dt><dd class="mt-1 truncate font-mono text-xs" title="{{ $document->sha256 }}">{{ $document->sha256 }}</dd></div>
    </dl>

    @if ($pages->count() > 1)
        <nav class="mb-6 flex flex-wrap gap-2 text-sm">
            @foreach ($pages as $page)
                <a href="#page-{{ $page->page_number }}" class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-slate-600 hover:border-indigo-300 hover:text-indigo-700">第 {{ $page->page_number }} 頁</a>
            @endforeach
        </nav>
    @endif

    <div class="space-y-6">
        @forelse ($pages as $page)
            <section id="page-{{ $page->page_number }}" class="scroll-mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white">
                <header class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-4 py-2 text-sm">
                    <span class="font-medium text-slate-700">第 {{ $page->page_number }} 頁</span>
                    <span class="text-xs text-slate-400">{{ mb_strlen($page->content) }} 字</span>
                </header>
                @if ($page->content === '')
                    <p class="px-4 py-6 text-sm italic text-slate-400">（空白頁）</p>
                @else
                    {{-- whitespace-pre-wrap：保留正規化後的換行與空行，才能檢查段落是否正確 --}}
                    <div class="whitespace-pre-wrap px-4 py-4 text-[15px] leading-7">{{ $page->content }}</div>
                @endif
            </section>
        @empty
            <p class="text-slate-500">這份文件還沒有解析出頁面。</p>
        @endforelse
    </div>
@endsection
