<?php
declare(strict_types=1);

require_once __DIR__ . '/SlidingWindow.php';

/**
 * 即時規則引擎（本 demo 的核心 = 詐欺偵測的「線上決策」那一層）
 * --------------------------------------------------------------------
 * 對「每一筆交易」即時評估一組規則，每條命中的規則加上各自的權重分數，
 * 累加成「風險總分」，再用兩個門檻把總分映射成三分決策：
 *
 *     score < reviewAt           → allow  （放行）
 *     reviewAt <= score < denyAt → review （送人工審查）
 *     score >= denyAt            → deny   （拒絕）
 *
 * 這是業界詐欺系統最常見的骨架：**規則引擎（可解釋、可即時調整）**負責
 * 擋掉明確的壞模式，**ML 模型分數**再補上規則涵蓋不到的細緻模式。本
 * demo 把「模型分數」以一個可解釋的加權示意（金額越大、速度越高，模型
 * 分越高），誠實聲明見 README / notes §9。
 *
 * 規則種類（涵蓋面試最常被問的幾類訊號）：
 *   - velocity_card : 同卡在滑動視窗內交易筆數過多（試卡 / 盜刷掃號）
 *   - velocity_ip   : 同 IP 在滑動視窗內交易筆數過多（機器人 / 代理農場）
 *   - amount        : 單筆金額超過門檻（大額異常）
 *   - blacklist     : 卡號 / IP / 裝置命中黑名單
 *   - model         : ML 模型風險分（以加權示意，非真模型）
 *
 * 所有門檻 / 權重皆可由 POST /api/config 即時調整，當場看決策如何改變。
 */
final class RuleEngine
{
    private SlidingWindow $cardVel;
    private SlidingWindow $ipVel;

    /** @var array<string,mixed> 可即時調整的設定 */
    private array $cfg;

    /** @var array<string,bool> 黑名單值集合（卡號 / IP / 裝置混在一起，以前綴區分語意） */
    private array $blacklist = [];

    public function __construct(array $cfg = [])
    {
        $this->cfg = array_merge([
            'velocity_window_sec' => 60,   // 速度規則滑動視窗長度
            'velocity_card_max'   => 3,    // 同卡視窗內筆數 > 此值即命中
            'velocity_ip_max'     => 5,    // 同 IP 視窗內筆數 > 此值即命中
            'amount_max'          => 1000, // 單筆金額 > 此值即命中
            // 各規則命中時加的分數（權重）
            'w_velocity_card'     => 40,
            'w_velocity_ip'       => 25,
            'w_amount'            => 30,
            'w_blacklist'         => 80,
            // ML 模型示意分數的縮放係數
            'w_model'             => 1.0,
            // 三分決策門檻
            'review_at'           => 50,
            'deny_at'             => 90,
        ], $cfg);

        $this->cardVel = new SlidingWindow((int) $this->cfg['velocity_window_sec']);
        $this->ipVel   = new SlidingWindow((int) $this->cfg['velocity_window_sec']);
    }

    public function addToBlacklist(string $value): void
    {
        if ($value !== '') {
            $this->blacklist[$value] = true;
        }
    }

    /** @param string[] $values */
    public function loadBlacklist(array $values): void
    {
        foreach ($values as $v) {
            $this->addToBlacklist((string) $v);
        }
    }

    public function blacklistValues(): array
    {
        return array_keys($this->blacklist);
    }

    public function config(): array
    {
        return $this->cfg;
    }

