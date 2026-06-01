<?php
declare(strict_types=1);

/**
 * 訂單狀態機（只允許合法轉移）
 * --------------------------------------------------------------
 * 狀態：CREATED → PAID → SHIPPED → DONE
 *       任一階段可轉 CANCELLED（補償/取消的終點）
 *
 * 為什麼要狀態機？
 *   電商訂單的生命週期是「有方向的流程」，不能跳級也不能倒退：
 *   未付款不能出貨、已完成不能再取消。把「誰能轉到誰」用一張
 *   白名單表寫死，任何非法轉移一律拒絕——這就是防止資料錯亂、
 *   把無數 if/else 收斂成單一真相來源的關鍵。
 */
final class OrderStateMachine
{
    public const CREATED   = 'CREATED';
    public const PAID      = 'PAID';
    public const SHIPPED   = 'SHIPPED';
    public const DONE      = 'DONE';
    public const CANCELLED = 'CANCELLED';

    /**
     * 合法轉移白名單：from => [允許的 to, ...]
     * 不在表內的轉移一律非法。
     */
    private const TRANSITIONS = [
        self::CREATED   => [self::PAID, self::CANCELLED],
        self::PAID      => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED   => [self::DONE, self::CANCELLED],
        self::DONE      => [],            // 終態，不可再轉
        self::CANCELLED => [],            // 終態，不可再轉
    ];

    /** 是否為合法狀態 */
    public static function isState(string $s): bool
    {
        return array_key_exists($s, self::TRANSITIONS);
    }

    /** from → to 是否為合法轉移 */
    public static function canTransition(string $from, string $to): bool
    {
        if (!self::isState($from) || !self::isState($to)) {
            return false;
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /** 是否為終態（不可再轉） */
    public static function isTerminal(string $s): bool
    {
        return self::isState($s) && self::TRANSITIONS[$s] === [];
    }

    /**
     * 執行轉移；非法則丟出例外（呼叫端必須先確認狀態）。
     * 回傳新狀態字串。
     */
    public static function transition(string $from, string $to): string
    {
        if (!self::canTransition($from, $to)) {
            throw new RuntimeException("非法狀態轉移：{$from} → {$to}");
        }
        return $to;
    }

    /** 列出某狀態的合法下一步（給前端/教學顯示用） */
    public static function nextStates(string $from): array
    {
        return self::isState($from) ? self::TRANSITIONS[$from] : [];
    }
}
