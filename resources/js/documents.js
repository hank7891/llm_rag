// 文件列表（/document）：刪除確認與處理狀態輪詢。

// 確認文字從 data-confirm 以純文字讀取（檔名寫進 onsubmit 的 JS 字串會被 HTML 實體還原，造成 XSS）
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

// 有文件還在處理時，每 3 秒只查詢這些文件的狀態；有變化才重新載入頁面，全部完成就停止
const rows = document.getElementById('document-rows');
const processing = rows ? [...rows.querySelectorAll('tr[data-processing="1"]')] : [];

if (processing.length > 0) {
    document.getElementById('polling-note')?.classList.remove('hidden');
    const url = new URL(rows.dataset.statusesUrl, window.location.href);
    processing.forEach((row) => url.searchParams.append('ids[]', row.dataset.documentId));

    const timer = setInterval(async () => {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const statuses = await response.json();
            if (processing.some((row) => statuses[row.dataset.documentId] !== row.dataset.status)) {
                clearInterval(timer);
                window.location.reload();
            }
        } catch {
            // 暫時連不上時等下一輪
        }
    }, 3000);
}
