// 知識問答頁（/knowledge/chat）。
// 安全規則：模型輸出、文件名稱、改寫後的問題都是不受信任的文字，一律以 textContent / createTextNode 插入，不使用 innerHTML。
// done 事件（或非串流回應）是唯一的真相：串流中的 delta 只是暫時顯示，收到 done 後以清理過的 answer 與 citations 重畫。
// conversation_id 只存在頁面記憶體中：重新載入頁面就是新對話。若保留 id 卻看不到先前的訊息，下一題會被當成追問改寫，畫面與系統狀態不一致。

const root = document.getElementById('chat');
const providers = JSON.parse(document.getElementById('chat-providers').textContent);
const els = {
    provider: document.getElementById('provider'),
    providerNote: document.getElementById('provider-note'),
    streaming: document.getElementById('streaming'),
    newConversation: document.getElementById('new-conversation'),
    messages: document.getElementById('messages'),
    emptyState: document.getElementById('empty-state'),
    form: document.getElementById('ask-form'),
    question: document.getElementById('question'),
    send: document.getElementById('send'),
    stop: document.getElementById('stop'),
};

const STAGES = [
    ['rewriting', '理解問題'],
    ['retrieving', '搜尋文件'],
    ['generating', '產生回答'],
];

let conversationId = null;
let abortController = null;

// ---- 對話狀態 ----

function setConversation(id) {
    conversationId = id;
    // 改寫跟隨回答 Provider：同一段對話不可中途更換（伺服器也會檢查）
    els.provider.disabled = id !== null;
    els.provider.title = id !== null ? '要更換模型請開始新對話' : '';
}

function updateProviderNote() {
    const provider = providers[els.provider.value];
    const local = provider?.local === true;
    els.providerNote.className = `rounded-lg border px-4 py-2 text-sm ${local ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'}`;
    els.providerNote.textContent = local
        ? `地端模型：問題與文件內容只在本機處理。${conversationId !== null ? `（對話 #${conversationId}）` : ''}`
        : `雲端模型：問題、對話紀錄與參考資料會送到 ${provider?.label ?? els.provider.value}。${conversationId !== null ? `（對話 #${conversationId}）` : ''}`;
}

// ---- 畫面元件 ----

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function appendUserMessage(text) {
    els.emptyState.remove();
    const bubble = el('div', 'ml-auto max-w-[80%] rounded-xl bg-indigo-600 px-4 py-2 text-sm whitespace-pre-wrap text-white', text);
    els.messages.append(bubble);
}

