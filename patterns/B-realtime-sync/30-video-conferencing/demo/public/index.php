<?php
declare(strict_types=1);

/**
 * 視訊會議 Zoom Demo — 前端控制器 / 路由
 * 啟動：php -S localhost:8030 -t public
 *
 * 純模擬「信令中繼 + SFU 轉發決策」，不含真實 WebRTC / 媒體傳輸。
 *
 * 路由：
 *   GET  /                      測試頁
 *   POST /api/join              加入 room { room, name } → { peer_id, peers }
 *   POST /api/sdp               中繼 SDP { room, from, to, type, sdp }
 *   POST /api/ice               中繼 ICE { room, from, to, candidate }
 *   GET  /api/signals/{room}/{peer}  取走信令信箱（測試輔助）
 *   GET  /api/room/{id}         room 狀態（轉發表 + 各訂閱層 + 主講者）
 *   POST /api/bandwidth         設頻寬 { room, peer, kbps } → { layer }
 *   POST /api/audio             回報音量 { room, peer, level } → { active_speaker }
 */

require __DIR__ . '/../src/Sfu.php';

$sfu = new Sfu(__DIR__ . '/../data');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

try {
    // ---- POST /api/join ----
    if ($method === 'POST' && $path === '/api/join') {
        $b = body();
        $room = trim((string) ($b['room'] ?? ''));
        $name = trim((string) ($b['name'] ?? ''));
        if ($room === '' || $name === '') {
            fail(400, 'room 與 name 必填');
        }
        ok($sfu->join($room, $name));
    }

    // ---- POST /api/sdp ----
    if ($method === 'POST' && $path === '/api/sdp') {
        $b = body();
        $type = (string) ($b['type'] ?? 'offer');
        if ($type !== 'offer' && $type !== 'answer') {
            fail(400, 'type 須為 offer 或 answer');
        }
        ok($sfu->postSdp(
            (string) ($b['room'] ?? ''),
            (string) ($b['from'] ?? ''),
            (string) ($b['to'] ?? ''),
            $type,
            (string) ($b['sdp'] ?? '')
        ));
    }

    // ---- POST /api/ice ----
    if ($method === 'POST' && $path === '/api/ice') {
        $b = body();
        ok($sfu->postIce(
            (string) ($b['room'] ?? ''),
            (string) ($b['from'] ?? ''),
            (string) ($b['to'] ?? ''),
            (string) ($b['candidate'] ?? '')
        ));
    }

    // ---- GET /api/signals/{room}/{peer} ----
    if ($method === 'GET' && preg_match('#^/api/signals/([^/]+)/([^/]+)$#', $path, $m)) {
        ok($sfu->drainSignals(rawurldecode($m[1]), rawurldecode($m[2])));
    }

    // ---- POST /api/bandwidth ----
    if ($method === 'POST' && $path === '/api/bandwidth') {
        $b = body();
        $kbps = (int) ($b['kbps'] ?? 0);
        if ($kbps < 0) {
            fail(400, 'kbps 不可為負');
        }
        ok($sfu->setBandwidth(
            (string) ($b['room'] ?? ''),
            (string) ($b['peer'] ?? ''),
            $kbps
        ));
    }

    // ---- POST /api/audio ----
    if ($method === 'POST' && $path === '/api/audio') {
        $b = body();
        ok($sfu->reportAudio(
            (string) ($b['room'] ?? ''),
            (string) ($b['peer'] ?? ''),
            (float) ($b['level'] ?? 0.0)
        ));
    }

    // ---- GET /api/room/{id} ----
    if ($method === 'GET' && preg_match('#^/api/room/([^/]+)$#', $path, $m)) {
        ok($sfu->roomState(rawurldecode($m[1])));
    }

    // ---- GET /：測試頁 ----
    if ($method === 'GET' && $path === '/') {
        render_home();
        exit;
    }

    fail(404, 'not found');
} catch (RuntimeException $e) {
    fail(400, $e->getMessage());
}

