<?php
declare(strict_types=1);

/**
 * QR 渲染器（教學示意版）
 * --------------------------------------------------
 * ⚠️ 誠實聲明：這裡產生的是「看起來像 QR 的方格圖」，由內容雜湊決定明暗，
 *    含三個定位角，但 **並非真正可掃描的 QR 編碼**（缺 Reed-Solomon 糾錯等）。
 *    目的是讓 demo 自成一體、零依賴即可跑，並聚焦於「系統設計」考點。
 *
 * 要產生真正可掃描的 QR：見 demo/README.md，安裝 endroid/qr-code 後，
 *    把本類別 render() 換成該套件即可（介面相同，輸出 SVG/PNG）。
 */
final class QrRenderer
{
    public function __construct(private int $modules = 25, private int $cell = 8) {}

    /** 把任意文字渲染成 SVG 方格圖（示意） */
    public function render(string $text): string
    {
        $n = $this->modules;
        $grid = $this->buildGrid($text, $n);
        $size = $n * $this->cell;

        $rects = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($grid[$y][$x]) {
                    $px = $x * $this->cell;
                    $py = $y * $this->cell;
                    $rects .= "<rect x=\"$px\" y=\"$py\" width=\"{$this->cell}\" height=\"{$this->cell}\"/>";
                }
            }
        }

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$size\" height=\"$size\" "
            . "viewBox=\"0 0 $size $size\" shape-rendering=\"crispEdges\">"
            . "<rect width=\"$size\" height=\"$size\" fill=\"#fff\"/>"
            . "<g fill=\"#000\">$rects</g></svg>";
    }

    /** 由內容雜湊決定明暗，並畫上三個定位角（模擬 QR 外觀） */
    private function buildGrid(string $text, int $n): array
    {
        $hash = hash('sha256', $text, true);
        $bits = '';
        foreach (str_split($hash) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        // 雜湊不夠長就重複
        while (strlen($bits) < $n * $n) {
            $bits .= $bits;
        }

        $grid = [];
        $i = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $grid[$y][$x] = $bits[$i++] === '1';
            }
        }

        // 三個定位角（左上、右上、左下）
        $this->placeFinder($grid, 0, 0);
        $this->placeFinder($grid, $n - 7, 0);
        $this->placeFinder($grid, 0, $n - 7);
        return $grid;
    }

    private function placeFinder(array &$grid, int $ox, int $oy): void
    {
        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 7; $x++) {
                $edge = $x === 0 || $x === 6 || $y === 0 || $y === 6;
                $inner = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                $grid[$oy + $y][$ox + $x] = $edge || $inner;
            }
        }
    }
}
