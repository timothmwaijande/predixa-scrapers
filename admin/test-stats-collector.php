<?php
require_once __DIR__ . '/../config.php';
$db = getDB();

$dateFrom = $_GET['date_from'] ?? ($_GET['date'] ?? date('Y-m-d', strtotime('-1 day')));
$dateTo = $_GET['date_to'] ?? $dateFrom;

$recentDates = [];
$totalRows = 0;
$leagues = [];
$stats = [];
$dateRangeStats = [];

try {
    $totalRows = $db->query("SELECT COUNT(*) FROM match_statistics")->fetchColumn();
    $recentMonths = $db->query("SELECT DATE_FORMAT(match_date, '%Y-%m') as month_key, DATE_FORMAT(match_date, '%b %Y') as month_label, MIN(match_date) as month_start, LAST_DAY(MAX(match_date)) as month_end, SUM(cnt) as cnt FROM (SELECT match_date, COUNT(*) as cnt FROM match_statistics GROUP BY match_date) t GROUP BY month_key ORDER BY month_key DESC LIMIT 24")->fetchAll();
    $leagues = $db->query("SELECT league_name, COUNT(*) as cnt FROM match_statistics GROUP BY league_name ORDER BY cnt DESC")->fetchAll();

    if ($dateFrom === $dateTo) {
        $stmt = $db->prepare("SELECT * FROM match_statistics WHERE match_date = ? ORDER BY league_name, home_team_api");
        $stmt->execute([$dateFrom]);
    } else {
        $stmt = $db->prepare("SELECT * FROM match_statistics WHERE match_date BETWEEN ? AND ? ORDER BY match_date, league_name, home_team_api");
        $stmt->execute([$dateFrom, $dateTo]);
    }
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dateRangeStats = $db->query("SELECT match_date, COUNT(*) as cnt FROM match_statistics GROUP BY match_date ORDER BY match_date DESC")->fetchAll();
} catch (Exception $e) {
    $error = $e->getMessage();
}

$logContent = null;
$logFile = __DIR__ . '/../logs/stats_collector_' . $dateFrom . '.log';
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
}