// ============ 輔助函式 ============
function body(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

function ok(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function fail(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function render_home(): void
{
    echo <<<'HTML'
<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>視訊會議 Zoom · Demo</title>
<style>
body{max-width:900px;margin:32px auto;padding:0 16px;font-family:system-ui,"Microsoft JhengHei",sans-serif;background:#0f1115;color:#e6e6e6}
h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:1.6em;border-bottom:1px solid #333;padding-bottom:4px}
.box{border-left:4px solid #4dabf7;background:#1a1d23;padding:10px 14px;border-radius:0 8px 8px 0;margin:1em 0}
.box .label{font-weight:700;display:block;color:#4dabf7}
code{background:#222;padding:2px 6px;border-radius:4px}
button{background:#1971c2;color:#fff;border:0;padding:6px 12px;border-radius:6px;cursor:pointer;margin:2px}
button:hover{background:#1864ab}
input,select{background:#222;color:#eee;border:1px solid #444;border-radius:6px;padding:5px 8px;margin:2px}
table{width:100%;border-collapse:collapse;margin:.5em 0}td,th{border:1px solid #333;padding:6px 8px;text-align:left;font-size:.9rem}
th{background:#1a1d23}
pre{background:#16181d;border:1px solid #333;border-radius:8px;padding:12px;overflow:auto;font-size:.82rem;max-height:320px}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:.4em 0}
.layer-high{color:#69db7c}.layer-mid{color:#ffd43b}.layer-low{color:#ff8787}
.speaker{color:#ffd43b;font-weight:700}
small{color:#888}
</style></head><body>
<h1>🎥 視訊會議 Zoom · Demo</h1>
<p><small>純模擬「信令中繼 + SFU 轉發決策」，不含真實 WebRTC / 媒體傳輸。所有決策邏輯（轉發表、依頻寬選層、主講者偵測）為真實可執行。</small></p>

<div class="box"><span class="label">① 建 room + 加 peer</span>
  <div class="row">
    Room：<input id="room" value="r1"> 名稱：<input id="name" value="Alice">
    <button onclick="join()">加入</button>
    <button onclick="quick()">一鍵建 r1 + 加 Alice/Bob/Carol</button>
  </div>
  <div id="joinOut"><small>尚未加入</small></div>
</div>

<div class="box"><span class="label">② 交換 SDP / ICE（信令中繼）</span>
  <div class="row">
    from：<input id="from" value="p1" size="4"> to：<input id="to" value="p2" size="4">
    type：<select id="sdptype"><option>offer</option><option>answer</option></select>
    <button onclick="sendSdp()">送 SDP</button>
    <button onclick="sendIce()">送 ICE</button>
    取信箱 peer：<input id="drain" value="p2" size="4"><button onclick="drain()">取走</button>
  </div>
  <div id="sigOut"></div>
</div>

<div class="box"><span class="label">③ 調訂閱者頻寬 → 看 SFU 選層（降/升階）</span>
  <div class="row">
    peer：<input id="bwpeer" value="p2" size="4">
    頻寬(kbps)：<input id="kbps" type="number" value="600" size="6">
    <button onclick="setBw()">套用</button>
    <small>high≥1500 / mid≥500 / low&lt;500</small>
  </div>
  <div id="bwOut"></div>
</div>

<div class="box"><span class="label">④ 回報音量 → 看主講者</span>
  <div class="row">
    peer：<input id="aupeer" value="p3" size="4">
    level(0~1)：<input id="level" type="number" step="0.01" value="0.9" size="6">
    <button onclick="sendAudio()">回報</button>
  </div>
  <div id="auOut"></div>
</div>

<h2>Room 狀態（轉發表 / 各訂閱層 / 主講者）</h2>
<button onclick="refresh()">🔄 重新整理</button>
<div id="state"></div>

<script>
const $=id=>document.getElementById(id);
const room=()=>$('room').value.trim();
async function post(u,b){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});return r.json();}
async function get(u){const r=await fetch(u);return r.json();}
function show(el,o){$(el).innerHTML='<pre>'+JSON.stringify(o,null,2)+'</pre>';}

async function join(){const o=await post('/api/join',{room:room(),name:$('name').value});show('joinOut',o);refresh();}
async function quick(){
  $('room').value='r1';
  for(const n of ['Alice','Bob','Carol']){await post('/api/join',{room:'r1',name:n});}
  $('joinOut').innerHTML='<small>已建 r1 並加入 Alice(p1)/Bob(p2)/Carol(p3)</small>';
  refresh();
}
async function sendSdp(){const o=await post('/api/sdp',{room:room(),from:$('from').value,to:$('to').value,type:$('sdptype').value,sdp:'v=0\\r\\no=- 1 1 IN IP4 0.0.0.0...'});show('sigOut',o);}
async function sendIce(){const o=await post('/api/ice',{room:room(),from:$('from').value,to:$('to').value,candidate:'candidate:1 1 UDP 2122 192.168.1.5 50000 typ host'});show('sigOut',o);}
async function drain(){const o=await get('/api/signals/'+encodeURIComponent(room())+'/'+encodeURIComponent($('drain').value));show('sigOut',o);}
async function setBw(){const o=await post('/api/bandwidth',{room:room(),peer:$('bwpeer').value,kbps:parseInt($('kbps').value,10)});show('bwOut',o);refresh();}
async function sendAudio(){const o=await post('/api/audio',{room:room(),peer:$('aupeer').value,level:parseFloat($('level').value)});show('auOut',o);refresh();}

async function refresh(){
  let s;try{s=await get('/api/room/'+encodeURIComponent(room()));}catch(e){$('state').innerHTML='<small>無此 room</small>';return;}
  if(s.error){$('state').innerHTML='<small>'+s.error+'</small>';return;}
  let html='<p>主講者：<span class="speaker">'+(s.active_speaker||'（無人說話）')+'</span></p>';
  html+='<table><tr><th>peer</th><th>名稱</th><th>頻寬kbps</th><th>選到的層</th><th>音量</th></tr>';
  for(const p of s.peers){const L=s.subscriber_layers[p.peer_id];
    html+='<tr><td>'+p.peer_id+'</td><td>'+p.name+'</td><td>'+p.bandwidth_kbps+'</td><td class="layer-'+L+'">'+L+'</td><td>'+p.audio_level+'</td></tr>';}
  html+='</table>';
  html+='<p><small>轉發表（每列：誰的上行 → 轉給誰，用哪層）：上行 1 條，下行 N−1 條</small></p>';
  html+='<table><tr><th>publisher(上行)</th><th>subscriber(下行)</th><th>層</th></tr>';
  for(const f of s.forwarding){html+='<tr><td>'+f.publisher+'</td><td>'+f.subscriber+'</td><td class="layer-'+f.layer+'">'+f.layer+'</td></tr>';}
  html+='</table>';
  $('state').innerHTML=html;
}
</script>
</body></html>
HTML;
}
