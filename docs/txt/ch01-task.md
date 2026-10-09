# Ch01 任務：Message DTO 與 Provider 抽象層

> 對應教學文件：第三階段「LLM API 串接」前半（Messages 與 Roles、建議程式架構）
> 本章只做抽象層設計，**不接任何真實模型**。

## 本章範圍

**要做**：DTO、`ChatProviderInterface`、`ChatService`、設定檔、`FakeChatProvider`、測試。

**不要做**：
- 任何 HTTP 呼叫（OllamaProvider、雲端 Provider 屬於 Ch02）
- Route、Controller、`POST /api/ai/chat`（屬於 Ch02）
- `EmbeddingProviderInterface`（屬於 Ch06）
- commit

## 設計決定（請遵守，有疑慮請在回報中提出，不要自行更改）

1. **內部格式與外部格式分開**：DTO 是程式內部的格式。各家 API 的格式轉換一律由 Provider 負責，ChatService 不做任何格式轉換。
2. **DTO 使用 `final readonly class`**；角色使用 enum，不使用字串。
3. **`ChatOptions` 只放各家共通的參數**（model、temperature、maxTokens），全部可為 null，null 代表「使用 Provider 預設值，不送出該參數」。Provider 特有參數（例如 Ollama 的 `num_ctx`、qwen3 的 `think`）不放進 ChatOptions，Ch02 由各 Provider 從自己的設定讀取。
4. **Provider 依名稱選擇**：API 請求會帶 `provider` 名稱，同一個應用程式中不同請求可能使用不同 Provider，因此 ChatService 依名稱從 Service Container 取得 Provider，不在 Container 綁定單一實作。
5. **設定檔命名為 `config/llm.php`**，環境變數使用 `LLM_` 前綴。原因：官方套件 laravel/ai 使用 `config/ai.php`，避免日後衝突。
6. **ChatService 不可刪減或改動傳入的 messages**。歷史訊息的截斷策略屬於 Ch13。

## 前置確認

1. 確認 `git status` 乾淨、tag `ch00-done` 存在；否則先停下來回報。
2. 建立分支 `ch01`。

## 實作項目

### 3. DTO（`app/Ai/Chat/DTO/`）

| 檔案 | 內容 |
| --- | --- |
| `Role.php` | backed enum：`system` / `user` / `assistant` |
| `Message.php` | `role`（Role）、`content`（string） |
| `ChatOptions.php` | `model`、`temperature`、`maxTokens`，全部 nullable，預設 null |
| `Usage.php` | `inputTokens`、`outputTokens` |
| `ChatResult.php` | `content`、`usage`（Usage）、`model`、`finishReason` |
| `StreamChunk.php` | `delta`（本段文字）；最後一段附帶 `usage` 與 `finishReason`，其餘為 null |

### 4. 介面（`app/Ai/Chat/Contracts/ChatProviderInterface.php`）

```php
/** @param list<Message> $messages */
public function chat(array $messages, ChatOptions $options): ChatResult;

/**
 * @param list<Message> $messages
 * @return iterable<StreamChunk>
 */
public function stream(array $messages, ChatOptions $options): iterable;
```

### 5. ChatService（`app/Ai/Chat/ChatService.php`）

- `chat(array $messages, ?ChatOptions $options = null, ?string $provider = null): ChatResult`
- `stream(...)`：同上，回傳 `iterable<StreamChunk>`
- 未指定 provider 時使用 `config('llm.chat.default')`
- 依 `config('llm.chat.providers')` 的「名稱 → 類別」對照表，從 Container 取得實例
- 驗證：messages 非空、每個元素都是 Message、最後一則為 user

### 6. 例外（`app/Ai/Chat/Exceptions/`）

- `UnknownChatProviderException`：名稱不存在於對照表時丟出，訊息需包含該名稱與可用清單
- `InvalidMessagesException`：messages 驗證失敗時丟出

### 7. 設定

- 新增 `config/llm.php`：
  ```php
  'chat' => [
      'default'   => env('LLM_CHAT_PROVIDER', 'fake'),
      'providers' => [
          'fake' => /* FakeChatProvider 類別 */,
      ],
  ],
  ```
- `.env` 與 `.env.example` 補上 `LLM_CHAT_PROVIDER=fake`
- 在 `AppServiceProvider` 或新建的 `AiServiceProvider` 中完成綁定

### 8. FakeChatProvider

- 放在 `tests/Support/` 或 `app/Ai/Chat/Providers/`，請說明選擇理由
- 記錄最後一次收到的 messages 與 options，供測試檢查
- `chat()` 回傳可預測的內容（例如回顯最後一則 user 訊息）與固定的 usage
- `stream()` 以 Generator 逐段 yield，最後一段帶 usage 與 finishReason

## 測試（全部必須通過）

- DTO 建立、Role enum 與字串互轉
- 三輪對話（user → assistant → user）送進 ChatService，FakeChatProvider 收到的內容與順序完全一致
- 以不同 provider 名稱切換時，呼叫端程式碼不變（可註冊第二個 Fake 名稱驗證）
- 未知 provider、空 messages、最後一則不是 user 時，丟出對應例外
- `stream()` 可逐段取得內容，組合後等於完整回答，最後一段帶有 usage

## 完成回報

做完以上內容就停止，不要進入 Ch02，不要 commit。請依序回報：

- **A.** 新增與修改的檔案清單，每個檔案用一句話說明責任
- **B.** Request Flow：一次 `chat()` 呼叫從 ChatService 到 Provider 再回來的完整流程
- **C.** 為什麼 DTO 使用 readonly、Role 使用 enum
- **D.** 為什麼 Provider 特有參數不放進 ChatOptions
- **E.** 為什麼 ChatService 依名稱取得 Provider，而不是在 Container 綁定單一實作
- **F.** 為什麼 Provider 切換時業務層不需要修改，用本章的測試舉例說明
- **G.** 預告 Ch02：OllamaProvider、Anthropic、Gemini 各自需要吸收哪些格式差異
- **H.** 測試執行結果
- **I.** 設計上有疑慮、或替我做了決定的地方
