@php
    $colors = [
        'uploaded' => 'bg-slate-100 text-slate-600',
        'parsing' => 'bg-amber-100 text-amber-800',
        'parsed' => 'bg-emerald-100 text-emerald-800',
        'chunking' => 'bg-amber-100 text-amber-800',
        'chunked' => 'bg-teal-100 text-teal-800',
        'indexing' => 'bg-sky-100 text-sky-800',
        'indexed' => 'bg-indigo-100 text-indigo-800',
        'failed' => 'bg-rose-100 text-rose-800',
    ];
@endphp
<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium {{ $colors[$status->value] }}">
    @if ($status->isProcessing())
        <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
    @endif
    {{ $status->label() }}
</span>
