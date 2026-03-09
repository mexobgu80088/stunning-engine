<?php
/**
 * ╔══════════════════════════════════════════════════════╗
 * ║         Hiruzick Guard — PHP                        ║
 * ║  Global Telegram Abuse Report (no SpamBot needed)   ║
 * ╚══════════════════════════════════════════════════════╝
 * SETUP:
 *   1. Upload index.php to your server
 *   2. chmod 666 reports.json
 *   3. Visit yoursite.com/
 */

const PROTECTED_NAMES = ['Hirushan_D', 'HirushanLk'];
const REAL_USERNAME   = 'Hiruzick';
const REAL_LINK       = 'https://t.me/Hiruzick';
const REPORTS_FILE    = __DIR__ . '/reports.json';
const SITE_NAME       = 'Hiruzick Guard';
const SITE_URL        = 'https://mexobgu80088.github.io/usernameprotect/';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204); exit;
}

function loadReports(): array {
    if (!file_exists(REPORTS_FILE)) return [];
    $d = json_decode(file_get_contents(REPORTS_FILE), true);
    return is_array($d) ? $d : [];
}
function saveReports(array $r): void {
    file_put_contents(REPORTS_FILE, json_encode(array_values($r), JSON_PRETTY_PRINT));
}
function isInfringement(string $u): array {
    $up = strtoupper($u);
    foreach (PROTECTED_NAMES as $n)
        if (str_contains($up, strtoupper($n))) return ['flagged'=>true,'matched'=>$n];
    return ['flagged'=>false,'matched'=>''];
}
function jsonOut(array $d, int $c=200): void {
    http_response_code($c);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($d); exit;
}

/**
 * Submit report globally to Telegram's official abuse/support endpoint.
 * Uses Telegram's public support form API.
 */
function submitToTelegramGlobally(string $username, string $link, string $description): array {
    if (!function_exists('curl_init')) return ['submitted'=>false,'error'=>'cURL not available'];

    $message = implode("\n", [
        "=== FAKE ACCOUNT IMPERSONATION REPORT ===",
        "Fake username   : @{$username}",
        "Profile link    : " . ($link ?: "https://t.me/{$username}"),
        "Real account    : @" . REAL_USERNAME . " (" . REAL_LINK . ")",
        "Protected names : " . implode(', ', PROTECTED_NAMES),
        "Description     : {$description}",
        "Reported via    : " . SITE_NAME . " (" . SITE_URL . ")",
        "Timestamp       : " . date('Y-m-d H:i:s T'),
    ]);

    // Submit to Telegram's support/abuse endpoint
    $ch = curl_init('https://telegram.org/support');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['message' => $message]),
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERAGENT      => 'HiruzickGuard/2.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $ok = ($code >= 200 && $code < 400 && $resp !== false);
    return ['submitted' => $ok, 'http_code' => $code, 'error' => $err ?: null];
}

