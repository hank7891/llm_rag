@extends('layouts.app')

@section('title', '上傳文件')

@section('content')
    <h1 class="text-2xl font-semibold text-slate-900">上傳文件</h1>
    <p class="mt-1 text-sm text-slate-500">支援 PDF（文字型）、TXT（UTF-8 / Big5）、Markdown，單檔不超過 {{ Illuminate\Support\Number::fileSize($maxKb * 1024) }}。掃描型 PDF 目前不支援。</p>

    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="mt-6 rounded-xl border border-slate-200 bg-white p-6">
        @csrf

        <label for="file" class="block text-sm font-medium text-slate-700">選擇檔案</label>
        <input id="file" name="file" type="file" accept=".pdf,.txt,.md,.markdown" required
            class="mt-2 block w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">

        @error('file')
            <p class="mt-2 text-sm text-rose-700">{{ $message }}</p>
        @enderror

        <div class="mt-6 flex items-center gap-4">
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">上傳並解析</button>
            <a href="{{ route('documents.index') }}" class="text-sm text-slate-500 hover:text-slate-900">取消</a>
        </div>
    </form>
@endsection
