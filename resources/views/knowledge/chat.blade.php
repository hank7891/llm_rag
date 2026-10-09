@extends('layouts.app')

@section('title', '知識問答')

@push('scripts')
    @vite('resources/js/knowledge-chat.js')
@endpush

@section('content')
    <div id="chat" class="flex flex-col gap-4"
        data-ask-url="{{ url('/api/knowledge/ask') }}"
        data-stream-url="{{ url('/api/knowledge/ask/stream') }}"
        data-document-url="{{ url('/document') }}">
        <script type="application/json" id="chat-providers">@json($providers)</script>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">知識問答</h1>
                <p class="mt-1 text-sm text-slate-500">只依據已上傳的文件回答，可以連續追問。</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <label class="flex items-center gap-2">
                    <span class="text-slate-500">模型</span>
                    <select id="provider" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5">
                        @foreach ($providers as $name => $provider)
                            <option value="{{ $name }}" @selected($name === $defaultProvider)>{{ $provider['label'] }}{{ $provider['model'] ? '（'.$provider['model'].'）' : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-center gap-1.5 text-slate-500" title="關閉時改用非串流 API，完成後才一次顯示">
                    <input type="checkbox" id="streaming" checked class="rounded border-slate-300">
                    串流顯示
                </label>
                <button type="button" id="new-conversation" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-50">新對話</button>
            </div>
        </div>

        <div id="provider-note" class="rounded-lg border px-4 py-2 text-sm"></div>

        <div id="messages" class="flex min-h-64 flex-col gap-4">
            <div id="empty-state" class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-500">
                輸入問題開始對話，例如「公司的特休規定是什麼？」，再接著追問「那兩年年資呢？」。
            </div>
        </div>

        <form id="ask-form" class="sticky bottom-4 flex gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <textarea id="question" rows="2" maxlength="2000" required placeholder="輸入問題（Enter 送出，Shift + Enter 換行）"
                class="flex-1 resize-none rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"></textarea>
            <div class="flex flex-col gap-2">
                <button type="submit" id="send" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">送出</button>
                <button type="button" id="stop" class="hidden rounded-lg border border-rose-300 px-4 py-2 text-sm text-rose-700 hover:bg-rose-50">停止</button>
            </div>
        </form>
    </div>
@endsection
