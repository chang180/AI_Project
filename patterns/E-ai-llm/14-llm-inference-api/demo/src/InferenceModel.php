<?php
declare(strict_types=1);

/**
 * 假「模型」—— 純示意，沒有真正的 GPU 或權重
 * --------------------------------------------------
 * 真實系統：GPU Worker 載入模型權重，prefill 一次處理整段 prompt，
 *           decode 階段每個 step 自迴歸產出一個 token，並讀寫 KV cache。
 *
 * 這裡用「prompt 的雜湊」決定性地決定每個請求要生成幾個 token，
 * 並逐 token 產出可預期的內容，讓 continuous batching 的排程語意可被驗證。
 * 注意：無真模型，token 內容僅為占位字串。
 */
final class InferenceModel
{
    /** 由 prompt 決定該請求的輸出長度（4～12 token），上限再受 max_tokens 約束 */
    public function plannedTokens(string $prompt, int $maxTokens): int
    {
        $h = crc32($prompt);
        $base = 4 + ($h % 9);           // 4..12，決定性、不同 prompt 不同長度
        return max(1, min($base, $maxTokens));
    }

    /**
     * 產出第 $index 個 token（從 0 起算）。
     * 真實系統此處是一次 GPU forward；這裡回傳占位字串。
     */
    public function nextToken(string $prompt, int $index): string
    {
        // 用 prompt + index 產生穩定、可讀的占位 token
        $seed = crc32($prompt . '#' . $index);
        return 'tok' . ($seed % 1000);
    }
}
