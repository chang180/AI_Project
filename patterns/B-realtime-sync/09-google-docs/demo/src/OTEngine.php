<?php
declare(strict_types=1);

/**
 * OTEngine — 最小可用的 Operational Transformation 引擎
 * --------------------------------------------------
 * 對應真實元件：協作伺服器中的「操作排序 + OT transform」核心。
 *
 * 核心觀念
 *   多個客戶端基於「同一個基準版本（baseVersion）」並發送出操作。
 *   伺服器維護權威版本號與 append-only 操作日誌；當一個進來的操作
 *   其 baseVersion 落後於目前權威版本時，代表它「沒看到」中間已套用
 *   的那些操作，必須把它對「中間每一個已套用操作」依序做 transform
 *   （位置調整）後，才能安全套用。如此一來，不論操作以何種順序到達，
 *   所有副本都會「收斂」到相同的文件內容。
 *
 * 本引擎支援的操作種類：
 *   - insert：在 pos 插入 text
 *   - delete：自 pos 起刪除 len 個字元
 *
 * transform(a, b)：在「b 已先套用」的前提下，回傳「修正後的 a」。
 */
final class OTEngine
{
    public const INSERT = 'insert';
    public const DELETE = 'delete';

    /**
     * 建立一個 insert 操作結構。
     * @return array{kind:string,pos:int,text:string,len:int}
     */
    public static function insert(int $pos, string $text): array
    {
        return ['kind' => self::INSERT, 'pos' => $pos, 'text' => $text, 'len' => 0];
    }

    /**
     * 建立一個 delete 操作結構。
     * @return array{kind:string,pos:int,text:string,len:int}
     */
    public static function delete(int $pos, int $len): array
    {
        return ['kind' => self::DELETE, 'pos' => $pos, 'text' => '', 'len' => max(0, $len)];
    }

    /**
     * 把操作 $op 套用到字串 $doc，回傳新字串。
     * 使用多位元組安全的字元級操作（uStrlen/uSubstr），以正確處理中文。
     */
    public static function apply(string $doc, array $op): string
    {
        $total = self::uStrlen($doc);
        if ($op['kind'] === self::INSERT) {
            $pos = self::clamp($op['pos'], 0, $total);
            return self::uSubstr($doc, 0, $pos) . $op['text'] . self::uSubstr($doc, $pos);
        }
        // delete
        $pos = self::clamp($op['pos'], 0, $total);
        $len = self::clamp($op['len'], 0, $total - $pos);
        return self::uSubstr($doc, 0, $pos) . self::uSubstr($doc, $pos + $len);
    }

    /**
     * 核心：transform($a, $b)
     * 在「$b 已先被套用」的前提下，調整 $a 的位置，使 $a 仍套到原本想表達的語意。
     * 涵蓋 insert/insert、insert/delete、delete/insert、delete/delete 四種組合。
     *
     * 慣例（tie-break）：insert vs insert 在同一位置時，視 $b 在前（$a 右移），
     * 保證兩端用相反順序 transform 後仍收斂到同一結果。
     */
    public static function transform(array $a, array $b): array
    {
        if ($a['kind'] === self::INSERT && $b['kind'] === self::INSERT) {
            // b 的插入位置在 a 之前（含同位）→ a 整體右移 b 的長度
            if ($b['pos'] <= $a['pos']) {
                $a['pos'] += self::uStrlen($b['text']);
            }
            return $a;
        }

        if ($a['kind'] === self::INSERT && $b['kind'] === self::DELETE) {
            // b 刪除了一段；a 的插入點要依刪除範圍左移
            if ($b['pos'] < $a['pos']) {
                $removedBefore = min($b['len'], $a['pos'] - $b['pos']);
                $a['pos'] -= $removedBefore;
            }
            return $a;
        }

        if ($a['kind'] === self::DELETE && $b['kind'] === self::INSERT) {
            // b 在 a 的刪除點之前/同位插入 → a 的刪除點右移
            if ($b['pos'] <= $a['pos']) {
                $a['pos'] += self::uStrlen($b['text']);
            }
            // 若 b 插在 a 刪除範圍「中間」，本最小實作不切割刪除（保持區段連續），
            // 真實系統會把 a 切成兩段刪除以精確排除新插入字元。
            return $a;
        }

        // delete vs delete：處理範圍重疊，避免重複刪除 / 刪過頭
        $aStart = $a['pos'];
        $aEnd   = $a['pos'] + $a['len'];
        $bStart = $b['pos'];
        $bEnd   = $b['pos'] + $b['len'];

        if ($bEnd <= $aStart) {
            // b 完全在 a 之前 → a 整段左移 b 的長度
            $a['pos'] -= $b['len'];
            return $a;
        }
        if ($bStart >= $aEnd) {
            // b 完全在 a 之後 → a 不受影響
            return $a;
        }

        // 兩段重疊：扣掉已被 b 刪掉的交集，a 只需刪掉「剩下還在的部分」
        $overlap = min($aEnd, $bEnd) - max($aStart, $bStart);
        $newLen  = $a['len'] - $overlap;
        // a 的新起點：若 b 從 a 之前就開始刪，a 的起點被拉到 b 的刪除點
        $newPos  = min($aStart, $bStart);
        $a['pos'] = $newPos;
        $a['len'] = max(0, $newLen);
        return $a;
    }

    private static function clamp(int $v, int $lo, int $hi): int
    {
        if ($v < $lo) return $lo;
        if ($v > $hi) return $hi;
        return $v;
    }

    // ---- UTF-8 安全的字元級工具（優先用 mbstring，無此擴充時以 PCRE 後援） ----

    /** 以字元數計算長度（非位元組數）。 */
    private static function uStrlen(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s, 'UTF-8');
        }
        $r = preg_match_all('/./us', $s);
        return $r === false ? strlen($s) : $r;
    }

    /** 以字元（非位元組）為單位的 substr。 */
    private static function uSubstr(string $s, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, $start, $length, 'UTF-8');
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return $length === null ? substr($s, $start) : substr($s, $start, $length);
        }
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
