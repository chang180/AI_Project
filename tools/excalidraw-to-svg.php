<?php
declare(strict_types=1);

/**
 * excalidraw-to-svg.php — 把 .excalidraw（Excalidraw JSON）轉成永久、離線、可內嵌的 SVG。
 * 用途：每題的 diagram.excalidraw → diagram.svg，再 inline 進 notes.html（不靠外部服務、不會失效）。
 *
 * 用法：
 *   php tools/excalidraw-to-svg.php <in.excalidraw> [out.svg]
 *   php tools/excalidraw-to-svg.php --all          # 掃 patterns/ 下所有 diagram.excalidraw 一次轉完
 *
 * 支援元素：rectangle / ellipse / diamond / text / arrow / line。
 * 顏色、虛線、箭頭、圓角、多行文字、文字對齊都會還原。零依賴（僅 json）。
 */

function conv(string $in, string $out): void
{
    $data = json_decode((string) file_get_contents($in), true);
    if (!is_array($data) || !isset($data['elements'])) {
        fwrite(STDERR, "跳過（非 excalidraw）：$in\n");
        return;
    }
    $els = array_values(array_filter($data['elements'], fn($e) => empty($e['isDeleted'])));
    $bg = $data['appState']['viewBackgroundColor'] ?? '#ffffff';

    // ---- 計算邊界框 ----
    $minX = INF; $minY = INF; $maxX = -INF; $maxY = -INF;
    foreach ($els as $e) {
        $x = (float)($e['x'] ?? 0); $y = (float)($e['y'] ?? 0);
        $w = (float)($e['width'] ?? 0); $h = (float)($e['height'] ?? 0);
        if (($e['type'] ?? '') === 'arrow' || ($e['type'] ?? '') === 'line') {
            foreach ($e['points'] ?? [[0,0]] as $p) {
                $minX = min($minX, $x + $p[0]); $maxX = max($maxX, $x + $p[0]);
                $minY = min($minY, $y + $p[1]); $maxY = max($maxY, $y + $p[1]);
            }
        } else {
            $minX = min($minX, $x); $maxX = max($maxX, $x + $w);
            $minY = min($minY, $y); $maxY = max($maxY, $y + $h);
        }
    }
    if (!is_finite($minX)) { $minX = $minY = 0; $maxX = $maxY = 100; }
    $pad = 24;
    $vx = $minX - $pad; $vy = $minY - $pad;
    $vw = ($maxX - $minX) + $pad * 2; $vh = ($maxY - $minY) + $pad * 2;

    $svg = [];
    $svg[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $svg[] = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" width="%s" height="%s" font-family="system-ui,\'Segoe UI\',\'Microsoft JhengHei\',sans-serif">',
        r($vx), r($vy), r($vw), r($vh), r($vw), r($vh)
    );
    // 每種箭頭顏色各自一個 marker
    $arrowColors = [];
    foreach ($els as $e) {
        if (in_array($e['type'] ?? '', ['arrow', 'line'], true) && !empty($e['endArrowhead'] ?? ($e['type'] === 'arrow' ? 'arrow' : null))) {
            $arrowColors[strtolower($e['strokeColor'] ?? '#1e1e1e')] = true;
        }
    }
    $svg[] = '<defs>';
    foreach (array_keys($arrowColors) as $c) {
        $id = 'ah' . substr(md5($c), 0, 6);
        $svg[] = sprintf('<marker id="%s" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="%s"/></marker>', $id, e($c));
    }
    $svg[] = '</defs>';
    $svg[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>', r($vx), r($vy), r($vw), r($vh), e($bg));

    foreach ($els as $el) {
        $type = $el['type'] ?? '';
        $x = (float)($el['x'] ?? 0); $y = (float)($el['y'] ?? 0);
        $w = (float)($el['width'] ?? 0); $h = (float)($el['height'] ?? 0);
        $stroke = $el['strokeColor'] ?? '#1e1e1e';
        $fill = $el['backgroundColor'] ?? 'transparent';
        if ($fill === 'transparent' || $fill === '') $fill = 'none';
        $sw = (float)($el['strokeWidth'] ?? 2);
        $dash = match ($el['strokeStyle'] ?? 'solid') {
            'dashed' => ' stroke-dasharray="8 6"',
            'dotted' => ' stroke-dasharray="2 6"',
            default => '',
        };

        switch ($type) {
            case 'rectangle':
                $rx = isset($el['roundness']) && $el['roundness'] !== null ? 8 : 0;
                $svg[] = sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="%s" stroke="%s" stroke-width="%s"%s/>',
                    r($x), r($y), r($w), r($h), $rx, e($fill), e($stroke), r($sw), $dash);
                break;
            case 'ellipse':
                $svg[] = sprintf('<ellipse cx="%s" cy="%s" rx="%s" ry="%s" fill="%s" stroke="%s" stroke-width="%s"%s/>',
                    r($x + $w / 2), r($y + $h / 2), r($w / 2), r($h / 2), e($fill), e($stroke), r($sw), $dash);
                break;
            case 'diamond':
                $pts = sprintf('%s,%s %s,%s %s,%s %s,%s',
                    r($x + $w / 2), r($y), r($x + $w), r($y + $h / 2), r($x + $w / 2), r($y + $h), r($x), r($y + $h / 2));
                $svg[] = sprintf('<polygon points="%s" fill="%s" stroke="%s" stroke-width="%s"%s/>', $pts, e($fill), e($stroke), r($sw), $dash);
                break;
            case 'text':
                $fs = (float)($el['fontSize'] ?? 16);
                $align = $el['textAlign'] ?? 'left';
                $anchor = $align === 'center' ? 'middle' : ($align === 'right' ? 'end' : 'start');
                $tx = $anchor === 'middle' ? $x + $w / 2 : ($anchor === 'end' ? $x + $w : $x);
                $lines = explode("\n", (string)($el['text'] ?? ''));
                $svg[] = sprintf('<text x="%s" y="%s" font-size="%s" fill="%s" text-anchor="%s" dominant-baseline="hanging">',
                    r($tx), r($y), r($fs), e($stroke), $anchor);
                foreach ($lines as $i => $line) {
                    $svg[] = sprintf('<tspan x="%s" dy="%s">%s</tspan>', r($tx), $i === 0 ? '0' : r($fs * 1.25), e($line));
                }
                $svg[] = '</text>';
                break;
            case 'arrow':
            case 'line':
                $pts = $el['points'] ?? [[0, 0], [$w, $h]];
                $abs = array_map(fn($p) => r($x + $p[0]) . ',' . r($y + $p[1]), $pts);
                $hasHead = $type === 'arrow' ? ($el['endArrowhead'] ?? 'arrow') : ($el['endArrowhead'] ?? null);
                $marker = $hasHead ? sprintf(' marker-end="url(#ah%s)"', substr(md5(strtolower($stroke)), 0, 6)) : '';
                $svg[] = sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="%s"%s%s stroke-linecap="round" stroke-linejoin="round"/>',
                    implode(' ', $abs), e($stroke), r($sw), $dash, $marker);
                break;
        }
    }
    $svg[] = '</svg>';
    file_put_contents($out, implode("\n", $svg) . "\n");
    echo "OK  $out\n";
}

function r(float $n): string { return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

// ---- CLI ----
$args = array_slice($argv, 1);
if (($args[0] ?? '') === '--all') {
    $root = dirname(__DIR__) . '/patterns';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getFilename() === 'diagram.excalidraw') {
            conv($f->getPathname(), dirname($f->getPathname()) . '/diagram.svg');
        }
    }
} elseif (isset($args[0])) {
    conv($args[0], $args[1] ?? preg_replace('/\.excalidraw$/', '.svg', $args[0]));
} else {
    fwrite(STDERR, "用法：php tools/excalidraw-to-svg.php <in.excalidraw> [out.svg]  |  --all\n");
    exit(1);
}
