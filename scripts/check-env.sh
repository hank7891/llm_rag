#!/usr/bin/env bash
# Ch00 驗收（final）。於專案根目錄執行：bash scripts/check-env.sh
set -u
pass() { printf "  \033[32m✔\033[0m %s\n" "$1"; }
fail() { printf "  \033[31m✘\033[0m %s\n" "$1"; FAILED=1; }
FAILED=0
envval() { grep -E "^$1=" .env 2>/dev/null | cut -d= -f2; }
CHAT_MODEL=$(envval OLLAMA_CHAT_MODEL); CHAT_MODEL=${CHAT_MODEL:-qwen3:8b}
EMBED_MODEL=$(envval OLLAMA_EMBED_MODEL); EMBED_MODEL=${EMBED_MODEL:-bge-m3}

echo "[1] Docker 服務"
curl -sf http://localhost:6333/ >/dev/null && pass "Qdrant 有回應（Web UI：http://localhost:6333/dashboard）" || fail "Qdrant 沒有回應"
docker compose ps mysql 2>/dev/null | grep -q healthy && pass "MySQL 容器 healthy" || fail "MySQL 未啟動或尚未 healthy"

echo "[2] Ollama（Mac 原生）"
curl -sf http://localhost:11434/api/tags >/dev/null && pass "Ollama API 有回應" || fail "Ollama 沒有回應（App 有開嗎？）"
ollama list 2>/dev/null | grep -q "^${CHAT_MODEL}" && pass "Chat 模型 ${CHAT_MODEL} 已下載" || fail "找不到 ${CHAT_MODEL}"
ollama list 2>/dev/null | grep -q "^${EMBED_MODEL}" && pass "Embedding 模型 ${EMBED_MODEL} 已下載" || fail "找不到 ${EMBED_MODEL}"

echo "[3] PHP / Laravel（Mac 原生）"
php -r 'exit(version_compare(PHP_VERSION,"8.3.0",">=")?0:1);' 2>/dev/null && pass "PHP $(php -r 'echo PHP_VERSION;')" || fail "PHP 需 8.3 以上"
for ext in pdo_mysql mbstring intl bcmath zip pcntl; do
  php -m 2>/dev/null | grep -qi "^${ext}$" && pass "擴充 ${ext}" || fail "缺少擴充 ${ext}"
done
command -v composer >/dev/null && pass "Composer 已安裝" || fail "缺 Composer"
command -v pdftotext >/dev/null && pass "pdftotext 已安裝（Ch03 用）" || fail "缺 pdftotext（brew install poppler）"
php artisan migrate:status >/dev/null 2>&1 && pass "Laravel 連得到 MySQL，migration 已執行" || fail "migrate:status 失敗"

echo "[4] Reranker（llama-server，Mac 原生，Ch12）"
RERANK_URL=$(envval RAG_RERANK_BASE_URL); RERANK_URL=${RERANK_URL:-http://127.0.0.1:8012}
RERANK_ENABLED=$(envval RAG_RERANK_ENABLED)
if curl -sf -m 3 "${RERANK_URL}/health" | grep -q '"ok"'; then
  pass "llama-server 有回應（${RERANK_URL}）"
else
  # 沒有啟動時檢索會靜靜地降級（退回第一階段排序），品質回到 Ch11
  fail "llama-server 沒有回應（${RERANK_URL}，RAG_RERANK_ENABLED=${RERANK_ENABLED:-未設定}）。請另開終端機啟動："
  echo "      llama-server -m ~/models/bge-reranker-v2-m3-Q8_0.gguf --alias bge-reranker-v2-m3 --embedding --pooling rank --reranking --host 127.0.0.1 --port 8012 -c 8192 -b 2048 -ub 2048"
fi
[ -f ~/models/bge-reranker-v2-m3-Q8_0.gguf ] && pass "Reranker 模型檔存在（~/models/bge-reranker-v2-m3-Q8_0.gguf）" || fail "找不到 ~/models/bge-reranker-v2-m3-Q8_0.gguf（gpustack/bge-reranker-v2-m3-GGUF 的 Q8_0）"

echo "[5] 版控安全"
git check-ignore -q .env && pass ".env 已被 .gitignore 排除" || fail ".env 沒有被排除！"
git ls-files --error-unmatch .env >/dev/null 2>&1 && fail ".env 已經被 commit 過，要移除" || pass ".env 不在版控中"

echo
[ "$FAILED" -eq 0 ] && echo "全部通過，可以打 tag：git tag ch00-done" || echo "有項目未通過（尚未進行的步驟失敗是正常的）。"