$teamNames = [];
if ($stats) {
    foreach ($stats as $s) {
        $teamNames[$s['home_team_api']] = true;
        $teamNames[$s['away_team_api']] = true;
    }
    $teamNames = array_values(array_keys($teamNames));
    sort($teamNames, SORT_STRING | SORT_FLAG_CASE);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Match Stats Collector</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        :root { --primary: #8B5CF6; --primary-light: #A78BFA; --accent: #06B6D4; --text: #E2E8F0; --muted: #8899AA; --border: #3d4455; }
        body { font-family: 'Inter', system-ui, sans-serif; background: linear-gradient(135deg, #1e2235 0%, #2a3045 100%); min-height: 100vh; color: var(--text); padding: 16px; }
        .container-fluid { max-width: 1400px; margin: 0 auto; }
        h4 { font-weight: 800; }
        h4 i { color: var(--primary); }
        .card { background: rgba(35,42,60,0.9); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 16px; }

        .stat-card { background: linear-gradient(135deg, rgba(139,92,246,0.18) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.25); border-radius: 10px; padding: 16px; text-align: center; transition: all .2s; }
        .stat-card:hover { border-color: var(--primary); transform: translateY(-1px); }
        .stat-big { font-size: 1.6rem; font-weight: 800; }
        .stat-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin-top: 4px; }

        .form-control, .form-select { background: rgba(35,42,60,0.9); border: 1px solid var(--border); color: var(--text); }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(139,92,246,0.15); color: var(--text); background: rgba(40,48,68,0.95); }
        .form-control::placeholder { color: #6B7280; }
        .btn-outline-secondary { background: rgba(35,42,60,0.9); border: 1px solid var(--border); color: var(--muted); }
        .btn-outline-secondary:hover { background: rgba(50,58,80,0.95); border-color: #5a6270; color: #fff; }

        .league-group { border: 1px solid rgba(139,92,246,0.25); border-radius: 12px; margin-bottom: 14px; overflow: hidden; background: linear-gradient(135deg, rgba(139,92,246,0.1) 0%, rgba(6,182,212,0.06) 100%); }
        .league-header { padding: 12px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none; transition: background 0.15s; background: linear-gradient(135deg, rgba(139,92,246,0.14) 0%, rgba(6,182,212,0.08) 100%); }
        .league-header:hover { background: linear-gradient(135deg, rgba(139,92,246,0.22) 0%, rgba(6,182,212,0.12) 100%); }
        .league-header .league-name { font-weight: 700; font-size: 0.9rem; }
        .league-header .match-count { font-size: 0.72rem; background: rgba(139,92,246,0.3); padding: 2px 10px; border-radius: 12px; font-weight: 600; }
        .league-header .chevron { transition: transform 0.2s; }
        .league-header.collapsed .chevron { transform: rotate(-90deg); }
        .league-body { display: block; padding: 8px; }
        .league-body.collapsed { display: none; }

        .match-card { background: linear-gradient(135deg, rgba(139,92,246,0.22) 0%, rgba(6,182,212,0.12) 100%); border: 1px solid rgba(139,92,246,0.25); border-radius: 10px; padding: 14px 16px; margin-bottom: 8px; transition: all .2s; cursor: pointer; }
        .match-card:hover { border-color: var(--primary); transform: translateY(-1px); box-shadow: 0 4px 15px rgba(139,92,246,0.15); }
        .match-card .teams-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .match-card .team { font-weight: 700; font-size: 0.88rem; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .match-card .team.home { text-align: right; color: #c4b5fd; }
        .match-card .team.away { text-align: left; color: #67e8f9; }
        .match-card .vs-badge { flex-shrink: 0; text-align: center; }
        .match-card .score-display { font-weight: 800; font-size: 1.1rem; color: #fff; background: rgba(139,92,246,0.3); border: 1px solid rgba(139,92,246,0.35); border-radius: 8px; padding: 4px 12px; min-width: 52px; }
        .match-card .match-meta { font-size: 0.65rem; color: var(--text); text-align: center; margin-top: 4px; }

        .match-card .stats-grid { display: grid; grid-template-columns: repeat(10, 1fr); gap: 4px 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(139,92,246,0.15); }
        .match-card .stats-grid-expand { margin-top: 0; padding-top: 0; border-top: none; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid rgba(139,92,246,0.15); }
        .match-card .stat-item { text-align: center; padding: 4px 0; }
        .match-card .stat-item .stat-label-small { font-size: 0.6rem; text-transform: uppercase; color: var(--text); letter-spacing: 0.3px; margin-bottom: 1px; }
        .match-card .stat-item .stat-values { font-size: 0.78rem; font-weight: 600; }
        .match-card .home-val { color: var(--primary-light); }
        .match-card .away-val { color: var(--accent); }

        .match-card .detail-section { display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(139,92,246,0.15); }
        .match-card .detail-section.open { display: block; }
        .match-card .detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 4px 12px; }
        .match-card .detail-item { font-size: 0.75rem; padding: 2px 0; }
        .match-card .detail-label { color: var(--muted); font-size: 0.68rem; text-transform: uppercase; }

        .log-box { background: rgba(25,30,45,0.95); border: 1px solid var(--border); border-radius: 8px; padding: 12px; font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.78rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; color: var(--muted); }

        .date-pills { display: flex; flex-wrap: wrap; gap: 6px; max-height: 80px; overflow-y: auto; }
        .date-pill { padding: 4px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 500; text-decoration: none; border: 1px solid rgba(139,92,246,0.25); color: var(--muted); background: rgba(35,42,60,0.8); transition: all 0.15s; white-space: nowrap; }
        .date-pill:hover { background: rgba(139,92,246,0.15); color: var(--text); border-color: rgba(139,92,246,0.4); }
        .date-pill.active { background: linear-gradient(135deg, rgba(139,92,246,0.35) 0%, rgba(6,182,212,0.15) 100%); color: #fff; border-color: var(--primary); }
        .date-pill .cnt { opacity: 0.7; margin-left: 4px; }

        .results-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .search-wrap { position: relative; display: flex; align-items: center; }
        .search-wrap .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6B7280; font-size: 0.8rem; pointer-events: none; z-index: 2; }
        .search-wrap .form-control { padding-left: 30px; }
        .autocomplete-list { position: absolute; top: 100%; left: 0; right: 0; z-index: 100; max-height: 240px; overflow-y: auto; background: rgba(35,42,60,0.98); border: 1px solid var(--border); border-top: none; border-radius: 0 0 8px 8px; display: none; }
        .autocomplete-list.show { display: block; }
        .autocomplete-item { padding: 6px 12px; font-size: 0.8rem; cursor: pointer; color: #CBD5E1; border-bottom: 1px solid rgba(42,46,53,0.5); }
        .autocomplete-item:hover, .autocomplete-item.active { background: rgba(139,92,246,0.2); color: #fff; }
        .autocomplete-item .league-tag { font-size: 0.65rem; color: var(--muted); margin-left: 6px; }

        .search-highlight { background: rgba(250,204,21,0.25); border-radius: 2px; padding: 0 2px; }
        .no-results { padding: 40px; text-align: center; color: var(--muted); }
        .no-results i { font-size: 2rem; margin-bottom: 12px; display: block; }

        .pagination-bar { display: flex; justify-content: center; align-items: center; gap: 4px; padding: 8px 0; }
        .pagination-bar button { background: rgba(139,92,246,0.15); border: 1px solid var(--border); color: var(--muted); padding: 3px 10px; border-radius: 6px; font-size: 0.75rem; cursor: pointer; transition: all 0.15s; }
        .pagination-bar button:hover:not(:disabled) { background: rgba(139,92,246,0.3); color: var(--text); }
        .pagination-bar button:disabled { opacity: 0.3; cursor: not-allowed; }
        .pagination-bar .page-info { font-size: 0.75rem; color: var(--muted); padding: 0 8px; }

        .date-range-form { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .date-range-form label { font-size: 0.72rem; color: var(--muted); text-transform: uppercase; margin-bottom: 0; }
        .text-muted { color: var(--muted) !important; }
        @media(max-width:768px) { .match-card .team { font-size: 0.78rem; } .stat-big { font-size: 1.2rem; } .match-card .stats-grid, .match-card .stats-grid-expand { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); } }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Match Stats Collector</h4>
            <small class="text-muted">Match statistics from API-Football</small>
        </div>
        <a href="../admin.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Admin</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="stat-big" style="color:var(--primary);"><?= number_format($totalRows) ?></div><div class="stat-label">Total Matches</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="stat-big" style="color:var(--accent);"><?= count($recentMonths) > 0 ? $recentMonths[0]['month_label'] : 'N/A' ?></div><div class="stat-label">Latest Month</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="stat-big" style="color:#22C55E;"><?= count($recentMonths) > 0 ? number_format($recentMonths[0]['cnt']) : 0 ?></div><div class="stat-label">Matches (Latest)</div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="stat-big" style="color:#FBBF24;"><?= count($leagues) ?></div><div class="stat-label">Leagues Covered</div></div>
        </div>
    </div>

    <div class="card">
        <h6 class="mb-3" style="font-weight:700;"><i class="fas fa-calendar-alt me-2" style="color:var(--primary);"></i>Collections by Month</h6>
        <div class="date-pills" id="datePills">
            <?php foreach ($recentMonths as $rm): ?>
                <a href="?date_from=<?= $rm['month_start'] ?>&date_to=<?= $rm['month_end'] ?>" class="date-pill <?= ($dateFrom >= $rm['month_start'] && $dateTo <= $rm['month_end']) ? 'active' : '' ?>"><?= $rm['month_label'] ?><span class="cnt"><?= $rm['cnt'] ?></span></a>
            <?php endforeach; ?>
            <?php if (empty($recentMonths)): ?><span class="text-muted">No data collected yet.</span><?php endif; ?>
        </div>
    </div>

    <div class="card" style="background:linear-gradient(135deg, rgba(139,92,246,0.1) 0%, rgba(6,182,212,0.06) 100%); border:1px solid rgba(139,92,246,0.25);">
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <h6 class="mb-0" style="font-weight:700;"><i class="fas fa-futbol me-2" style="color:var(--accent);"></i><?= htmlspecialchars($dateFrom) ?><?= $dateFrom !== $dateTo ? ' — ' . htmlspecialchars($dateTo) : '' ?> <span class="text-muted" style="font-size:0.8rem;font-weight:400;">(<?= count($stats) ?> matches)</span></h6>
            <form class="date-range-form" method="get">
                <label>From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control form-control-sm" style="width:155px">
                <label>To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control form-control-sm" style="width:155px">
                <button type="submit" class="btn btn-sm" style="background:linear-gradient(135deg,rgba(139,92,246,0.5),rgba(6,182,212,0.3));border:1px solid rgba(139,92,246,0.5);color:#fff;"><i class="fas fa-filter me-1"></i>Filter</button>
            </form>
        </div>

        <div class="results-bar mb-3">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <div class="search-wrap">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search team..." style="width:200px;" autocomplete="off">
                    <div class="autocomplete-list" id="autocompleteList"></div>
                </div>
                <div class="search-wrap">
                    <i class="fas fa-futbol search-icon"></i>
                    <input type="text" id="leagueInput" class="form-control form-control-sm" placeholder="Search league..." style="width:200px;" autocomplete="off">
                    <div class="autocomplete-list" id="leagueAutocomplete"></div>
                </div>
                <span id="resultCount" class="text-muted" style="font-size:0.8rem;"></span>
            </div>
            <div class="d-flex gap-2">
                <button onclick="expandAll()" class="btn btn-sm btn-outline-secondary" style="font-size:0.75rem;"><i class="fas fa-expand-alt me-1"></i>Expand All</button>
                <button onclick="collapseAll()" class="btn btn-sm btn-outline-secondary" style="font-size:0.75rem;"><i class="fas fa-compress-alt me-1"></i>Collapse All</button>
            </div>
        </div>

        <div style="font-size:0.7rem;color:var(--text);padding:6px 10px;margin-bottom:10px;background:rgba(255,255,255,0.03);border-radius:6px;line-height:1.6;">
            <strong>Key:</strong>
            SOT = Shots on Target &nbsp;|&nbsp; YC = Yellow Cards &nbsp;|&nbsp; Poss = Possession &nbsp;|&nbsp; xG = Expected Goals &nbsp;|&nbsp; GK Sv = Goalkeeper Saves &nbsp;|&nbsp; Pass% = Pass Accuracy &nbsp;|&nbsp; Ref = Referee &nbsp;|&nbsp; GP = Goals Prevented
        </div>

        <div id="leagueContainer"></div>
        <div id="noResults" class="no-results" style="display:none;"><i class="fas fa-inbox"></i><div>No matches found.</div></div>
    </div>

    <?php if ($logContent): ?>
    <div class="card">
        <h6 class="mb-3" style="font-weight:700;"><i class="fas fa-terminal me-2" style="color:var(--muted);"></i>Collector Log (<?= $dateFrom ?>)</h6>
        <div class="log-box"><?= htmlspecialchars($logContent) ?></div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const MATCH_DATA = <?= json_encode($stats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const TEAM_NAMES = <?= json_encode($teamNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const ALL_LEAGUES = <?= json_encode(array_map(function($l) { return ['name' => $l['league_name'], 'cnt' => $l['cnt']]; }, $leagues), JSON_HEX_TAG) ?>;
const PER_PAGE = 25;
const IS_RANGE = <?= $dateFrom !== $dateTo ? 'true' : 'false' ?>;

let filteredByLeague = null;
let searchTerm = '';
let leagueSearch = '';
let sortState = {};
let leaguePages = {};
let autocompleteIdx = -1;
let expandedCards = {};

const searchInput = document.getElementById('searchInput');
const autocompleteList = document.getElementById('autocompleteList');
const leagueInput = document.getElementById('leagueInput');
const leagueAutocomplete = document.getElementById('leagueAutocomplete');
const resultCount = document.getElementById('resultCount');
const leagueContainer = document.getElementById('leagueContainer');
const noResults = document.getElementById('noResults');

function getFiltered() {
    let data = MATCH_DATA;
    if (filteredByLeague) data = data.filter(m => m.league_name === filteredByLeague);
    if (leagueSearch && !filteredByLeague) {
        const q = leagueSearch.toLowerCase();
        data = data.filter(m => m.league_name.toLowerCase().includes(q));
    }
    if (searchTerm) {
        const q = searchTerm.toLowerCase();
        data = data.filter(m => m.home_team_api.toLowerCase().includes(q) || m.away_team_api.toLowerCase().includes(q));
    }
    return data;
}

function render() {
    const data = getFiltered();
    resultCount.textContent = data.length + ' match' + (data.length !== 1 ? 'es' : '');

    const groups = {};
    data.forEach(m => {
        if (!groups[m.league_name]) groups[m.league_name] = [];
        groups[m.league_name].push(m);
    });

    const leagueNames = Object.keys(groups).sort((a,b) => {
        const sa = sortState.leagueAsc;
        const cmp = groups[b].length - groups[a].length;
        return sa ? -cmp : cmp;
    });

    if (leagueNames.length === 0) {
        leagueContainer.innerHTML = '';
        noResults.style.display = '';
        return;
    }
    noResults.style.display = 'none';

    let html = '';
    leagueNames.forEach(lname => {
        const matches = groups[lname];
        const sortKey = sortState[lname + '_key'];
        const sortAsc = sortState[lname + '_asc'];
        if (sortKey) {
            matches.sort((a, b) => {
                let va, vb;
                if (['sot','shots','corners','fouls','yc','poss','xg'].includes(sortKey)) {
                    va = parseFloat((a['home_' + sortKey] || 0)) + parseFloat((a['away_' + sortKey] || 0));
                    vb = parseFloat((b['home_' + sortKey] || 0)) + parseFloat((b['away_' + sortKey] || 0));
                } else if (sortKey === 'home') { va = a.home_team_api.toLowerCase(); vb = b.home_team_api.toLowerCase(); }
                else if (sortKey === 'away') { va = a.away_team_api.toLowerCase(); vb = b.away_team_api.toLowerCase(); }
                else if (sortKey === 'score') { va = parseInt(a.home_score) + parseInt(a.away_score); vb = parseInt(b.home_score) + parseInt(b.away_score); }
                else if (sortKey === 'date') { va = a.match_date; vb = b.match_date; }
                else { return 0; }
                if (va < vb) return sortAsc ? -1 : 1;
                if (va > vb) return sortAsc ? 1 : -1;
                return 0;
            });
        }

        if (!leaguePages[lname]) leaguePages[lname] = 0;
        const showAll = !!sortState['showAll_' + lname];
        const perPage = showAll ? matches.length : PER_PAGE;
        const totalPages = Math.ceil(matches.length / perPage);
        if (leaguePages[lname] >= totalPages) leaguePages[lname] = Math.max(0, totalPages - 1);
        const page = leaguePages[lname];
        const start = page * perPage;
        const pageMatches = showAll ? matches : matches.slice(start, start + perPage);
        const isCollapsed = sortState['collapsed_' + lname] ? 'collapsed' : '';

        html += '<div class="league-group" data-league="' + esc(lname) + '">';
        html += '<div class="league-header ' + isCollapsed + '" onclick="toggleLeague(\'' + escJS(lname) + '\')">';
        html += '<div class="d-flex align-items-center gap-2"><i class="fas fa-chevron-down chevron" style="font-size:0.7rem;"></i>';
        html += '<span class="league-name">' + esc(lname) + '</span></div>';
        html += '<span class="match-count">' + matches.length + ' matches</span></div>';
        html += '<div class="league-body' + (isCollapsed ? ' collapsed' : '') + '">';

        pageMatches.forEach(m => {
            const q = searchTerm.toLowerCase();
            const cardId = m.home_team_api + '_' + m.away_team_api + '_' + m.match_date;
            const isExpanded = expandedCards[cardId] ? 'open' : '';

            html += '<div class="match-card" onclick="toggleCard(this, \'' + escJS(cardId) + '\')">';
            html += '<div class="teams-row">';
            html += '<div class="team home" title="' + esc(m.home_team_api) + '">' + hl(m.home_team_api, q) + '</div>';
            html += '<div class="vs-badge">';
            html += '<div class="score-display">' + m.home_score + ' - ' + m.away_score + '</div>';
            html += '</div>';
            html += '<div class="team away" title="' + esc(m.away_team_api) + '">' + hl(m.away_team_api, q) + '</div>';
            html += '</div>';
            html += '<div class="match-meta">Match Date: ' + esc(m.match_date) + (m.venue ? ' &middot; Venue: ' + esc(m.venue) : '') + '</div>';

            html += '<div class="stats-grid">';
            html += '<div class="stat-item"><div class="stat-label-small">SOT</div><div class="stat-values"><span class="home-val">' + nv(m.home_shots_on_goal) + '</span> / <span class="away-val">' + nv(m.away_shots_on_goal) + '</span></div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">Shots</div><div class="stat-values"><span class="home-val">' + nv(m.home_total_shots) + '</span> / <span class="away-val">' + nv(m.away_total_shots) + '</span></div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">Corners</div><div class="stat-values"><span class="home-val">' + nv(m.home_corner_kicks) + '</span> / <span class="away-val">' + nv(m.away_corner_kicks) + '</span></div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">Fouls</div><div class="stat-values"><span class="home-val">' + nv(m.home_fouls) + '</span> / <span class="away-val">' + nv(m.away_fouls) + '</span></div></div>';
            const ycTotal = (m.home_yellow_cards||0)*1 + (m.away_yellow_cards||0)*1;
            html += '<div class="stat-item"><div class="stat-label-small">YC</div><div class="stat-values">' + (ycTotal > 0 ? '<span class="home-val">' + nv(m.home_yellow_cards) + '</span> / <span class="away-val">' + nv(m.away_yellow_cards) + '</span>' : '-') + '</div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">Poss</div><div class="stat-values"><span class="home-val">' + (m.home_ball_possession || '-') + '</span> / <span class="away-val">' + (m.away_ball_possession || '-') + '</span></div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">xG</div><div class="stat-values">' + (m.home_expected_goals != null ? '<span class="home-val">' + parseFloat(m.home_expected_goals).toFixed(2) + '</span> / <span class="away-val">' + parseFloat(m.away_expected_goals).toFixed(2) + '</span>' : '-') + '</div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">GK Sv</div><div class="stat-values"><span class="home-val">' + nv(m.home_goalkeeper_saves) + '</span> / <span class="away-val">' + nv(m.away_goalkeeper_saves) + '</span></div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">Pass%</div><div class="stat-values"><span class="home-val">' + nv(m.home_pass_accuracy) + '</span> / <span class="away-val">' + nv(m.away_pass_accuracy) + '</span></div></div>';
            html += '<div class="stat-item"><div class="stat-label-small">Ref</div><div class="stat-values" style="font-size:0.7rem;color:var(--muted);">' + esc(m.referee || '-') + '</div></div>';
            html += '</div>';
            html += '<div class="detail-section ' + isExpanded + '" id="detail-' + escJS(cardId) + '">';
            html += '<div class="detail-grid">';
            const detailRows = [
                ['Goals Prevented', m.home_goals_prevented != null ? parseFloat(m.home_goals_prevented).toFixed(2) : '-', m.away_goals_prevented != null ? parseFloat(m.away_goals_prevented).toFixed(2) : '-'],
                ['Total Passes', nv(m.home_total_passes), nv(m.away_total_passes)],
                ['Shots Off Target', nv(m.home_shots_off_goal), nv(m.away_shots_off_goal)],
                ['Blocked Shots', nv(m.home_blocked_shots), nv(m.away_blocked_shots)],
                ['Shots Inside Box', nv(m.home_shots_inside_box), nv(m.away_shots_inside_box)],
                ['Shots Outside Box', nv(m.home_shots_outside_box), nv(m.away_shots_outside_box)],
                ['Offsides', nv(m.home_offsides), nv(m.away_offsides)],
                ['Free Kicks', nv(m.home_free_kicks), nv(m.away_free_kicks)],
                ['Passes Accurate', nv(m.home_passes_accurate), nv(m.away_passes_accurate)],
            ];
            detailRows.forEach(dr => {
                if (dr[1] === null || (dr.length === 3 && dr[1] === '-' && dr[2] === '-')) return;
                html += '<div class="detail-item"><span class="detail-label">' + dr[0] + '</span><br>';
                if (dr.length === 3 && dr[2] !== '' && dr[2] !== null) {
                    html += '<span class="home-val">' + dr[1] + '</span> / <span class="away-val">' + dr[2] + '</span>';
                } else {
                    html += '<span>' + dr[1] + '</span>';
                }
                html += '</div>';
            });
            html += '</div></div>';
            html += '</div>';
        });

        if (matches.length > PER_PAGE) {
            html += '<div class="pagination-bar">';
            if (showAll) {
                html += '<button onclick="toggleShowAll(\'' + escJS(lname) + '\',false)"><i class="fas fa-list me-1"></i>Paginate (' + matches.length + ' total)</button>';
            } else {
                html += '<button onclick="goPage(\'' + escJS(lname) + '\',0)"' + (page===0?' disabled':'') + '><i class="fas fa-angle-double-left"></i></button>';
                html += '<button onclick="goPage(\'' + escJS(lname) + '\',' + (page-1) + ')"' + (page===0?' disabled':'') + '><i class="fas fa-angle-left"></i></button>';
                html += '<span class="page-info">' + (start+1) + '-' + Math.min(start + perPage, matches.length) + ' of ' + matches.length + '</span>';
                html += '<button onclick="goPage(\'' + escJS(lname) + '\',' + (page+1) + ')"' + (page>=totalPages-1?' disabled':'') + '><i class="fas fa-angle-right"></i></button>';
                html += '<button onclick="goPage(\'' + escJS(lname) + '\',' + (totalPages-1) + ')"' + (page>=totalPages-1?' disabled':'') + '><i class="fas fa-angle-double-right"></i></button>';
                html += '<button onclick="toggleShowAll(\'' + escJS(lname) + '\',true)"><i class="fas fa-expand-alt me-1"></i>Show All</button>';
            }
            html += '</div>';
        }

        html += '</div></div>';
    });

    leagueContainer.innerHTML = html;

    document.querySelectorAll('.league-header').forEach(h => {
        if (sortState['collapsed_' + h.closest('.league-group').dataset.league]) {
            h.classList.add('collapsed');
            h.nextElementSibling.classList.add('collapsed');
        }
    });
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escJS(s) { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function nv(v) { return v != null ? v : '-'; }
function hl(text, q) {
    if (!q) return esc(text);
    const safe = esc(text);
    const regex = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return safe.replace(regex, '<span class="search-highlight">$1</span>');
}

function toggleLeague(name) {
    sortState['collapsed_' + name] = !sortState['collapsed_' + name];
    render();
}
function expandAll() {
    Object.keys(leaguePages).forEach(k => sortState['collapsed_' + k] = false);
    render();
}
function collapseAll() {
    Object.keys(leaguePages).forEach(k => sortState['collapsed_' + k] = true);
    render();
}
function goPage(name, page) {
    leaguePages[name] = Math.max(0, page);
    render();
}
function toggleShowAll(name, show) {
    sortState['showAll_' + name] = show;
    leaguePages[name] = 0;
    render();
}
function sortLeague(name, key) {
    if (sortState[name + '_key'] === key) { sortState[name + '_asc'] = !sortState[name + '_asc']; }
    else { sortState[name + '_key'] = key; sortState[name + '_asc'] = true; }
    render();
}

function toggleCard(cardEl, cardId) {
    const detail = cardEl.querySelector('.detail-section');
    if (!detail) return;
    const isOpen = detail.classList.contains('open');
    detail.classList.toggle('open');
    expandedCards[cardId] = !isOpen;
}

searchInput.addEventListener('input', function() {
    searchTerm = this.value.trim();
    leaguePages = {};
    render();
    updateTeamAutocomplete();
});
searchInput.addEventListener('keydown', function(e) {
    const items = autocompleteList.querySelectorAll('.autocomplete-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); autocompleteIdx = Math.min(autocompleteIdx + 1, items.length - 1); setACActive(items, autocompleteList); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); autocompleteIdx = Math.max(autocompleteIdx - 1, 0); setACActive(items, autocompleteList); }
    else if (e.key === 'Enter' && autocompleteIdx >= 0) { e.preventDefault(); selectTeamAC(items[autocompleteIdx]); }
    else if (e.key === 'Escape') { autocompleteList.classList.remove('show'); autocompleteIdx = -1; }
});

leagueInput.addEventListener('input', function() {
    leagueSearch = this.value.trim();
    filteredByLeague = null;
    leaguePages = {};
    render();
    updateLeagueAutocomplete();
});
leagueInput.addEventListener('keydown', function(e) {
    const items = leagueAutocomplete.querySelectorAll('.autocomplete-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); autocompleteIdx = Math.min(autocompleteIdx + 1, items.length - 1); setACActive(items, leagueAutocomplete); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); autocompleteIdx = Math.max(autocompleteIdx - 1, 0); setACActive(items, leagueAutocomplete); }
    else if (e.key === 'Enter' && autocompleteIdx >= 0) { e.preventDefault(); selectLeagueAC(items[autocompleteIdx]); }
    else if (e.key === 'Escape') { leagueAutocomplete.classList.remove('show'); autocompleteIdx = -1; }
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('#searchInput') && !e.target.closest('#autocompleteList')) autocompleteList.classList.remove('show');
    if (!e.target.closest('#leagueInput') && !e.target.closest('#leagueAutocomplete')) leagueAutocomplete.classList.remove('show');
});

function updateTeamAutocomplete() {
    const q = searchTerm.toLowerCase();
    autocompleteIdx = -1;
    if (!q || q.length < 1) { autocompleteList.classList.remove('show'); return; }
    const teamLeagueMap = {};
    MATCH_DATA.forEach(m => {
        if (m.home_team_api.toLowerCase().includes(q)) teamLeagueMap[m.home_team_api] = m.league_name;
        if (m.away_team_api.toLowerCase().includes(q)) teamLeagueMap[m.away_team_api] = m.league_name;
    });
    const matches = Object.entries(teamLeagueMap).sort((a,b) => a[0].localeCompare(b[0])).slice(0, 15);
    if (!matches.length) { autocompleteList.classList.remove('show'); return; }
    let html = '';
    matches.forEach(([name, league]) => {
        html += '<div class="autocomplete-item" data-team="' + esc(name) + '" onclick="selectTeamAC(this)">' + hl(name, q) + '<span class="league-tag">' + esc(league) + '</span></div>';
    });
    autocompleteList.innerHTML = html;
    autocompleteList.classList.add('show');
}

function updateLeagueAutocomplete() {
    const q = leagueSearch.toLowerCase();
    autocompleteIdx = -1;
    if (!q || q.length < 1) { leagueAutocomplete.classList.remove('show'); return; }
    const matches = ALL_LEAGUES.filter(l => l.name.toLowerCase().includes(q)).slice(0, 15);
    if (!matches.length) { leagueAutocomplete.classList.remove('show'); return; }
    let html = '';
    matches.forEach(l => {
        html += '<div class="autocomplete-item" data-league="' + esc(l.name) + '" onclick="selectLeagueAC(this)">' + hl(l.name, q) + '<span class="league-tag">' + l.cnt + ' matches</span></div>';
    });
    leagueAutocomplete.innerHTML = html;
    leagueAutocomplete.classList.add('show');
}

function setACActive(items, list) {
    items.forEach((it,i) => it.classList.toggle('active', i === autocompleteIdx));
    if (items[autocompleteIdx]) items[autocompleteIdx].scrollIntoView({ block: 'nearest' });
}
function selectTeamAC(el) {
    searchInput.value = el.dataset.team;
    searchTerm = el.dataset.team;
    autocompleteList.classList.remove('show');
    leaguePages = {};
    render();
}
function selectLeagueAC(el) {
    filteredByLeague = el.dataset.league;
    leagueInput.value = el.dataset.league;
    leagueAutocomplete.classList.remove('show');
    leaguePages = {};
    render();
}

render();
</script>
</body>
</html>