function createAssistantMessage() {
    const card = el('article', 'max-w-[90%] rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm');
    const progress = el('div', 'mb-2 flex flex-wrap items-center gap-2 text-xs text-slate-400');
    const steps = Object.fromEntries(STAGES.map(([key, label]) => {
        const step = el('span', 'rounded-full bg-slate-100 px-2 py-0.5', label);
        progress.append(step);
        return [key, step];
    }));
    const elapsed = el('span', 'ml-auto tabular-nums');
    progress.append(elapsed);

    const rewrite = el('details', 'mb-2 hidden text-xs text-slate-500');
    const rewriteSummary = el('summary', 'cursor-pointer', '檢索用問題');
    const rewriteText = el('div', 'mt-1 rounded bg-slate-50 px-2 py-1');
    rewrite.append(rewriteSummary, rewriteText);

    const notice = el('div', 'mb-2 hidden rounded bg-amber-50 px-2 py-1 text-xs text-amber-800');
    const body = el('div', 'whitespace-pre-wrap leading-relaxed text-slate-400');
    const sources = el('div', 'mt-3 hidden border-t border-slate-100 pt-2 text-xs');
    const meta = el('div', 'mt-2 hidden text-xs text-slate-400');

    card.append(progress, rewrite, notice, body, sources, meta);
    els.messages.append(card);

    const startedAt = Date.now();
    const timer = setInterval(() => {
        elapsed.textContent = `已等待 ${Math.round((Date.now() - startedAt) / 1000)} 秒`;
    }, 1000);

    const view = {
        stage(stage) {
            let reached = true;
            for (const [key] of STAGES) {
                steps[key].className = key === stage
                    ? 'rounded-full bg-indigo-100 px-2 py-0.5 text-indigo-700 animate-pulse'
                    : reached ? 'rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700' : 'rounded-full bg-slate-100 px-2 py-0.5';
                if (key === stage) reached = false;
            }
        },
        rewrite(data) {
            if (data.rewrite_status === 'skipped') return;
            rewrite.classList.remove('hidden');
            rewriteText.textContent = data.rewritten_question;
            rewriteSummary.textContent = {
                rewritten: '檢索用問題（已改寫）',
                unchanged: '檢索用問題（與原問題相同）',
                fallback: '改寫失敗，以原問題搜尋',
            }[data.rewrite_status] ?? '檢索用問題';
            if (data.rewrite_status === 'fallback') view.notice('改寫失敗，以原問題搜尋文件。');
        },
        retrieval(data) {
            if (data.rerank_degraded) view.notice('重新排序服務沒有回應，使用原本的排序。');
        },
        notice(text) {
            notice.classList.remove('hidden');
            notice.textContent = notice.textContent ? `${notice.textContent} ${text}` : text;
        },
        delta(text) {
            body.append(document.createTextNode(text));
        },
        done(answer) {
            clearInterval(timer);
            elapsed.textContent = `共 ${Math.round((Date.now() - startedAt) / 1000)} 秒`;
            for (const [key] of STAGES) steps[key].className = 'rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700';
            if (!answer.llm_called) steps.generating.className = 'rounded-full bg-slate-100 px-2 py-0.5 line-through';

            view.rewrite({ rewritten_question: answer.rewritten_question, rewrite_status: answer.rewrite_status });
            renderAnswer(body, sources, answer);

            meta.classList.remove('hidden');
            meta.textContent = [
                answer.model ? `${answer.provider} / ${answer.model}` : `${answer.provider}（未呼叫模型）`,
                answer.rewrite_ms !== null ? `改寫 ${answer.rewrite_ms} ms` : null,
                `檢索 ${answer.timing.retrieval_ms} ms`,
                answer.timing.llm_ms !== null ? `回答 ${answer.timing.llm_ms} ms` : null,
            ].filter(Boolean).join(' · ');
        },
        stopped() {
            clearInterval(timer);
            elapsed.textContent = '已停止';
            body.className = 'whitespace-pre-wrap leading-relaxed text-slate-400';
            body.append(el('span', 'ml-1 text-xs', '（已停止，這則回答不會列入後續追問的歷史）'));
        },
        error(message) {
            clearInterval(timer);
            elapsed.textContent = '';
            body.className = 'rounded bg-rose-50 px-2 py-1 text-rose-700';
            body.textContent = message;
        },
    };

    return view;
}

// 回答：[n] 只在對得到 citations 時轉成來源標記；來源清單只從 citations 產生（程式依 MySQL 對應，不解析模型輸出）
function renderAnswer(body, sources, answer) {
    body.replaceChildren();
    sources.replaceChildren();
    sources.classList.add('hidden');

    if (answer.status === 'insufficient_no_candidates' || answer.status === 'insufficient_by_llm') {
        body.className = 'rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900';
        body.textContent = answer.answer;
        body.append(el('div', 'mt-1 text-xs text-amber-700', answer.status === 'insufficient_no_candidates'
            ? '沒有找到相關的文件段落，未呼叫模型。'
            : '模型判斷參考資料不足以回答。'));
        return;
    }

    if (answer.status === 'interrupted') {
        body.className = 'whitespace-pre-wrap leading-relaxed text-slate-400';
        body.textContent = answer.answer;
        body.append(el('span', 'ml-1 text-xs', '（已停止，這則回答不會列入後續追問的歷史）'));
        return;
    }

    body.className = 'whitespace-pre-wrap leading-relaxed text-slate-800';
    const labels = new Map(answer.citations.map((c) => [String(c.ref), c.label]));
    for (const part of answer.answer.split(/(\[\d+\])/)) {
        const ref = part.match(/^\[(\d+)\]$/)?.[1];
        if (ref !== undefined && labels.has(ref)) {
            const mark = el('sup', 'ml-0.5 cursor-help rounded bg-indigo-50 px-1 text-indigo-700', `[${ref}]`);
            mark.title = labels.get(ref);
            body.append(mark);
        } else {
            body.append(document.createTextNode(part));
        }
    }

    if (answer.citations.length > 0) {
        sources.classList.remove('hidden');
        sources.append(el('div', 'mb-1 font-medium text-slate-500', '資料來源'));
        const list = el('ol', 'space-y-0.5 text-slate-600');
        for (const citation of answer.citations) {
            const item = el('li', '', `[${citation.ref}] `);
            const link = el('a', 'text-indigo-600 hover:underline', citation.label);
            link.href = `${root.dataset.documentUrl}/${Number(citation.document_id)}`;
            link.target = '_blank';
            link.rel = 'noopener';
            item.append(link);
            list.append(item);
        }
        sources.append(list);
    }
}