    /**
     * 評估單筆交易，回傳：分數、命中的規則明細、最終決策。
     * 交易格式：['txn_id'=>, 'card_id'=>, 'ip'=>, 'device'=>, 'amount'=>, 'ts'=>]
     *
     * @return array{txn_id:string,score:int,decision:string,triggered:array,features:array}
     */
    public function evaluate(array $txn): array
    {
        $txnId  = (string) ($txn['txn_id'] ?? '');
        $cardId = (string) ($txn['card_id'] ?? '');
        $ip     = (string) ($txn['ip'] ?? '');
        $device = (string) ($txn['device'] ?? '');
        $amount = (float) ($txn['amount'] ?? 0);
        $ts     = (int) ($txn['ts'] ?? time());

        // ---- 1) 即時線上特徵：滑動視窗速度（呼應 ㉒ 串流聚合）----
        $cardVel = $this->cardVel->hit($cardId, $ts);
        $ipVel   = $this->ipVel->hit($ip, $ts);

        $triggered = [];
        $score = 0;

        // ---- 2) 規則：同卡速度 ----
        if ($cardVel > (int) $this->cfg['velocity_card_max']) {
            $w = (int) $this->cfg['w_velocity_card'];
            $score += $w;
            $triggered[] = [
                'rule' => 'velocity_card',
                'desc' => "同卡 {$this->cfg['velocity_window_sec']} 秒內第 {$cardVel} 筆 (門檻 {$this->cfg['velocity_card_max']})",
                'score' => $w,
            ];
        }

        // ---- 規則：同 IP 速度 ----
        if ($ipVel > (int) $this->cfg['velocity_ip_max']) {
            $w = (int) $this->cfg['w_velocity_ip'];
            $score += $w;
            $triggered[] = [
                'rule' => 'velocity_ip',
                'desc' => "同 IP {$this->cfg['velocity_window_sec']} 秒內第 {$ipVel} 筆 (門檻 {$this->cfg['velocity_ip_max']})",
                'score' => $w,
            ];
        }

        // ---- 規則：金額門檻 ----
        if ($amount > (float) $this->cfg['amount_max']) {
            $w = (int) $this->cfg['w_amount'];
            $score += $w;
            $triggered[] = [
                'rule' => 'amount',
                'desc' => "金額 {$amount} 超過門檻 {$this->cfg['amount_max']}",
                'score' => $w,
            ];
        }

        // ---- 規則：黑名單（卡 / IP / 裝置任一命中）----
        $hit = null;
        if ($cardId !== '' && isset($this->blacklist[$cardId])) {
            $hit = "卡號 {$cardId}";
        } elseif ($ip !== '' && isset($this->blacklist[$ip])) {
            $hit = "IP {$ip}";
        } elseif ($device !== '' && isset($this->blacklist[$device])) {
            $hit = "裝置 {$device}";
        }
        if ($hit !== null) {
            $w = (int) $this->cfg['w_blacklist'];
            $score += $w;
            $triggered[] = [
                'rule' => 'blacklist',
                'desc' => "命中黑名單：{$hit}",
                'score' => $w,
            ];
        }

        // ---- 規則：ML 模型分（加權示意，非真模型）----
        // 以「金額相對門檻」與「速度相對門檻」做平滑加權，象徵模型對細緻模式的評分。
        $amountRatio = $amount / max(1.0, (float) $this->cfg['amount_max']);
        $velRatio = max(
            $cardVel / max(1, (int) $this->cfg['velocity_card_max']),
            $ipVel / max(1, (int) $this->cfg['velocity_ip_max'])
        );
        $modelRaw = (min(2.0, $amountRatio) * 8) + (min(3.0, $velRatio) * 6);
        $modelScore = (int) round($modelRaw * (float) $this->cfg['w_model']);
        if ($modelScore > 0) {
            $score += $modelScore;
            $triggered[] = [
                'rule' => 'model',
                'desc' => '模型風險分（加權示意：金額/速度越高分越高）',
                'score' => $modelScore,
            ];
        }

        // ---- 3) 三分決策：分數 → allow / review / deny ----
        $reviewAt = (int) $this->cfg['review_at'];
        $denyAt = (int) $this->cfg['deny_at'];
        if ($score >= $denyAt) {
            $decision = 'deny';
        } elseif ($score >= $reviewAt) {
            $decision = 'review';
        } else {
            $decision = 'allow';
        }

        return [
            'txn_id' => $txnId,
            'score' => $score,
            'decision' => $decision,
            'triggered' => $triggered,
            'features' => [
                'card_velocity' => $cardVel,
                'ip_velocity' => $ipVel,
                'amount' => $amount,
            ],
        ];
    }

    public function snapshot(): array
    {
        return [
            'card_vel' => $this->cardVel->snapshot(),
            'ip_vel' => $this->ipVel->snapshot(),
            'blacklist' => $this->blacklistValues(),
            'cfg' => $this->cfg,
        ];
    }

    public function load(array $state): void
    {
        if (isset($state['card_vel']) && is_array($state['card_vel'])) {
            $this->cardVel->load($state['card_vel']);
        }
        if (isset($state['ip_vel']) && is_array($state['ip_vel'])) {
            $this->ipVel->load($state['ip_vel']);
        }
        if (isset($state['blacklist']) && is_array($state['blacklist'])) {
            $this->loadBlacklist($state['blacklist']);
        }
    }

    public function reset(): void
    {
        $this->cardVel->reset();
        $this->ipVel->reset();
        $this->blacklist = [];
    }
}
