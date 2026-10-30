#!/usr/bin/env bash
# 環境檢查（容器化：app、queue、MySQL、Qdrant 在 Docker；Ollama、llama-server 在主機）。
# 於專案根目錄執行：bash scripts/check-env.sh
set -u
pass() { printf "  \033[32m✔\033[0m %s\n" "$1"; }
fail() { printf "  \033[31m✘\033[0m %s\n" "$1"; FAILED=1; }
FAILED=0
envval() { grep -E "^$1=" .env 2>/dev/null | cut -d= -f2; }
app() { docker compose exec -T -u www-data app "$@"; }
CHAT_MODEL=$(envval OLLAMA_CHAT_MODEL); CHAT_MODEL=${CHAT_MODEL:-qwen3:8b}
EMBED_MODEL=$(envval OLLAMA_EMBED_MODEL); EMBED_MODEL=${EMBED_MODEL:-bge-m3}

echo "[1] Docker 服務"
docker compose ps mysql 2>/dev/null | grep -q healthy && pass "MySQL 容器 healthy" || fail "MySQL 未啟動或尚未 healthy"
curl -sf http://localhost:6333/ >/dev/null && pass "Qdrant 有回應（Web UI：http://localhost:6333/dashboard）" || fail "Qdrant 沒有回應"
for svc in app queue; do
  [ -n "$(docker compose ps -q --status running "$svc" 2>/dev/null)" ] && pass "${svc} 容器執行中" || fail "${svc} 容器未啟動（docker compose up -d app queue）"
done
code=$(curl -s -o /dev/null -w '%{http_code}' -m 5 http://localhost:8000/knowledge/chat)
[ "$code" = 200 ] && pass "網頁有回應（http://localhost:8000）" || fail "網頁沒有正常回應（HTTP ${code}）"

echo "[2] Ollama（Homebrew，主機）"
curl -sf http://localhost:11434/api/tags >/dev/null && pass "Ollama API 有回應" || fail "Ollama 沒有回應（brew services start ollama）"
ollama list 2>/dev/null | grep -q "^${CHAT_MODEL}" && pass "Chat 模型 ${CHAT_MODEL} 已下載" || fail "找不到 ${CHAT_MODEL}"
ollama list 2>/dev/null | grep -q "^${EMBED_MODEL}" && pass "Embedding 模型 ${EMBED_MODEL} 已下載" || fail "找不到 ${EMBED_MODEL}"
# 升級會改變推論結果（Ch13 改寫的 repeat_penalty 就是 Ollama 0.40 實測後才加的）
brew list --pinned 2>/dev/null | grep -qx ollama && pass "ollama 已 brew pin" || fail "ollama 沒有 brew pin（brew pin ollama llama.cpp）"

echo "[3] PHP / Laravel（app 容器）"
app php -r 'exit(version_compare(PHP_VERSION,"8.3.0",">=")?0:1);' 2>/dev/null && pass "PHP $(app php -r 'echo PHP_VERSION;' 2>/dev/null)" || fail "PHP 需 8.3 以上（或 app 容器未啟動）"
mods=$(app php -m 2>/dev/null)
for ext in pdo_mysql mbstring intl bcmath zip pcntl; do
  grep -qix "${ext}" <<< "$mods" && pass "擴充 ${ext}" || fail "缺少擴充 ${ext}"
done
app test -x /usr/bin/pdftotext 2>/dev/null && pass "pdftotext 已安裝（容器內）" || fail "容器內缺 pdftotext（poppler-utils）"
app test -f vendor/autoload.php 2>/dev/null && pass "vendor 已安裝" || fail "缺 vendor（docker compose run --rm --no-deps -u www-data app composer install）"
[ -f public/build/manifest.json ] && pass "前端已建置（public/build）" || fail "缺 public/build（npm ci && npm run build）"
app php artisan migrate:status >/dev/null 2>&1 && pass "Laravel 連得到 MySQL，migration 已執行" || fail "migrate:status 失敗"
app curl -sf -m 3 http://host.docker.internal:11434/api/version >/dev/null 2>&1 && pass "容器連得到主機的 Ollama" || fail "容器連不到 host.docker.internal:11434"

echo "[4] Reranker（llama-server，主機，Ch12）"
RERANK_ENABLED=$(envval RAG_RERANK_ENABLED)
if curl -sf -m 3 http://127.0.0.1:8012/health | grep -q '"ok"'; then
  pass "llama-server 有回應（127.0.0.1:8012）"
  app curl -sf -m 3 http://host.docker.internal:8012/health >/dev/null 2>&1 && pass "容器連得到主機的 llama-server" || fail "容器連不到 host.docker.internal:8012"
else
  # 沒有啟動時檢索會靜靜地降級（退回第一階段排序），品質回到 Ch11
  fail "llama-server 沒有回應（RAG_RERANK_ENABLED=${RERANK_ENABLED:-未設定}）。請另開終端機啟動："
  echo "      llama-server -m ~/models/bge-reranker-v2-m3-Q8_0.gguf --alias bge-reranker-v2-m3 --embedding --pooling rank --reranking --host 127.0.0.1 --port 8012 -c 8192 -b 2048 -ub 2048"
fi
[ -f ~/models/bge-reranker-v2-m3-Q8_0.gguf ] && pass "Reranker 模型檔存在（~/models/bge-reranker-v2-m3-Q8_0.gguf）" || fail "找不到 ~/models/bge-reranker-v2-m3-Q8_0.gguf（gpustack/bge-reranker-v2-m3-GGUF 的 Q8_0）"

echo "[5] 版控安全"
git check-ignore -q .env && pass ".env 已被 .gitignore 排除" || fail ".env 沒有被排除！"
git ls-files --error-unmatch .env >/dev/null 2>&1 && fail ".env 已經被 commit 過，要移除" || pass ".env 不在版控中"

echo
[ "$FAILED" -eq 0 ] && echo "全部通過。" || echo "有項目未通過（尚未進行的步驟失敗是正常的）。"