// ---- 送出問題 ----

async function errorMessage(response) {
    try {
        const json = await response.json();
        if (json.errors) return Object.values(json.errors).flat()[0];
        return json.error?.message ?? json.message ?? `HTTP ${response.status}`;
    } catch {
        return `HTTP ${response.status}`;
    }
}

// 逐一解析 SSE 事件（event: 名稱、data: JSON、空行結束）
async function readEvents(response, onEvent) {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { value, done } = await reader.read();
        if (done) return;
        buffer += decoder.decode(value, { stream: true });

        let end;
        while ((end = buffer.indexOf('\n\n')) !== -1) {
            const raw = buffer.slice(0, end);
            buffer = buffer.slice(end + 2);
            let event = 'message';
            const data = [];
            for (const line of raw.split('\n')) {
                if (line.startsWith('event: ')) event = line.slice(7);
                else if (line.startsWith('data: ')) data.push(line.slice(6));
            }
            onEvent(event, data.length > 0 ? JSON.parse(data.join('\n')) : null);
        }
    }
}

async function ask(question) {
    const streaming = els.streaming.checked;
    const view = createAssistantMessage();
    view.stage('rewriting');
    abortController = new AbortController();
    setBusy(true, streaming);

    try {
        const response = await fetch(streaming ? root.dataset.streamUrl : root.dataset.askUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: streaming ? 'text/event-stream' : 'application/json' },
            body: JSON.stringify({ question, provider: els.provider.value, conversation_id: conversationId }),
            signal: abortController.signal,
        });

        if (!response.ok) {
            view.error(await errorMessage(response));
            return;
        }

        if (!streaming) {
            const answer = await response.json();
            setConversation(answer.conversation_id);
            view.done(answer);
            return;
        }

        let finished = false;
        await readEvents(response, (event, data) => {
            switch (event) {
                case 'conversation': setConversation(data.conversation_id); break;
                case 'stage': view.stage(data.stage); break;
                case 'rewrite': view.rewrite(data); break;
                case 'retrieval': view.retrieval(data); break;
                case 'delta': view.delta(data.text); break;
                case 'done': finished = true; view.done(data); break;
                case 'error': finished = true; view.error(data.message); break;
            }
        });
        if (!finished) view.error('連線在回答完成前中斷。');
    } catch (error) {
        if (error.name === 'AbortError') view.stopped();
        else view.error('無法連線到伺服器。');
    } finally {
        abortController = null;
        setBusy(false);
        updateProviderNote();
    }
}

function setBusy(busy, streaming = false) {
    els.send.disabled = busy;
    els.question.disabled = busy;
    els.newConversation.disabled = busy;
    // 非串流時停止只會中斷畫面等待，伺服器仍會完成並保存回答，所以只在串流時提供
    els.stop.classList.toggle('hidden', !(busy && streaming));
}

els.form.addEventListener('submit', (event) => {
    event.preventDefault();
    const question = els.question.value.trim();
    if (question === '' || abortController !== null) return;
    els.question.value = '';
    appendUserMessage(question);
    ask(question).then(() => els.question.focus());
});

els.question.addEventListener('keydown', (event) => {
    // 中文輸入法選字時的 Enter 不送出
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        els.form.requestSubmit();
    }
});

els.stop.addEventListener('click', () => abortController?.abort());

els.newConversation.addEventListener('click', () => {
    setConversation(null);
    els.messages.replaceChildren(els.emptyState);
    els.emptyState.textContent = '新對話：輸入問題開始。';
    updateProviderNote();
});

els.provider.addEventListener('change', updateProviderNote);

updateProviderNote();