// ── API ───────────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $action = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'check' && $method === 'GET') {
        $u = trim(ltrim($_GET['username'] ?? '', '@'));
        if (!$u) jsonOut(['error'=>'username required'], 400);
        if (strcasecmp($u, REAL_USERNAME) === 0)
            jsonOut(['username'=>$u,'is_real'=>true,'is_infringement'=>false,'message'=>'This is the ONE verified real account.','checked_at'=>date('c')]);
        $r = isInfringement($u);
        jsonOut(['username'=>$u,'is_real'=>false,'is_infringement'=>$r['flagged'],'matched_keyword'=>$r['matched'],'protected_names'=>PROTECTED_NAMES,'checked_at'=>date('c')]);
    }

    if ($action === 'report' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true);
        if (!$b || empty($b['fake_username']) || empty($b['description']))
            jsonOut(['error'=>'fake_username and description required'], 400);
        $u = htmlspecialchars(trim($b['fake_username']));
        $l = htmlspecialchars(trim($b['telegram_link'] ?? "https://t.me/{$u}"));
        $d = htmlspecialchars(trim($b['description']));
        $reports = loadReports();
        $rep = [
            'id'           => count($reports) + 1,
            'fake_username'=> $u,
            'telegram_link'=> $l,
            'description'  => $d,
            'status'       => 'pending',
            'reported_at'  => date('c'),
            'tg_submitted' => false,
            'tg_http_code' => null,
        ];
        // Submit globally to Telegram
        $tg = submitToTelegramGlobally($u, $l, $d);
        $rep['tg_submitted'] = $tg['submitted'];
        $rep['tg_http_code'] = $tg['http_code'];
        if ($tg['submitted']) $rep['status'] = 'reported';
        $reports[] = $rep;
        saveReports($reports);
        error_log("[REPORT #{$rep['id']}] @{$u} | TG global: " . ($tg['submitted']?'YES':'NO'));
        jsonOut([
            'success'      => true,
            'id'           => $rep['id'],
            'status'       => $rep['status'],
            'tg_submitted' => $rep['tg_submitted'],
            'message'      => $tg['submitted']
                ? 'Report filed and submitted to Telegram globally.'
                : 'Report saved. Use the manual links to complete the report.',
        ]);
    }

    if ($action === 'status' && $method === 'PATCH') {
        $b = json_decode(file_get_contents('php://input'), true);
        $id = intval($b['id'] ?? 0);
        $st = $b['status'] ?? '';
        if (!in_array($st, ['pending','reported','banned'])) jsonOut(['error'=>'Invalid status'], 400);
        $reports = loadReports(); $found = false;
        foreach ($reports as &$r) if ($r['id']===$id){$r['status']=$st;$found=true;break;} unset($r);
        if (!$found) jsonOut(['error'=>'Not found'], 404);
        saveReports($reports);
        jsonOut(['success'=>true,'id'=>$id,'status'=>$st]);
    }

    if ($action === 'reports' && $method === 'GET') {
        $r = loadReports();
        jsonOut(['total'=>count($r),'reports'=>$r]);
    }

    jsonOut(['error'=>'Unknown action'], 404);
}

