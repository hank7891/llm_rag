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

    <nav class="mb-6 flex gap-6 border-b border-slate-200 text-sm">
        @foreach (['pages' => "逐頁內容（{$document->page_count} 頁）", 'chunks' => "切段結果（{$chunkCount} 段）"] as $key => $label)
            <a href="{{ route('documents.show', [$document, 'tab' => $key]) }}"
                class="-mb-px border-b-2 px-1 pb-2 {{ $tab === $key ? 'border-indigo-600 font-medium text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-900' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if ($tab === 'pages')
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
    @else
        @if ($chunks->isNotEmpty())
            <p class="mb-4 text-sm text-slate-500">
                策略 <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $chunks->first()->chunk_strategy }}</code>
                ｜Token 最短 {{ $chunks->min('token_count') }}、平均 {{ (int) round($chunks->avg('token_count')) }}、最長 {{ $chunks->max('token_count') }}（上限 {{ $maxTokens }}）
                ｜跨頁 {{ $chunks->filter(fn ($c) => $c->page_end > $c->page_start)->count() }} 段
            </p>
        @endif

        <div class="space-y-4">
            @forelse ($chunks as $chunk)
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <header class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-slate-100 bg-slate-50 px-4 py-2 text-sm">
                        <span class="font-mono text-xs text-slate-400">#{{ $chunk->chunk_index }}</span>
                        <span class="font-medium text-slate-700">{{ $chunk->section ?? '（無章節）' }}</span>
                        <span class="{{ $chunk->page_end > $chunk->page_start ? 'rounded bg-amber-100 px-1.5 text-amber-800' : 'text-slate-500' }}">
                            第 {{ $chunk->page_start === $chunk->page_end ? $chunk->page_start : "{$chunk->page_start}–{$chunk->page_end}" }} 頁
                        </span>
                        <span class="ml-auto text-xs text-slate-400">{{ $chunk->char_count }} 字｜約 {{ $chunk->token_count }} Token</span>
                    </header>
                    <div class="whitespace-pre-wrap px-4 py-4 text-[15px] leading-7">{{ $chunk->content }}</div>
                </section>
            @empty
                <p class="text-slate-500">這份文件還沒有切段。解析完成後會自動切段（需執行 <code>php artisan queue:listen</code>）。</p>
            @endforelse
        </div>
    @endif
@endsection
