<?php
/**
 * Excel-style Payroll Sheet (PHP + SQLite)
 * Put in C:\xampp\htdocs\payroll\ and open http://localhost/payroll/payroll.php
 * Needs PHP 7.4+ with pdo_sqlite enabled.
 */
session_start();

const CURRENCY = '₱';  // change to $, €, etc.
const HALF     = 0.5;  // half day pays 50% of the rate
const ROWS     = 15;   // number of employee rows
const DAYS     = 7;    // days shown per sheet

// ---------- DATABASE ----------
$db = new PDO('sqlite:' . __DIR__ . '/payroll_sheet.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS sheets (
    start_date TEXT PRIMARY KEY,
    data TEXT NOT NULL,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

// ---------- HELPERS ----------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return CURRENCY . number_format((float)$n, 2); }
function valid_date($d) { $x = DateTime::createFromFormat('Y-m-d', (string)$d); return $x && $x->format('Y-m-d') === $d; }
function valid_time($t) { $x = DateTime::createFromFormat('H:i', (string)$t); return $x && $x->format('H:i') === $t; }
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function day_pay($rate, $type) {
    return $type === 'Whole' ? $rate : ($type === 'Half' ? $rate * HALF : 0);
}
function blank_row() {
    return ['name' => '', 'rate' => '', 'days' => array_fill(0, DAYS, ['type' => '', 'in' => '', 'out' => ''])];
}
function normalize(array $rows): array {
    $out = [];
    for ($i = 0; $i < ROWS; $i++) {
        $r = $rows[$i] ?? [];
        $row = blank_row();
        $row['name'] = (string)($r['name'] ?? '');
        $row['rate'] = $r['rate'] ?? '';
        for ($d = 0; $d < DAYS; $d++) {
            $x = $r['days'][$d] ?? [];
            $row['days'][$d] = [
                'type' => (string)($x['type'] ?? ''),
                'in'   => (string)($x['in'] ?? ''),
                'out'  => (string)($x['out'] ?? ''),
            ];
        }
        $out[] = $row;
    }
    return $out;
}

// ---------- WHICH SHEET ----------
$start = $_POST['start'] ?? $_GET['start'] ?? '';
if (!valid_date($start)) $start = (new DateTime('monday this week'))->format('Y-m-d');

// ---------- SAVE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
    $in = $_POST['emp'] ?? [];
    $rows = [];
    for ($i = 0; $i < ROWS; $i++) {
        $r = $in[$i] ?? [];
        $row = blank_row();
        $row['name'] = mb_substr(trim((string)($r['name'] ?? '')), 0, 60);
        $rate = trim((string)($r['rate'] ?? ''));
        $row['rate'] = (is_numeric($rate) && $rate >= 0) ? (float)$rate : '';
        for ($d = 0; $d < DAYS; $d++) {
            $x = $r['days'][$d] ?? [];
            $type = $x['type'] ?? '';
            $row['days'][$d] = [
                'type' => in_array($type, ['Whole', 'Half'], true) ? $type : '',
                'in'   => valid_time($x['in'] ?? '') ? $x['in'] : '',
                'out'  => valid_time($x['out'] ?? '') ? $x['out'] : '',
            ];
        }
        $rows[] = $row;
    }
    $db->prepare("INSERT OR REPLACE INTO sheets (start_date, data, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
       ->execute([$start, json_encode($rows)]);
    header('Location: ?start=' . $start . '&saved=1');
    exit;
}

// ---------- LOAD ----------
$stmt = $db->prepare("SELECT data FROM sheets WHERE start_date = ?");
$stmt->execute([$start]);
$saved = $stmt->fetchColumn();
$carried = false;
if ($saved !== false) {
    $rows = normalize(json_decode($saved, true) ?: []);
} else {
    // New sheet: carry over names and rates from the most recent saved sheet
    $rows = normalize([]);
    $prev = $db->query("SELECT data FROM sheets ORDER BY start_date DESC LIMIT 1")->fetchColumn();
    if ($prev !== false) {
        foreach (normalize(json_decode($prev, true) ?: []) as $i => $p) {
            $rows[$i]['name'] = $p['name'];
            $rows[$i]['rate'] = $p['rate'];
        }
        $carried = true;
    }
}

$dates = [];
for ($d = 0; $d < DAYS; $d++) $dates[] = (new DateTime($start))->modify("+$d day");
$dayLabels = array_map(function ($dt) { return ['d' => $dt->format('D'), 'date' => $dt->format('M d')]; }, $dates);
$periodLabel = $dates[0]->format('M d, Y') . ' – ' . $dates[DAYS - 1]->format('M d, Y');
$prevWeek = (new DateTime($start))->modify('-' . DAYS . ' day')->format('Y-m-d');
$nextWeek = (new DateTime($start))->modify('+' . DAYS . ' day')->format('Y-m-d');

// Totals
$dayTotals = array_fill(0, DAYS, 0);
$rowTotals = [];
$rowDays = [];
foreach ($rows as $i => $r) {
    $t = 0; $dc = 0;
    foreach ($r['days'] as $d => $x) {
        $p = day_pay((float)$r['rate'], $x['type']);
        $t += $p; $dayTotals[$d] += $p;
        $dc += $x['type'] === 'Whole' ? 1 : ($x['type'] === 'Half' ? HALF : 0);
    }
    $rowTotals[$i] = $t; $rowDays[$i] = $dc;
}
$grand = array_sum($rowTotals);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payroll Sheet</title>
<style>
    body { font-family: Arial, sans-serif; margin: 0; background: #e9ecef; color: #222; font-size: 13px; }
    header { background: #1f3a5f; color: #fff; padding: 10px 20px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
    header h1 { font-size: 1.1rem; margin: 0 12px 0 0; }
    header a, header button { background: #fff; color: #1f3a5f; border: 0; padding: 6px 12px; border-radius: 4px; text-decoration: none; cursor: pointer; font-size: 13px; }
    header button.save { background: #2e9e4f; color: #fff; font-weight: 700; }
    header input[type=date] { padding: 5px; border-radius: 4px; border: 0; }
    header label { font-size: 13px; }
    .msg { padding: 8px 20px; font-size: 13px; }
    .msg.ok { background: #d7f0de; color: #1d6b32; }
    .msg.info { background: #fff3cd; color: #6b5200; }
    .wrap { overflow: auto; padding: 12px; }
    table { border-collapse: collapse; background: #fff; }
    th, td { border: 1px solid #b8bec6; padding: 0; text-align: center; }
    th { background: #dfe5ec; padding: 5px 6px; white-space: nowrap; }
    th.day { background: #c9d7e8; }
    td input, td select { border: 0; width: 100%; box-sizing: border-box; padding: 6px 4px; font-size: 13px; background: transparent; font-family: inherit; }
    td input:focus, td select:focus { outline: 2px solid #1f6feb; outline-offset: -2px; background: #fffbe6; }
    td.name input { width: 190px; text-align: left; }
    td.rate input { width: 80px; text-align: right; }
    td.t select { width: 66px; }
    td.tm input { width: 98px; }
    td.no { background: #f0f2f5; padding: 4px 8px; color: #666; }
    td.calc { padding: 6px 10px; text-align: right; font-weight: 700; background: #f7f9fb; white-space: nowrap; }
    td.dayc { padding: 6px 10px; text-align: right; background: #eef2f6; font-weight: 700; white-space: nowrap; }
    tr.foot td { background: #dfe5ec; font-weight: 700; }
    td.whole select, td.t.whole select { background: #dff1e3; }
    td.t.half select { background: #fff0d6; }
    th.sticky, td.sticky { position: sticky; left: 0; z-index: 1; }
    td.sticky { background: #fff; }
    .hint { padding: 0 20px 16px; color: #555; font-size: 12px; }
    th.sig, td.sig { min-width: 170px; }
    td.sig { background: #fff; }
    #printSheet { display: none; }

    /* ---- Print: A4 portrait, compact one-cell-per-day layout ---- */
    @page { size: A4 portrait; margin: 8mm; }
    @media print {
        header, .msg, .hint, .wrap { display: none !important; }
        html, body { background: #fff; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        #printSheet { display: block; font-family: Arial, sans-serif; color: #000; }
        #printSheet h1 { font-size: 14pt; text-align: center; margin: 0 0 1mm; }
        #printSheet .period { text-align: center; font-size: 9pt; margin-bottom: 4mm; }
        #printSheet table { width: 100%; table-layout: fixed; border-collapse: collapse; background: #fff; }
        #printSheet th, #printSheet td { border: 0.3mm solid #000; padding: 0.6mm; font-size: 7pt; text-align: center; vertical-align: middle; overflow: hidden; word-break: break-all; }
        #printSheet th { background: #e6e6e6; }
        #printSheet td.l { text-align: left; padding-left: 1.2mm; font-size: 8pt; word-break: normal; }
        #printSheet td.r { text-align: right; padding-right: 1.2mm; }
        #printSheet tbody tr { height: 13mm; }
        #printSheet tfoot td { font-weight: bold; background: #f0f0f0; height: 7mm; }
        #printSheet tfoot td.d { font-size: 6pt; }
        #printSheet .legend { font-size: 7pt; margin-top: 3mm; }
    }
</style>
</head>
<body>
<form method="post" id="sheet">
<input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
<header>
    <h1>Payroll Sheet</h1>
    <a href="?start=<?= h($prevWeek) ?>">&laquo; Previous</a>
    <label>Start date <input type="date" name="start" value="<?= h($start) ?>" id="startDate"></label>
    <a href="?start=<?= h($nextWeek) ?>">Next &raquo;</a>
    <button type="button" id="goBtn">Go</button>
    <button type="submit" class="save">Save</button>
    <button type="button" onclick="doPrint()">Print (A4)</button>
</header>

<?php if (!empty($_GET['saved'])): ?><div class="msg ok">Saved.</div><?php endif; ?>
<?php if ($carried): ?><div class="msg info">New sheet &mdash; names and rates were copied from your last saved sheet. Fill in the days and click Save.</div><?php endif; ?>

<div class="wrap">
<table id="grid">
    <thead>
        <tr>
            <th rowspan="2">#</th>
            <th rowspan="2" class="sticky">Name</th>
            <th rowspan="2">Rate</th>
            <?php foreach ($dates as $dt): ?>
                <th colspan="3" class="day"><?= h($dt->format('l')) ?><br><span style="font-weight:normal"><?= h($dt->format('M d, Y')) ?></span></th>
            <?php endforeach; ?>
            <th rowspan="2">Days</th>
            <th rowspan="2">Total</th>
            <th rowspan="2" class="sig">Employee Signature</th>
        </tr>
        <tr>
            <?php for ($d = 0; $d < DAYS; $d++): ?>
                <th>Whole/Half</th><th>Time In</th><th>Time Out</th>
            <?php endfor; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
        <tr class="emp">
            <td class="no"><?= $i + 1 ?></td>
            <td class="name sticky"><input type="text" name="emp[<?= $i ?>][name]" value="<?= h($r['name']) ?>" maxlength="60" autocomplete="off"></td>
            <td class="rate"><input type="number" class="rateIn" name="emp[<?= $i ?>][rate]" value="<?= h($r['rate']) ?>" min="0" step="0.01"></td>
            <?php foreach ($r['days'] as $d => $x): ?>
                <td class="t <?= $x['type'] === 'Whole' ? 'whole' : ($x['type'] === 'Half' ? 'half' : '') ?>">
                    <select class="typeIn" data-day="<?= $d ?>" name="emp[<?= $i ?>][days][<?= $d ?>][type]">
                        <option value=""></option>
                        <option value="Whole" <?= $x['type'] === 'Whole' ? 'selected' : '' ?>>Whole</option>
                        <option value="Half"  <?= $x['type'] === 'Half'  ? 'selected' : '' ?>>Half</option>
                    </select>
                </td>
                <td class="tm"><input type="time" name="emp[<?= $i ?>][days][<?= $d ?>][in]"  value="<?= h($x['in']) ?>"></td>
                <td class="tm"><input type="time" name="emp[<?= $i ?>][days][<?= $d ?>][out]" value="<?= h($x['out']) ?>"></td>
            <?php endforeach; ?>
            <td class="calc daysCell"><?= rtrim(rtrim(number_format($rowDays[$i], 1), '0'), '.') ?: '0' ?></td>
            <td class="calc totalCell"><?= money($rowTotals[$i]) ?></td>
            <td class="sig"></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="foot">
            <td colspan="3" style="text-align:right;padding:6px 10px">TOTAL PER DAY</td>
            <?php for ($d = 0; $d < DAYS; $d++): ?>
                <td colspan="3" class="dayc" id="dayTot<?= $d ?>"><?= money($dayTotals[$d]) ?></td>
            <?php endfor; ?>
            <td></td>
            <td class="calc" id="grand"><?= money($grand) ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>
</div>
<div id="printSheet"></div>
<div class="hint">
    Pick <strong>Whole</strong> or <strong>Half</strong> for each day worked (leave blank if absent). Whole = full rate, Half = <?= HALF * 100 ?>% of the rate.
    The rate is per day. Click <strong>Save</strong> to keep your changes. Use Previous / Next or the start date to open another week.
</div>
</form>

<script>
(function () {
    const fmt = n => '<?= CURRENCY ?>' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const HALF = <?= HALF ?>;
    const DAYS = <?= DAYS ?>;
    let dirty = false;

    function recalc() {
        const dayTotals = new Array(DAYS).fill(0);
        let grand = 0;
        document.querySelectorAll('#grid tbody tr.emp').forEach(tr => {
            const rate = parseFloat(tr.querySelector('.rateIn').value) || 0;
            let total = 0, days = 0;
            tr.querySelectorAll('.typeIn').forEach(sel => {
                const d = +sel.dataset.day, td = sel.parentElement;
                td.classList.remove('whole', 'half');
                let p = 0;
                if (sel.value === 'Whole') { p = rate; days += 1; td.classList.add('whole'); }
                else if (sel.value === 'Half') { p = rate * HALF; days += HALF; td.classList.add('half'); }
                total += p; dayTotals[d] += p;
            });
            tr.querySelector('.daysCell').textContent = String(days);
            tr.querySelector('.totalCell').textContent = fmt(total);
            grand += total;
        });
        dayTotals.forEach((v, d) => document.getElementById('dayTot' + d).textContent = fmt(v));
        document.getElementById('grand').textContent = fmt(grand);
    }

    document.getElementById('grid').addEventListener('input',  () => { dirty = true; recalc(); });
    document.getElementById('grid').addEventListener('change', () => { dirty = true; recalc(); });
    document.getElementById('sheet').addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // ---------- A4 portrait print sheet ----------
    const DAYLBL = <?= json_encode($dayLabels) ?>;
    const PERIOD = <?= json_encode($periodLabel, JSON_UNESCAPED_UNICODE) ?>;
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const t12 = v => {
        if (!v) return '';
        const [h, m] = v.split(':').map(Number);
        return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + (h >= 12 ? 'P' : 'A');
    };
    const num = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function buildPrint() {
        recalc();
        let head = '<th>#</th><th>Name</th><th>Rate</th>';
        DAYLBL.forEach(x => head += '<th>' + esc(x.d) + '<br>' + esc(x.date) + '</th>');
        head += '<th>Days</th><th>Total</th><th>Employee Signature</th>';

        let body = '';
        document.querySelectorAll('#grid tbody tr.emp').forEach((tr, i) => {
            const rateRaw = tr.querySelector('.rateIn').value;
            const times = tr.querySelectorAll('input[type=time]');
            let cells = '', any = false;
            tr.querySelectorAll('.typeIn').forEach((sel, d) => {
                const tin = t12(times[d * 2].value), tout = t12(times[d * 2 + 1].value);
                const ty = sel.value === 'Whole' ? 'W' : (sel.value === 'Half' ? 'H' : '');
                if (ty || tin || tout) any = true;
                cells += '<td>' + (ty ? '<b>' + ty + '</b><br>' : '') + esc(tin) + ((tin || tout) ? '<br>' : '') + esc(tout) + '</td>';
            });
            const active = any || rateRaw !== '';
            body += '<tr><td>' + (i + 1) + '</td>'
                 + '<td class="l">' + esc(tr.querySelector('.name input').value) + '</td>'
                 + '<td class="r">' + (rateRaw !== '' ? num(parseFloat(rateRaw) || 0) : '') + '</td>'
                 + cells
                 + '<td>' + (active ? esc(tr.querySelector('.daysCell').textContent) : '') + '</td>'
                 + '<td class="r">' + (active ? esc(tr.querySelector('.totalCell').textContent) : '') + '</td>'
                 + '<td></td></tr>';
        });

        let foot = '<tr><td colspan="3" style="text-align:right">TOTAL</td>';
        DAYLBL.forEach((x, d) => {
            const v = document.getElementById('dayTot' + d).textContent.replace(/[^0-9.,]/g, '');
            foot += '<td class="d">' + esc(v) + '</td>';
        });
        foot += '<td></td><td class="r">' + esc(document.getElementById('grand').textContent) + '</td><td></td></tr>';

        const cols = '<colgroup><col style="width:3%"><col style="width:15%"><col style="width:6%">'
                   + DAYLBL.map(() => '<col style="width:6%">').join('')
                   + '<col style="width:4.5%"><col style="width:9%"><col style="width:20.5%"></colgroup>';

        document.getElementById('printSheet').innerHTML =
            '<h1>PAYROLL</h1><div class="period">' + esc(PERIOD) + '</div>'
            + '<table>' + cols + '<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody><tfoot>' + foot + '</tfoot></table>'
            + '<div class="legend">W = Whole day &nbsp; H = Half day &nbsp;|&nbsp; Each day shows the type, then Time In, then Time Out.</div>';
    }

    window.doPrint = () => { buildPrint(); window.print(); };
    window.addEventListener('beforeprint', buildPrint);   // also covers Ctrl+P
    recalc();

    // "Go" opens the sheet starting at the chosen date
    document.getElementById('goBtn').addEventListener('click', () => {
        const v = document.getElementById('startDate').value;
        if (v) location.href = '?start=' + encodeURIComponent(v);
    });
})();
</script>
</body>
</html>