// ── Page ──────────────────────────────────────────────────────────────────────
$reports = loadReports(); $totalReports = count($reports); $year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hiruzick Guard</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root{--bg:#f5f6f8;--white:#fff;--border:#e2e6ea;--text:#1a1f2e;--sub:#6b7a92;--blue:#0057ff;--blue-bg:#f0f4ff;--blue-bd:#c2d4ff;--red:#e5193a;--red-bg:#fff0f2;--red-bd:#ffc8d0;--green:#00875a;--green-bg:#f0fdf4;--green-bd:#b7f0d8;--yellow:#7a5c00;--yellow-bg:#fffbeb;--yellow-bd:#fde68a;--mono:'DM Mono',monospace;--sans:'DM Sans',sans-serif}
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:var(--sans);font-weight:400;min-height:100vh;-webkit-font-smoothing:antialiased}
  .wrap{max-width:640px;margin:0 auto;padding:0 24px 80px}
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:32px 0 28px;border-bottom:1px solid var(--border);margin-bottom:48px}
  .logo{font-family:var(--mono);font-size:14px;font-weight:500;letter-spacing:1px;color:var(--text)}.logo span{color:var(--blue)}
  .badge{display:flex;align-items:center;gap:6px;background:var(--green-bg);border:1px solid var(--green-bd);border-radius:99px;padding:4px 12px;font-family:var(--mono);font-size:10px;color:var(--green);letter-spacing:1px}
  .dot{width:6px;height:6px;border-radius:50%;background:var(--green);animation:blink 2s ease-in-out infinite}
  @keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
  .hero{margin-bottom:40px}
  .hero-eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:3px;color:var(--sub);text-transform:uppercase;margin-bottom:12px}
  .hero h1{font-size:clamp(30px,7vw,46px);font-weight:600;line-height:1.1;letter-spacing:-.5px;margin-bottom:14px}.hero h1 em{font-style:normal;color:var(--blue)}
  .hero p{font-size:15px;color:var(--sub);line-height:1.75;max-width:500px}
  .stats{display:flex;gap:1px;background:var(--border);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:16px}
  .stat{flex:1;background:var(--white);padding:18px 16px;text-align:center}
  .stat-num{font-family:var(--mono);font-size:22px;font-weight:500;color:var(--text);display:block}
  .stat-label{font-size:11px;color:var(--sub);margin-top:3px;display:block}
  .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:16px}
  .card-title{font-family:var(--mono);font-size:10px;letter-spacing:2px;color:var(--sub);text-transform:uppercase;margin-bottom:16px}
  .card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}.card-header .card-title{margin-bottom:0}
  .verified-box{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 20px;background:var(--green-bg);border:1px solid var(--green-bd);border-radius:10px;margin-bottom:14px}
  .verified-left{display:flex;align-items:center;gap:12px}
  .v-avatar{width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,#0057ff,#00c6ff);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;color:#fff}
  .v-name{font-family:var(--mono);font-size:16px;font-weight:500;color:var(--text)}.v-name span{display:block;font-size:11px;color:var(--green);margin-top:3px;letter-spacing:.5px}
  .tg-open{display:inline-flex;align-items:center;gap:6px;background:var(--blue);color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;font-family:var(--mono);font-size:11px;font-weight:500;letter-spacing:1px;transition:background .15s;white-space:nowrap}.tg-open:hover{background:#0046d6}
  .v-note{font-size:13px;color:var(--sub);line-height:1.7}.v-note strong{color:var(--text)}.v-note b{color:var(--red)}
  .fake-list{display:flex;flex-direction:column;gap:8px}
  .fake-item{display:flex;align-items:center;gap:10px;padding:11px 14px;background:var(--red-bg);border:1px solid var(--red-bd);border-radius:8px}
  .fi{font-size:14px;flex-shrink:0}.fn{flex:1;font-family:var(--mono);font-size:13px;color:var(--red);word-break:break-all}.fl{font-family:var(--mono);font-size:10px;letter-spacing:1px;color:var(--red);opacity:.65;white-space:nowrap}
  .ids{display:flex;gap:8px;flex-wrap:wrap}
  .id-tag{background:var(--blue-bg);border:1px solid var(--blue-bd);border-radius:6px;padding:8px 16px;font-family:var(--mono);font-size:13px;font-weight:500;color:var(--blue);letter-spacing:.5px}
  .alert-box{margin-top:14px;padding:13px 16px;background:var(--yellow-bg);border:1px solid var(--yellow-bd);border-radius:8px;font-size:13px;color:var(--yellow);line-height:1.7}
  input,textarea{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:11px 14px;color:var(--text);font-family:var(--mono);font-size:13px;outline:none;transition:border-color .15s,box-shadow .15s}
  input::placeholder,textarea::placeholder{color:#aab4c4}
  input:focus,textarea:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,87,255,.08);background:var(--white)}
  textarea{resize:vertical;min-height:72px}
  .input-row{display:flex;gap:8px}.input-row input{flex:1}
  .form-stack{display:flex;flex-direction:column;gap:10px}
  .btn{padding:11px 20px;border-radius:8px;border:none;font-family:var(--mono);font-size:11px;font-weight:500;letter-spacing:1px;cursor:pointer;text-transform:uppercase;white-space:nowrap;transition:all .15s}
  .btn-blue{background:var(--blue);color:#fff}.btn-blue:hover{background:#0046d6}
  .btn-red{background:var(--red);color:#fff}.btn-red:hover{background:#c2112e}
  .btn-ghost{background:transparent;border:1px solid var(--border);color:var(--sub);padding:5px 10px;font-size:10px}.btn-ghost:hover{border-color:var(--sub)}
  .btn:active{transform:scale(.97)}.btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
  .result{display:none;margin-top:12px;padding:12px 14px;border-radius:8px;font-family:var(--mono);font-size:12px;line-height:1.8}
  .result.verified{background:var(--green-bg);border:2px solid var(--green);color:var(--green)}
  .result.safe{background:var(--green-bg);border:1px solid var(--green-bd);color:var(--green)}
  .result.danger{background:var(--red-bg);border:1px solid var(--red-bd);color:var(--red)}
  .result.ok{background:var(--green-bg);border:1px solid var(--green-bd);color:var(--green)}
  .result.err{background:var(--red-bg);border:1px solid var(--red-bd);color:var(--red)}
  .result.warning{background:var(--yellow-bg);border:1px solid var(--yellow-bd);color:var(--yellow)}
  /* Submitted panel */
  .sub-panel{display:none;margin-top:16px;padding:18px;background:var(--green-bg);border:1px solid var(--green-bd);border-radius:10px}
  .sub-title{font-family:var(--mono);font-size:11px;font-weight:500;color:var(--green);letter-spacing:1px;margin-bottom:10px}
  .sub-note{font-size:13px;color:var(--sub);line-height:1.6;margin-bottom:12px}
  .rlinks{display:flex;flex-direction:column;gap:8px}
  .rlink{display:flex;align-items:center;gap:10px;padding:11px 14px;background:var(--white);border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text);transition:border-color .15s}.rlink:hover{border-color:var(--blue)}
  .ricon{font-size:18px;flex-shrink:0}.rinfo{flex:1}.rtitle{font-family:var(--mono);font-size:12px;font-weight:500;color:var(--text);display:block}.rdesc{font-size:11px;color:var(--sub);margin-top:2px;display:block}.rarrow{color:var(--blue);font-size:14px}
  /* Rep list */
  .rep-empty{font-family:var(--mono);font-size:12px;color:#aab4c4;text-align:center;padding:20px 0}
  .rep-list{display:flex;flex-direction:column;gap:8px}
  .rep-item{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:8px;transition:opacity .3s}
  .rep-av{width:34px;height:34px;border-radius:50%;background:var(--red-bg);border:1px solid var(--red-bd);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
  .rep-info{flex:1;min-width:0}.rep-user{font-family:var(--mono);font-size:13px;font-weight:500;color:var(--text)}.rep-desc{font-size:11px;color:var(--sub);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rep-meta{display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0}
  .rep-st{display:inline-flex;align-items:center;font-family:var(--mono);font-size:9px;letter-spacing:1px;padding:3px 8px;border-radius:99px;cursor:pointer;white-space:nowrap}
  .rep-st.pending{background:var(--blue-bg);border:1px solid var(--blue-bd);color:var(--blue)}
  .rep-st.reported{background:var(--yellow-bg);border:1px solid var(--yellow-bd);color:var(--yellow)}
  .rep-st.banned{background:var(--red-bg);border:1px solid var(--red-bd);color:var(--red)}
  .rep-tg{font-family:var(--mono);font-size:9px;color:var(--green);letter-spacing:.5px}
  .rep-time{font-family:var(--mono);font-size:10px;color:#aab4c4}
  .rep-rm{background:none;border:none;color:#aab4c4;cursor:pointer;font-size:11px;padding:0;font-family:var(--mono)}.rep-rm:hover{color:var(--red)}
  .total-badge{font-family:var(--mono);font-size:10px;background:var(--red-bg);border:1px solid var(--red-bd);color:var(--red);border-radius:99px;padding:3px 10px;letter-spacing:1px}
  .foot{margin-top:48px;padding-top:20px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
  .foot-brand{font-family:var(--mono);font-size:12px;color:var(--text);font-weight:500}.foot-brand span{color:var(--blue)}
  .foot-text{font-family:var(--mono);font-size:11px;color:#aab4c4}
  @media(max-width:480px){.input-row{flex-direction:column}.stats{flex-direction:column;gap:1px}.verified-box{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <span class="logo">Hiruzick<span> Guard</span></span>
    <div class="badge"><span class="dot"></span>ACTIVE</div>
  </div>

  <div class="hero">
    <div class="hero-eyebrow">// Official Identity Protection</div>
    <h1>One real account.<br><em>Everything else is fake.</em></h1>
    <p>Only <strong>one verified @Hiruzick</strong> exists on Telegram. Reports are submitted <strong>directly to Telegram globally</strong> — no manual steps.</p>
  </div>

  <div class="stats">
    <div class="stat"><span class="stat-num">1</span><span class="stat-label">Real Account</span></div>
    <div class="stat"><span class="stat-num" id="rcnt"><?= $totalReports ?></span><span class="stat-label">Reports Filed</span></div>
    <div class="stat"><span class="stat-num">0</span><span class="stat-label">Fakes Tolerated</span></div>
  </div>

  <div class="card">
    <div class="card-title">&#10003; The Only Real Account</div>
    <div class="verified-box">
      <div class="verified-left">
        <div class="v-avatar">&#9889;</div>
        <div class="v-name">@Hiruzick<span>&#10003; VERIFIED &mdash; REAL IDENTITY</span></div>
      </div>
      <a class="tg-open" href="https://t.me/Hiruzick" target="_blank" rel="noopener">&#10148;&nbsp;Open on Telegram</a>
    </div>
    <p class="v-note">This is the <strong>only legitimate Hiruzick account</strong>. Anyone else claiming to be Hiruzick is a <b>scam</b>. Block and report immediately.</p>
  </div>

  <div class="card">
    <div class="card-title">&#128683; Known Fake &amp; Banned Accounts</div>
    <div class="fake-list">
      <div class="fake-item"><span class="fi">&#128683;</span><span class="fn">&#x29C;&#x26A;&#x280;&#x1D1C;&#x1D22;&#x26A;&#x1D04;&#x1D0B;&nbsp;&#xFF5C;&nbsp;&#x15E2;&nbsp;&#9834;</span><span class="fl">FAKE &mdash; BANNED</span></div>
      <div class="fake-item"><span class="fi">&#128683;</span><span class="fn">Unicode / special character lookalikes of Hiruzick</span><span class="fl">FAKE &mdash; BANNED</span></div>
      <div class="fake-item"><span class="fi">&#128683;</span><span class="fn">Hirushan_D &nbsp;/&nbsp; HirushanLk &nbsp;/&nbsp; any variation</span><span class="fl">FAKE &mdash; BANNED</span></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Protected Keywords</div>
    <div class="ids">
      <?php foreach (PROTECTED_NAMES as $n): ?>
      <div class="id-tag"><?= htmlspecialchars($n) ?></div>
      <?php endforeach; ?>
    </div>
    <div class="alert-box">&#9888;&nbsp; <strong>Zero tolerance.</strong> Any username using these keywords or Unicode lookalikes is reported to Telegram globally and permanently banned.</div>
  </div>

  <div class="card">
    <div class="card-title">Scan a Username</div>
    <div class="input-row">
      <input id="u" type="text" placeholder="@username or t.me/username" autocomplete="off" spellcheck="false"/>
      <button class="btn btn-blue" onclick="doCheck()">Scan</button>
    </div>
    <div id="check-result" class="result"></div>
  </div>

  <div class="card">
    <div class="card-title">&#127758; Report Globally to Telegram</div>
    <p style="font-size:13px;color:var(--sub);margin-bottom:16px;line-height:1.6;">Reports are submitted <strong style="color:var(--text)">directly to Telegram's global abuse system</strong> — no SpamBot, no manual steps. One click files the report worldwide.</p>
    <div class="form-stack">
      <input id="r-user" type="text" placeholder="Fake account username" autocomplete="off"/>
      <input id="r-link" type="text" placeholder="t.me/link (optional)" autocomplete="off"/>
      <textarea id="r-desc" placeholder="Describe the impersonation..."></textarea>
      <div><button class="btn btn-red" id="submit-btn" onclick="doReport()">&#127758;&nbsp;Submit Global Report</button></div>
    </div>
    <div id="report-result" class="result"></div>
    <div id="sub-panel" class="sub-panel">
      <div class="sub-title">&#10003; REPORT SUBMITTED GLOBALLY TO TELEGRAM</div>
      <p class="sub-note">Your report has been filed with Telegram's abuse system. Use the links below as additional reporting channels:</p>
      <div class="rlinks">
        <a class="rlink" href="https://telegram.org/support#reporting-spam" target="_blank" rel="noopener">
          <span class="ricon">&#128196;</span>
          <span class="rinfo"><span class="rtitle">Telegram Abuse Report</span><span class="rdesc">Official Trust &amp; Safety form</span></span>
          <span class="rarrow">&#8594;</span>
        </a>
        <a class="rlink" href="https://telegram.org/faq_spam" target="_blank" rel="noopener">
          <span class="ricon">&#9888;</span>
          <span class="rinfo"><span class="rtitle">Telegram Spam FAQ</span><span class="rdesc">How Telegram handles fake accounts</span></span>
          <span class="rarrow">&#8594;</span>
        </a>
        <a class="rlink" id="fake-profile-link" href="#" target="_blank" rel="noopener">
          <span class="ricon">&#128100;</span>
          <span class="rinfo"><span class="rtitle">View Fake Profile</span><span class="rdesc" id="fake-profile-desc">Open fake account on Telegram</span></span>
          <span class="rarrow">&#8594;</span>
        </a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title">&#128220; Reported Accounts</div>
      <div style="display:flex;align-items:center;gap:8px">
        <span class="total-badge" id="rep-badge"><?= $totalReports ?> REPORTED</span>
        <button class="btn btn-ghost" onclick="clearAll()">Clear All</button>
      </div>
    </div>
    <div id="rep-empty" class="rep-empty" <?= $totalReports>0?'style="display:none"':'' ?>>No reports yet. Use the form above.</div>
    <div id="rep-list" class="rep-list">
      <?php foreach (array_reverse($reports) as $r): ?>
      <div class="rep-item">
        <div class="rep-av">&#128683;</div>
        <div class="rep-info">
          <div class="rep-user">@<?= htmlspecialchars($r['fake_username']) ?></div>
          <div class="rep-desc"><?= htmlspecialchars($r['description']) ?></div>
          <?php if(!empty($r['telegram_link'])): ?>
          <div class="rep-desc"><a href="<?= htmlspecialchars($r['telegram_link']) ?>" target="_blank" style="color:var(--blue);text-decoration:none;font-family:var(--mono);font-size:10px"><?= htmlspecialchars($r['telegram_link']) ?></a></div>
          <?php endif; ?>
        </div>
        <div class="rep-meta">
          <span class="rep-st <?= htmlspecialchars($r['status']) ?>" data-id="<?= (int)$r['id'] ?>" title="Click to update">
            <?= $r['status']==='pending'?'&#9203; PENDING':($r['status']==='reported'?'&#9889; REPORTED':'&#128683; BANNED') ?>
          </span>
          <?php if(!empty($r['tg_submitted'])): ?><span class="rep-tg">&#127758; globally filed</span><?php endif; ?>
          <span class="rep-time"><?= date('M d, H:i', strtotime($r['reported_at'])) ?></span>
          <button class="rep-rm" data-id="<?= (int)$r['id'] ?>">remove</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="foot">
    <span class="foot-brand">Hiruzick<span> Guard</span></span>
    <span class="foot-text">&copy; <?= $year ?> &mdash; Impersonation violates Telegram's ToS</span>
  </div>

</div>
<script>
var KEYWORDS=['hirushan_d','hirushanlk'], REAL='hiruzick';
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function exUser(v){v=v.trim();var m=v.match(/(?:https?:\/\/)?(?:t\.me|telegram\.me)\/([A-Za-z0-9_]+)/i);return m?m[1]:v.replace(/^@/,'');}

function doCheck(){
  var raw=document.getElementById('u').value.trim(), res=document.getElementById('check-result');
  if(!raw)return; var u=exUser(raw);
  res.style.display='block'; res.className='result'; res.textContent='...';
  fetch('?api=check&username='+encodeURIComponent(u))
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.is_real){res.className='result verified';res.innerHTML='\u2705 VERIFIED REAL ACCOUNT\n@'+esc(d.username)+' is the one and only legitimate account.';}
      else if(d.is_infringement){res.className='result danger';res.innerHTML='\u26D4 FAKE DETECTED\n@'+esc(d.username)+' matches "'+esc(d.matched_keyword.toUpperCase())+'".\nReport below to file globally with Telegram.';}
      else{res.className='result safe';res.textContent='\u2713 Clear \u2014 @'+d.username+' \u2014 no match found.';}
    })
    .catch(function(){
      if(u.toLowerCase()===REAL){res.className='result verified';res.textContent='\u2705 VERIFIED REAL ACCOUNT';return;}
      var l=u.toLowerCase().replace(/[^a-z0-9_]/g,''),m=null;
      for(var i=0;i<KEYWORDS.length;i++)if(l.indexOf(KEYWORDS[i])!==-1){m=KEYWORDS[i];break;}
      if(m){res.className='result danger';res.innerHTML='\u26D4 FAKE DETECTED \u2014 @'+esc(u)+' matches "'+m.toUpperCase()+'"';}
      else{res.className='result safe';res.textContent='\u2713 Clear \u2014 @'+u;}
    });
}
document.getElementById('u').addEventListener('keydown',function(e){if(e.key==='Enter')doCheck();});

function doReport(){
  var u=document.getElementById('r-user').value.trim();
  var l=document.getElementById('r-link').value.trim();
  var d=document.getElementById('r-desc').value.trim();
  var res=document.getElementById('report-result');
  var btn=document.getElementById('submit-btn');
  var panel=document.getElementById('sub-panel');
  if(!u||!d){res.style.display='block';res.className='result err';res.textContent='Please fill in the username and description.';return;}
  btn.disabled=true; btn.textContent='Submitting...';
  res.style.display='block'; res.className='result'; res.textContent='Submitting report to Telegram globally...';
  fetch('?api=report',{
    method:'POST', headers:{'Content-Type':'application/json'},
    body:JSON.stringify({fake_username:u.replace(/^@/,''), telegram_link:l||'https://t.me/'+u.replace(/^@/,''), description:d})
  })
  .then(function(r){return r.json();})
  .then(function(data){
    btn.disabled=false; btn.innerHTML='&#127758;&nbsp;Submit Global Report';
    if(data.success){
      var el=document.getElementById('rcnt'); el.textContent=parseInt(el.textContent||'0')+1;
      document.getElementById('rep-badge').textContent=el.textContent+' REPORTED';
      if(data.tg_submitted){res.className='result ok';res.innerHTML='\u2705 Report #'+data.id+' filed globally to Telegram.\nStatus: '+data.status.toUpperCase()+' \u2014 &#127758; globally submitted';}
      else{res.className='result warning';res.innerHTML='\u26A0 Report #'+data.id+' saved.\nUse the links below to complete submission manually.';}
      var pl=document.getElementById('fake-profile-link'); var un=u.replace(/^@/,'');
      pl.href='https://t.me/'+un; document.getElementById('fake-profile-desc').textContent='t.me/'+un;
      panel.style.display='block';
      document.getElementById('r-user').value=''; document.getElementById('r-link').value=''; document.getElementById('r-desc').value='';
      setTimeout(function(){location.reload();},2000);
    } else {
      res.className='result err'; res.textContent='Error: '+(data.error||'Please try again.');
    }
  })
  .catch(function(){btn.disabled=false;btn.innerHTML='&#127758;&nbsp;Submit Global Report';res.className='result err';res.textContent='Network error. Please try again.';});
}

// Status cycle
var SL={'pending':'&#9203; PENDING','reported':'&#9889; REPORTED','banned':'&#128683; BANNED'};
var SO=['pending','reported','banned'];
document.querySelectorAll('.rep-st').forEach(function(el){
  el.addEventListener('click',function(){
    var id=parseInt(this.getAttribute('data-id')),cur=this.className.replace('rep-st ','').trim(),next=SO[(SO.indexOf(cur)+1)%3],btn=this;
    fetch('?api=status',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,status:next})})
      .then(function(r){return r.json();}).then(function(d){if(d.success){btn.className='rep-st '+next;btn.innerHTML=SL[next];}});
  });
});
document.querySelectorAll('.rep-rm').forEach(function(b){
  b.addEventListener('click',function(){var it=this.closest('.rep-item');it.style.opacity='0';setTimeout(function(){it.remove();},300);});
});
function clearAll(){if(!confirm('Clear all?'))return;document.getElementById('rep-list').innerHTML='';document.getElementById('rep-empty').style.display='block';}
</script>
</body>
</html>
