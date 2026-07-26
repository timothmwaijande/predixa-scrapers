<?php
require_once __DIR__ . '/../config.php';

class BayesianModel {
    private $db;
    private $priorStrength = 20;
    private $recencyHalfLife = 90;
    private $leaguePriors = [];
    private static $teamAliases = [];
    private static $tableEnsured = false;
    private $homeAdvantageCache = [];

    public function __construct() {
        $this->db = getDB();
        $this->ensureTable();
        $this->loadTeamAliases();
        $this->computeLeaguePriors();
    }

    private function ensureTable() {
        if (!$this->db || self::$tableEnsured) return;
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS `bayesian_predictions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `home_team` VARCHAR(255) NOT NULL,
                `away_team` VARCHAR(255) NOT NULL,
                `match_name` VARCHAR(255) NOT NULL,
                `league` VARCHAR(255) DEFAULT NULL,
                `match_date` DATE NOT NULL,
                `prob_1` DECIMAL(5,2) DEFAULT NULL,
                `prob_x` DECIMAL(5,2) DEFAULT NULL,
                `prob_2` DECIMAL(5,2) DEFAULT NULL,
                `prob_1x` DECIMAL(5,2) DEFAULT NULL,
                `prob_x2` DECIMAL(5,2) DEFAULT NULL,
                `prob_12` DECIMAL(5,2) DEFAULT NULL,
                `over_25` DECIMAL(5,2) DEFAULT NULL,
                `under_25` DECIMAL(5,2) DEFAULT NULL,
                `btts_yes` DECIMAL(5,2) DEFAULT NULL,
                `btts_no` DECIMAL(5,2) DEFAULT NULL,
                `expected_goals` DECIMAL(4,2) DEFAULT NULL,
                `confidence` DECIMAL(5,2) DEFAULT NULL,
                `recommended_pick` VARCHAR(100) DEFAULT NULL,
                `value_edge_1` DECIMAL(5,2) DEFAULT NULL,
                `value_edge_x` DECIMAL(5,2) DEFAULT NULL,
                `value_edge_2` DECIMAL(5,2) DEFAULT NULL,
                `value_pick` VARCHAR(100) DEFAULT NULL,
                `market_odds_1` DECIMAL(8,2) DEFAULT NULL,
                `market_odds_x` DECIMAL(8,2) DEFAULT NULL,
                `market_odds_2` DECIMAL(8,2) DEFAULT NULL,
                `home_score` INT DEFAULT NULL,
                `away_score` INT DEFAULT NULL,
                `result` ENUM('pending','correct','incorrect','void') NOT NULL DEFAULT 'pending',
                `settled_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_match_date` (`match_date`),
                INDEX `idx_result` (`result`),
                INDEX `idx_league` (`league`),
                INDEX `idx_match_name` (`match_name`),
                UNIQUE KEY `uq_bayesian_match` (`match_name`(100), `match_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $cols = [];
            $q = $this->db->query("SHOW COLUMNS FROM bayesian_predictions");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $c) $cols[] = $c;

            $migrations = [
                'value_edge_1' => "ALTER TABLE bayesian_predictions ADD COLUMN `value_edge_1` DECIMAL(5,2) DEFAULT NULL AFTER `recommended_pick`",
                'value_edge_x' => "ALTER TABLE bayesian_predictions ADD COLUMN `value_edge_x` DECIMAL(5,2) DEFAULT NULL AFTER `value_edge_1`",
                'value_edge_2' => "ALTER TABLE bayesian_predictions ADD COLUMN `value_edge_2` DECIMAL(5,2) DEFAULT NULL AFTER `value_edge_x`",
                'value_pick' => "ALTER TABLE bayesian_predictions ADD COLUMN `value_pick` VARCHAR(100) DEFAULT NULL AFTER `value_edge_2`",
                'market_odds_1' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_1` DECIMAL(8,2) DEFAULT NULL AFTER `value_pick`",
                'market_odds_x' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_x` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_1`",
                'market_odds_2' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_2` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_x`",
                'prob_over_15' => "ALTER TABLE bayesian_predictions ADD COLUMN `prob_over_15` DECIMAL(5,2) DEFAULT NULL AFTER `under_25`",
                'prob_under_15' => "ALTER TABLE bayesian_predictions ADD COLUMN `prob_under_15` DECIMAL(5,2) DEFAULT NULL AFTER `prob_over_15`",
                'prob_over_35' => "ALTER TABLE bayesian_predictions ADD COLUMN `prob_over_35` DECIMAL(5,2) DEFAULT NULL AFTER `prob_under_15`",
                'prob_under_35' => "ALTER TABLE bayesian_predictions ADD COLUMN `prob_under_35` DECIMAL(5,2) DEFAULT NULL AFTER `prob_over_35`",
                'market_odds_over15' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_over15` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_2`",
                'market_odds_under25' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_under25` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_over15`",
                'market_odds_under35' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_under35` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_under25`",
                'market_odds_btts_yes' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_btts_yes` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_under35`",
                'market_odds_btts_no' => "ALTER TABLE bayesian_predictions ADD COLUMN `market_odds_btts_no` DECIMAL(8,2) DEFAULT NULL AFTER `market_odds_btts_yes`",
                'match_time' => "ALTER TABLE bayesian_predictions ADD COLUMN `match_time` VARCHAR(50) DEFAULT NULL AFTER `match_date`",
            ];
            foreach ($migrations as $col => $sql) {
                if (!in_array($col, $cols)) {
                    try { $this->db->exec($sql); } catch (Exception $e) {}
                }
            }
            self::$tableEnsured = true;
        } catch (Exception $e) {
            error_log("BayesianModel::ensureTable: " . $e->getMessage());
        }
    }

    private function loadTeamAliases() {
        if (!empty(self::$teamAliases)) return;
        if (!$this->db) return;
        try {
            self::$teamAliases = [];
            // Load from teams table first (preferred)
            $stmt = $this->db->query("SELECT id, name, normalized_name, aliases FROM teams");
            foreach ($stmt->fetchAll() as $r) {
                $key = $r['normalized_name'];
                $names = [$r['name']];
                if ($r['aliases']) {
                    $extra = json_decode($r['aliases'], true);
                    if (is_array($extra)) $names = array_merge($names, $extra);
                }
                $entry = ['id' => (int)$r['id'], 'names' => $names];
                self::$teamAliases[$key] = $entry;
                // Register each alias variant as an additional lookup key
                foreach ($names as $n) {
                    $aliasKey = $this->normalizeName($n);
                    if ($aliasKey !== $key && !isset(self::$teamAliases[$aliasKey])) {
                        self::$teamAliases[$aliasKey] = $entry;
                    }
                }
            }
            // If teams table is empty, fall back to match_results
            if (empty(self::$teamAliases)) {
                $stmt = $this->db->query("SELECT DISTINCT home_team FROM match_results UNION SELECT DISTINCT away_team FROM match_results");
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $key = $this->normalizeName($name);
                    if (!isset(self::$teamAliases[$key])) self::$teamAliases[$key] = ['id' => null, 'names' => []];
                    self::$teamAliases[$key]['names'][] = $name;
                }
            }
        } catch (Exception $e) {}
    }

    private function getTeamId($name) {
        $key = $this->normalizeName($name);
        if (isset(self::$teamAliases[$key]) && self::$teamAliases[$key]['id']) {
            return self::$teamAliases[$key]['id'];
        }
        // Fuzzy fallback
        $best = null; $bestScore = 0;
        foreach (self::$teamAliases as $dbKey => $entry) {
            if (!$entry['id']) continue;
            $score = $this->bigramSimilarity($key, $dbKey);
            if ($score > $bestScore && $score >= 0.55) {
                $bestScore = $score;
                $best = $entry['id'];
            }
        }
        return $best;
    }

    public function resolveTeamName($scrapedName) {
        $key = $this->normalizeName($scrapedName);
        if (isset(self::$teamAliases[$key]) && !empty(self::$teamAliases[$key]['names'])) {
            return self::$teamAliases[$key]['names'][0];
        }
        $best = null; $bestScore = 0;
        foreach (self::$teamAliases as $dbKey => $entry) {
            $score = $this->bigramSimilarity($key, $dbKey);
            if ($score > $bestScore && $score >= 0.55) {
                $bestScore = $score;
                $best = $entry['names'][0] ?? null;
            }
        }
        return $best ?: $scrapedName;
    }

    public function resolveTeamId($scrapedName) {
        return $this->getTeamId($scrapedName);
    }

    private function normalizeName($name) {
        $name = mb_strtolower(trim($name));
        // Transliterate accented/diacritical characters before stripping
        $transliterations = [
            'ö' => 'oe', 'ü' => 'ue', 'ä' => 'ae', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ø' => 'o',
            'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ł' => 'l',
            'š' => 's', 'ž' => 'z', 'č' => 'c', 'ć' => 'c', 'đ' => 'd',
        ];
        $name = strtr($name, $transliterations);
        $name = preg_replace('/[^a-z0-9\s\-\']/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        // Strip common prefixes — anchored at start of string to avoid corrupting "afc" → "abournemouth" etc.
        $name = preg_replace('/^(fc|cf|ac|sc|rc|ss|cd|as|sk|fk|nk|ud|ca|cr|ec|aa|ae|ssc|if|bk)\s+/i', '', $name);

        // Abbreviation expansion — only apply if expanded form not already in the name
        // Use word-boundary regex to avoid false matches (e.g. "inter" in "international")
        $expansions = [
            'man utd'       => 'manchester united',
            'man united'    => 'manchester united',
            'man city'      => 'manchester city',
            'manchester cty' => 'manchester city',
            'inter'         => ['inter milan', '/\binter\b(?!\s+(?:miami|salt|nacional|napoli))/i'],
            'milan'         => 'ac milan',
            'bayern'        => 'bayern munich',
            'psg'           => 'paris saint germain',
            'realmadrid'    => 'real madrid',
            'bvb'           => 'borussia dortmund',
        ];
        foreach ($expansions as $abbr => $expanded) {
            if (is_array($expanded)) {
                $target = $expanded[0];
                $regex = $expanded[1];
                if (strpos($name, $target) === false) {
                    $name = preg_replace($regex, $target, $name);
                }
            } else {
                if (strpos($name, $expanded) === false && !($abbr === 'milan' && strpos($name, 'inter milan') !== false)) {
                    $name = preg_replace('/\b' . preg_quote($abbr, '/') . '\b/', $expanded, $name);
                }
            }
        }

        // Short-name aliases (always safe — single word to single word)
        $shortcuts = [
            'tot' => 'tottenham', 'spurs' => 'tottenham',
            'ars' => 'arsenal', 'che' => 'chelsea',
            'liv' => 'liverpool', 'new' => 'newcastle',
            'juve' => 'juventus', 'nap' => 'napoli',
            'barca' => 'barcelona',
        ];
        foreach ($shortcuts as $abbr => $expanded) {
            if (strpos($name, $expanded) === false) {
                $name = preg_replace('/\b' . preg_quote($abbr, '/') . '\b/', $expanded, $name);
            }
        }

        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    private function bigramSimilarity($a, $b) {
        if ($a === $b) return 1.0;
        $bgA = $this->bigrams($a);
        $bgB = $this->bigrams($b);
        if (empty($bgA) || empty($bgB)) return 0;
        $intersect = array_intersect($bgA, $bgB);
        return 2 * count($intersect) / (count($bgA) + count($bgB));
    }

    private function bigrams($s) {
        $r = [];
        for ($i = 0; $i < mb_strlen($s) - 1; $i++) {
            $r[] = mb_substr($s, $i, 2);
        }
        return $r;
    }

    public function resolveMatchTeams($scrapedMatchName) {
        $parts = preg_split('/\s+vs\.?\s+/i', trim($scrapedMatchName), 2);
        if (count($parts) !== 2) return null;
        return [
            'original' => $scrapedMatchName,
            'home' => $parts[0],
            'away' => $parts[1],
            'resolved_home' => $this->resolveTeamName($parts[0]),
            'resolved_away' => $this->resolveTeamName($parts[1]),
        ];
    }

    private function computeLeaguePriors() {
        if (!$this->db) return;
        $cacheKey = 'bayesian_league_priors';
        $cached = isset($GLOBALS[$cacheKey]) ? $GLOBALS[$cacheKey] : null;
        if ($cached !== null) { $this->leaguePriors = $cached; return; }

        try {
            $stmt = $this->db->query("
                SELECT
                    league,
                    COUNT(*) as total,
                    SUM(CASE WHEN home_score > away_score THEN 1 ELSE 0 END) as home_wins,
                    SUM(CASE WHEN home_score = away_score THEN 1 ELSE 0 END) as draws,
                    SUM(CASE WHEN home_score < away_score THEN 1 ELSE 0 END) as away_wins,
                    AVG(home_score + away_score) as avg_goals,
                    SUM(CASE WHEN home_score > 0 AND away_score > 0 THEN 1 ELSE 0 END) as btts
                FROM match_results
                WHERE match_date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
                  AND home_score IS NOT NULL AND away_score IS NOT NULL
                GROUP BY league
                HAVING total >= 10
            ");
            $priors = [];
            foreach ($stmt->fetchAll() as $r) {
                $t = (int)$r['total'];
                $hw = (int)$r['home_wins'];
                $d = (int)$r['draws'];
                $aw = (int)$r['away_wins'];
                $priors[$r['league']] = [
                    'total' => $t,
                    'home_win_rate' => $hw / $t,
                    'draw_rate' => $d / $t,
                    'away_win_rate' => $aw / $t,
                    'avg_goals' => (float)$r['avg_goals'],
                    'btts_rate' => $t > 0 ? (int)$r['btts'] / $t : 0,
                ];
            }
            $priors['__global__'] = $this->computeGlobalPrior($priors);
            $this->leaguePriors = $priors;
            $GLOBALS[$cacheKey] = $priors;
        } catch (Exception $e) {
            error_log("BayesianModel::computeLeaguePriors: " . $e->getMessage());
        }
    }

    private function computeGlobalPrior($priors) {
        $total = 0; $hw = 0; $d = 0; $aw = 0; $goals = 0; $btts = 0; $count = 0;
        foreach ($priors as $l => $p) {
            $total += $p['total'];
            $hw += $p['home_win_rate'] * $p['total'];
            $d += $p['draw_rate'] * $p['total'];
            $aw += $p['away_win_rate'] * $p['total'];
            $goals += $p['avg_goals'] * $p['total'];
            $btts += $p['btts_rate'] * $p['total'];
            $count += $p['total'];
        }
        return $count > 0 ? [
            'total' => $total,
            'home_win_rate' => $hw / $count,
            'draw_rate' => $d / $count,
            'away_win_rate' => $aw / $count,
            'avg_goals' => $goals / $count,
            'btts_rate' => $btts / $count,
        ] : [
            'total' => 0, 'home_win_rate' => 0.45, 'draw_rate' => 0.25, 'away_win_rate' => 0.30,
            'avg_goals' => 2.5, 'btts_rate' => 0.50,
        ];
    }

    private function getLeaguePrior($league) {
        if (!$league || !isset($this->leaguePriors[$league])) {
            return $this->leaguePriors['__global__'] ?? [
                'home_win_rate' => 0.45, 'draw_rate' => 0.25, 'away_win_rate' => 0.30,
                'avg_goals' => 2.5, 'btts_rate' => 0.50,
            ];
        }
        return $this->leaguePriors[$league];
    }

    public function predict($homeTeam, $awayTeam, $league = null, $lookbackDays = 365, $bookmakerOdds = null) {
        $prior = $this->getLeaguePrior($league);
        $homeForm = $this->getTeamHistory($homeTeam, $league, true, $lookbackDays);
        $awayForm = $this->getTeamHistory($awayTeam, $league, false, $lookbackDays);
        $h2h = $this->getHeadToHead($homeTeam, $awayTeam, $lookbackDays);
        $homeDefense = $this->getDefenseStats($homeTeam, $lookbackDays);
        $awayDefense = $this->getDefenseStats($awayTeam, $lookbackDays);
        $homeStats = $this->getTeamStatsProfile($homeTeam, $lookbackDays);
        $awayStats = $this->getTeamStatsProfile($awayTeam, $lookbackDays);

        $homeMatches = $homeForm['matches'];
        $awayMatches = $awayForm['matches'];
        $k = $this->priorStrength;

        $teamHA = $this->getTeamHomeAdvantage($homeTeam, $league);
        $effectiveHomeRate = $prior['home_win_rate'];
        $effectiveAwayRate = $prior['away_win_rate'];
        if ($teamHA && $teamHA['total'] >= 5) {
            $teamWeight = min(0.7, $teamHA['total'] / ($teamHA['total'] + 20));
            $effectiveHomeRate = $prior['home_win_rate'] * (1 - $teamWeight) + $teamHA['home_win_rate'] * $teamWeight;
            $effectiveAwayRate = $prior['away_win_rate'] * (1 - $teamWeight) + $teamHA['loss_rate'] * $teamWeight;
        }

        $alphaHome = $effectiveHomeRate * $k + $homeForm['wins'];
        $betaHome = (1 - $effectiveHomeRate) * $k + ($homeMatches - $homeForm['wins']);
        $postHome = $alphaHome / ($alphaHome + $betaHome);

        $homeDrawRate = $homeMatches > 0 ? $homeForm['draws'] / $homeMatches : $prior['draw_rate'];
        $awayDrawRate = $awayMatches > 0 ? $awayForm['draws'] / $awayMatches : $prior['draw_rate'];
        $combinedDrawRate = ($homeDrawRate * $homeMatches + $awayDrawRate * $awayMatches) / max(1, $homeMatches + $awayMatches);
        $totalMatches = $homeMatches + $awayMatches;
        $alphaDraw = $prior['draw_rate'] * $k + $combinedDrawRate * $totalMatches;
        $betaDraw = (1 - $prior['draw_rate']) * $k + $totalMatches * (1 - $combinedDrawRate);
        $postDraw = $alphaDraw / ($alphaDraw + $betaDraw);

        $alphaAway = $effectiveAwayRate * $k + $awayForm['wins'];
        $betaAway = (1 - $effectiveAwayRate) * $k + ($awayMatches - $awayForm['wins']);
        $postAway = $alphaAway / ($alphaAway + $betaAway);

        $total = $postHome + $postDraw + $postAway;
        if ($total > 0) { $postHome /= $total; $postDraw /= $total; $postAway /= $total; }

        if ($h2h['matches'] >= 3) {
            $h2hHome = $h2h['home_wins'] / max(1, $h2h['matches']);
            $postHome = $postHome * 0.8 + $h2hHome * 0.2;
            $h2hDraw = $h2h['draws'] / max(1, $h2h['matches']);
            $postDraw = $postDraw * 0.8 + $h2hDraw * 0.2;
            $h2hAway = $h2h['away_wins'] / max(1, $h2h['matches']);
            $postAway = $postAway * 0.8 + $h2hAway * 0.2;
            $t2 = $postHome + $postDraw + $postAway;
            if ($t2 > 0) { $postHome /= $t2; $postDraw /= $t2; $postAway /= $t2; }
        }

        if ($homeStats && $awayStats) {
            $hWeight = $homeStats['data_weight'];
            $aWeight = $awayStats['data_weight'];
            $statsWeight = max($hWeight, $aWeight);
            if ($statsWeight >= 0.13) {
                $leagueStats = $this->getLeagueStatsBaseline($lookbackDays);
                $lgAvgXgHome = ($leagueStats && $leagueStats['avg_xg_home'] > 0) ? $leagueStats['avg_xg_home'] : 1.5;
                $lgAvgXgAway = ($leagueStats && $leagueStats['avg_xg_away'] > 0) ? $leagueStats['avg_xg_away'] : 1.1;
                $lgAvgSotHome = ($leagueStats && $leagueStats['avg_sot_home'] > 0) ? $leagueStats['avg_sot_home'] : 4.5;
                $lgAvgSotAway = ($leagueStats && $leagueStats['avg_sot_away'] > 0) ? $leagueStats['avg_sot_away'] : 3.8;

                $homeOff = $homeStats['offensive_strength'] ?? null;
                $awayOff = $awayStats['offensive_strength'] ?? null;
                $homeDef = $homeStats['defensive_strength'] ?? null;
                $awayDef = $awayStats['defensive_strength'] ?? null;
                $homeCtrl = $homeStats['control_strength'] ?? null;
                $awayCtrl = $awayStats['control_strength'] ?? null;

                if ($homeOff !== null && $awayOff !== null) {
                    $homeXgRatio = $homeStats['xg_for'] !== null ? $homeStats['xg_for'] / $lgAvgXgHome : 1.0;
                    $awayXgRatio = $awayStats['xg_for'] !== null ? $awayStats['xg_for'] / $lgAvgXgAway : 1.0;
                    $homeSotRatio = $lgAvgSotHome > 0 ? $homeStats['sot_for'] / $lgAvgSotHome : 1.0;
                    $awaySotRatio = $lgAvgSotAway > 0 ? $awayStats['sot_for'] / $lgAvgSotAway : 1.0;

                    if ($homeStats['xg_for'] !== null && $awayStats['xg_for'] !== null) {
                        $offRatio = ($homeXgRatio * 0.6 + $homeSotRatio * 0.4) / max(0.3, ($awayXgRatio * 0.6 + $awaySotRatio * 0.4));
                    } else {
                        $offRatio = $homeSotRatio / max(0.3, $awaySotRatio);
                    }
                    $offAdj = log(max(0.3, min(3.0, $offRatio))) * 0.08;
                    $postHome += $offAdj;
                    $postAway -= $offAdj;
                    $t4 = $postHome + $postDraw + $postAway;
                    if ($t4 > 0) { $postHome /= $t4; $postDraw /= $t4; $postAway /= $t4; }
                    $postHome = max(0.01, min(0.99, $postHome));
                    $postAway = max(0.01, min(0.99, $postAway));
                    $postDraw = max(0.01, min(0.99, $postDraw));
                }

                if ($homeDef !== null && $awayDef !== null) {
                    $defDiff2 = ($homeDef - $awayDef);
                    $defAdj2 = $defDiff2 * 0.05;
                    $postHome += $defAdj2;
                    $postAway -= $defAdj2;
                    $t5 = $postHome + $postDraw + $postAway;
                    if ($t5 > 0) { $postHome /= $t5; $postDraw /= $t5; $postAway /= $t5; }
                    $postHome = max(0.01, min(0.99, $postHome));
                    $postAway = max(0.01, min(0.99, $postAway));
                    $postDraw = max(0.01, min(0.99, $postDraw));
                }

                if ($homeCtrl !== null && $awayCtrl !== null) {
                    $ctrlDiff = ($homeCtrl - $awayCtrl);
                    $ctrlAdj = $ctrlDiff * 0.03;
                    $postHome += $ctrlAdj;
                    $postAway -= $ctrlAdj;
                    $t6 = $postHome + $postDraw + $postAway;
                    if ($t6 > 0) { $postHome /= $t6; $postDraw /= $t6; $postAway /= $t6; }
                    $postHome = max(0.01, min(0.99, $postHome));
                    $postAway = max(0.01, min(0.99, $postAway));
                    $postDraw = max(0.01, min(0.99, $postDraw));
                }

                $homeDiscipline = $homeStats['cards_per_game'];
                $awayDiscipline = $awayStats['cards_per_game'];
                if ($homeDiscipline > 4 || $awayDiscipline > 4) {
                    $homeDiscAdj = ($awayDiscipline - $homeDiscipline) * 0.005;
                    $postHome += $homeDiscAdj;
                    $postAway -= $homeDiscAdj;
                    $t7 = $postHome + $postDraw + $postAway;
                    if ($t7 > 0) { $postHome /= $t7; $postDraw /= $t7; $postAway /= $t7; }
                    $postHome = max(0.01, min(0.99, $postHome));
                    $postAway = max(0.01, min(0.99, $postAway));
                    $postDraw = max(0.01, min(0.99, $postDraw));
                }
            }
        }

        $ou = $this->predictOverUnder($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays, $homeStats, $awayStats);
        $btts = $this->predictBTTS($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays, $homeStats, $awayStats);

        $dcHomeDraw = $postHome + $postDraw;
        $dcAwayDraw = $postAway + $postDraw;
        $dcHomeAway = $postHome + $postAway;

        $uniform = 1/3;
        $confidence = round((abs($postHome - $uniform) + abs($postDraw - $uniform) + abs($postAway - $uniform)) / (2 * (1 - $uniform)) * 100, 1);

        $dataPoints = $homeMatches + $awayMatches + $h2h['matches'];
        // Effective N: use raw data count, not weighted sum (weighted sum underestimates true info)
        $effectiveN = max(10, $dataPoints * 0.8 + $k * 0.3);
        $credible95 = [
            'home' => $this->credibleInterval($postHome, $effectiveN),
            'draw' => $this->credibleInterval($postDraw, $effectiveN),
            'away' => $this->credibleInterval($postAway, $effectiveN),
        ];

        $tempPred = ['probs' => [
            '1' => $postHome * 100, 'X' => $postDraw * 100, '2' => $postAway * 100,
            '1X' => $dcHomeDraw * 100, 'X2' => $dcAwayDraw * 100, '12' => $dcHomeAway * 100,
        ], 'over_under' => $ou, 'btts' => $btts];
        $value = $this->detectValue($tempPred, $bookmakerOdds);

        return [
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'league' => $league,
            'prior' => $prior,
            'home_form' => $homeForm,
            'away_form' => $awayForm,
            'home_defense' => $homeDefense,
            'away_defense' => $awayDefense,
            'home_stats' => $homeStats,
            'away_stats' => $awayStats,
            'h2h' => $h2h,
            'probs' => [
                '1' => round($postHome * 100, 1),
                'X' => round($postDraw * 100, 1),
                '2' => round($postAway * 100, 1),
                '1X' => round($dcHomeDraw * 100, 1),
                'X2' => round($dcAwayDraw * 100, 1),
                '12' => round($dcHomeAway * 100, 1),
            ],
            'over_under' => $ou,
            'btts' => $btts,
            'confidence' => $confidence,
            'credible_intervals' => $credible95,
            'value' => $value,
            'recommended_pick' => $this->getRecommendedPick($postHome, $postDraw, $postAway, $ou, $btts),
            'data_quality' => $dataPoints,
        ];
    }

    public function storePrediction($homeTeam, $awayTeam, $league, $pred, $matchTime = null, $bookmakerOdds = null) {
        if (!$this->db) return false;
        $matchName = $homeTeam . ' vs ' . $awayTeam;
        $recPick = '';
        if (!empty($pred['recommended_pick'])) {
            $parts = [];
            foreach ($pred['recommended_pick'] as $rp) {
                $parts[] = $rp['type'] . ':' . $rp['prob'];
            }
            $recPick = implode(', ', $parts);
        }
        $value = $pred['value'] ?? null;
        if (!$value && $bookmakerOdds) {
            $value = $this->detectValue($pred, $bookmakerOdds);
        }
        $ve1 = $value['edges']['1'] ?? null;
        $veX = $value['edges']['X'] ?? null;
        $ve2 = $value['edges']['2'] ?? null;
        $vpick = (!empty($value['is_value']) && !empty($value['pick'])) ? $value['pick'] : null;
        $mo1 = $bookmakerOdds['1'] ?? null;
        $moX = $bookmakerOdds['X'] ?? null;
        $mo2 = $bookmakerOdds['2'] ?? null;
        try {
            $stmt = $this->db->prepare("
                INSERT INTO bayesian_predictions
                    (home_team, away_team, match_name, league, match_date, match_time,
                     prob_1, prob_x, prob_2, prob_1x, prob_x2, prob_12,
                     over_25, under_25, prob_over_15, prob_under_15, prob_over_35, prob_under_35,
                     btts_yes, btts_no, expected_goals,
                     confidence, recommended_pick,
                     value_edge_1, value_edge_x, value_edge_2, value_pick,
                     market_odds_1, market_odds_x, market_odds_2)
                VALUES (?, ?, ?, ?, CURDATE(), ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    prob_1 = VALUES(prob_1), prob_x = VALUES(prob_x), prob_2 = VALUES(prob_2),
                    prob_1x = VALUES(prob_1x), prob_x2 = VALUES(prob_x2), prob_12 = VALUES(prob_12),
                    over_25 = VALUES(over_25), under_25 = VALUES(under_25),
                    prob_over_15 = VALUES(prob_over_15), prob_under_15 = VALUES(prob_under_15),
                    prob_over_35 = VALUES(prob_over_35), prob_under_35 = VALUES(prob_under_35),
                    btts_yes = VALUES(btts_yes), btts_no = VALUES(btts_no),
                    expected_goals = VALUES(expected_goals), confidence = VALUES(confidence),
                    recommended_pick = VALUES(recommended_pick),
                    value_edge_1 = VALUES(value_edge_1), value_edge_x = VALUES(value_edge_x),
                    value_edge_2 = VALUES(value_edge_2), value_pick = VALUES(value_pick),
                    market_odds_1 = VALUES(market_odds_1), market_odds_x = VALUES(market_odds_x),
                    market_odds_2 = VALUES(market_odds_2)
            ");
            return $stmt->execute([
                $homeTeam, $awayTeam, $matchName, $league, $matchTime,
                $pred['probs']['1'], $pred['probs']['X'], $pred['probs']['2'],
                $pred['probs']['1X'], $pred['probs']['X2'], $pred['probs']['12'],
                $pred['over_under']['over_25'], $pred['over_under']['under_25'],
                $pred['over_under']['over_15'], $pred['over_under']['under_15'],
                $pred['over_under']['over_35'], $pred['over_under']['under_35'],
                $pred['btts']['yes'], $pred['btts']['no'],
                $pred['over_under']['expected_total_goals'],
                $pred['confidence'], $recPick,
                $ve1, $veX, $ve2, $vpick,
                $mo1, $moX, $mo2,
            ]);
        } catch (Exception $e) {
            error_log("BayesianModel::storePrediction: " . $e->getMessage());
            return false;
        }
    }

    public function runBatchPredictions($lookbackDays = 30) {
        if (!$this->db) return ['stored' => 0, 'skipped' => 0, 'errors' => 0];
        $stored = 0; $skipped = 0; $errors = 0;

        // Get distinct matches from scraper_results + web_picks for today
        $stmt = $this->db->query("
            SELECT match_name, league, match_time FROM (
                SELECT CONVERT(match_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS match_name, CONVERT(league USING utf8mb4) COLLATE utf8mb4_unicode_ci AS league, CONVERT(match_time USING utf8mb4) COLLATE utf8mb4_unicode_ci AS match_time FROM scraper_results WHERE DATE(detected_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                UNION
                SELECT CONVERT(match_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(league USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(match_time USING utf8mb4) COLLATE utf8mb4_unicode_ci FROM web_picks WHERE DATE(detected_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                UNION
                SELECT CONVERT(match_name USING utf8mb4) COLLATE utf8mb4_unicode_ci, CONVERT(league USING utf8mb4) COLLATE utf8mb4_unicode_ci, CAST(match_time AS CHAR) FROM admin_featured_picks WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ) t LIMIT 100
        ");

        foreach ($stmt->fetchAll() as $m) {
            $resolved = $this->resolveMatchTeams($m['match_name']);
            if (!$resolved) { $skipped++; continue; }
            try {
                $pred = $this->predict($resolved['resolved_home'], $resolved['resolved_away'], $m['league']);
                $this->storePrediction($resolved['resolved_home'], $resolved['resolved_away'], $m['league'], $pred, $m['match_time']);
                $stored++;
            } catch (Exception $e) {
                $errors++;
                error_log("BayesianModel::runBatchPredictions error for {$m['match_name']}: " . $e->getMessage());
            }
        }
        return ['stored' => $stored, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function settlePredictions() {
        if (!$this->db) return ['settled' => 0, 'matched' => 0, 'unmatched' => 0];
        $settled = 0; $matched = 0; $unmatched = 0;

        try {
            // Mark stale unmatched predictions older than 3 days as 'stale' so they don't clog the queue
            $staleCutoff = date('Y-m-d', strtotime('-3 days'));
            $this->db->exec("UPDATE bayesian_predictions SET result = 'stale', settled_at = NOW() WHERE result = 'pending' AND match_date < '$staleCutoff'");

            // Process newest predictions first (ORDER BY match_date DESC) — prevents old unmatched rows from blocking recent settles
            $stmt = $this->db->query("
                SELECT id, home_team, away_team, match_name, match_date,
                       prob_1, prob_x, prob_2, over_25, under_25,
                       btts_yes, btts_no, recommended_pick
                FROM bayesian_predictions
                WHERE result = 'pending'
                ORDER BY match_date DESC
                LIMIT 500
            ");

            $updateStmt = $this->db->prepare("UPDATE bayesian_predictions SET result = ?, home_score = ?, away_score = ?, settled_at = NOW() WHERE id = ?");

            foreach ($stmt->fetchAll() as $bp) {
                $homeId = $this->getTeamId($bp['home_team']);
                $awayId = $this->getTeamId($bp['away_team']);
                $matchRow = null;

                // Try by team_id + date first
                if ($homeId && $awayId) {
                    $q = $this->db->prepare("SELECT home_score, away_score FROM match_results WHERE ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?)) AND match_date = ? AND home_score IS NOT NULL AND away_score IS NOT NULL LIMIT 1");
                    $q->execute([$homeId, $awayId, $awayId, $homeId, $bp['match_date']]);
                    $matchRow = $q->fetch();
                }
                // Fallback: exact name match
                if (!$matchRow) {
                    $q = $this->db->prepare("SELECT home_score, away_score FROM match_results WHERE ((home_team = ? AND away_team = ?) OR (home_team = ? AND away_team = ?)) AND match_date = ? AND home_score IS NOT NULL AND away_score IS NOT NULL LIMIT 1");
                    $q->execute([$bp['home_team'], $bp['away_team'], $bp['away_team'], $bp['home_team'], $bp['match_date']]);
                    $matchRow = $q->fetch();
                }
                // Fallback: ±1 day by team_id
                if (!$matchRow && $homeId && $awayId) {
                    $q = $this->db->prepare("SELECT home_score, away_score FROM match_results WHERE ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?)) AND match_date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY) AND home_score IS NOT NULL AND away_score IS NOT NULL ORDER BY ABS(DATEDIFF(match_date, ?)) ASC LIMIT 1");
                    $q->execute([$homeId, $awayId, $awayId, $homeId, $bp['match_date'], $bp['match_date'], $bp['match_date']]);
                    $matchRow = $q->fetch();
                }

                if (!$matchRow) { $unmatched++; continue; }
                $matched++;

                $hs = (int)$matchRow['home_score'];
                $as = (int)$matchRow['away_score'];
                $actualWinner = $hs > $as ? '1' : ($hs === $as ? 'X' : '2');
                $totalG = $hs + $as;
                $bttsActual = ($hs > 0 && $as > 0);

                $correct = true;
                $recPick = $bp['recommended_pick'] ?? '';
                $recPickParts = array_map('trim', explode(',', $recPick));

                $settled1x2 = false;
                $settledOu = false;
                $settledBtts = false;
                foreach ($recPickParts as $rpPart) {
                    $rpTrim = trim($rpPart);
                    $rpColon = strpos($rpTrim, ':');
                    $rpType = $rpColon !== false ? trim(substr($rpTrim, 0, $rpColon)) : $rpTrim;

                    if (!$settled1x2) {
                        if ($rpType === '1X') { if ($actualWinner === '2') $correct = false; $settled1x2 = true; }
                        elseif ($rpType === 'X2') { if ($actualWinner === '1') $correct = false; $settled1x2 = true; }
                        elseif ($rpType === '12') { if ($actualWinner === 'X') $correct = false; $settled1x2 = true; }
                        elseif ($rpType === '1') { if ($actualWinner !== '1') $correct = false; $settled1x2 = true; }
                        elseif ($rpType === 'X') { if ($actualWinner !== 'X') $correct = false; $settled1x2 = true; }
                        elseif ($rpType === '2') { if ($actualWinner !== '2') $correct = false; $settled1x2 = true; }
                    }

                    if (!$settledOu) {
                        if ($rpType === 'Over 2.5' && $totalG <= 2) { $correct = false; $settledOu = true; }
                        elseif ($rpType === 'Under 2.5' && $totalG > 2) { $correct = false; $settledOu = true; }
                        elseif ($rpType === 'Over 1.5' && $totalG <= 1) { $correct = false; $settledOu = true; }
                        elseif ($rpType === 'Under 1.5' && $totalG > 1) { $correct = false; $settledOu = true; }
                        elseif ($rpType === 'Under 3.5' && $totalG > 3) { $correct = false; $settledOu = true; }
                        elseif ($rpType === 'Over 3.5' && $totalG <= 3) { $correct = false; $settledOu = true; }
                    }

                    if (!$settledBtts) {
                        if ($rpType === 'GG' && !$bttsActual) { $correct = false; $settledBtts = true; }
                        elseif ($rpType === 'NG' && $bttsActual) { $correct = false; $settledBtts = true; }
                    }
                }

                $outcome = $correct ? 'correct' : 'incorrect';
                $updateStmt->execute([$outcome, $hs, $as, $bp['id']]);
                $settled++;
            }
        } catch (Exception $e) {
            error_log("BayesianModel::settlePredictions: " . $e->getMessage());
        }
        return ['settled' => $settled, 'matched' => $matched, 'unmatched' => $unmatched];
    }

    public function getAccuracyStats() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) as correct,
                    SUM(CASE WHEN result = 'incorrect' THEN 1 ELSE 0 END) as incorrect,
                    SUM(CASE WHEN result = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN result = 'void' THEN 1 ELSE 0 END) as voided,
                    ROUND(AVG(CASE WHEN result = 'correct' THEN confidence ELSE NULL END), 1) as avg_conf_correct,
                    ROUND(AVG(CASE WHEN result = 'incorrect' THEN confidence ELSE NULL END), 1) as avg_conf_incorrect,
                    MIN(match_date) as from_date, MAX(match_date) as to_date
                FROM bayesian_predictions
            ")->fetch();
        } catch (Exception $e) { return []; }
    }

    public function getTodayPredictions() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT id, home_team, away_team, match_name, league,
                       prob_1, prob_x, prob_2, confidence, recommended_pick
                FROM bayesian_predictions
                WHERE match_date = CURDATE()
                ORDER BY confidence DESC
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function updateValueEdge($id, $edge1, $edgeX, $edge2, $valuePick, $odds1, $oddsX, $odds2, $oddsOver15 = null, $oddsUnder25 = null, $oddsBttsYes = null, $oddsBttsNo = null) {
        if (!$this->db) return false;
        try {
            $stmt = $this->db->prepare("
                UPDATE bayesian_predictions SET
                    value_edge_1 = ?, value_edge_x = ?, value_edge_2 = ?,
                    value_pick = ?,
                    market_odds_1 = ?, market_odds_x = ?, market_odds_2 = ?,
                    market_odds_over15 = ?, market_odds_under25 = ?,
                    market_odds_btts_yes = ?, market_odds_btts_no = ?
                WHERE id = ?
            ");
            return $stmt->execute([$edge1, $edgeX, $edge2, $valuePick, $odds1, $oddsX, $odds2, $oddsOver15, $oddsUnder25, $oddsBttsYes, $oddsBttsNo, $id]);
        } catch (Exception $e) {
            error_log("BayesianModel::updateValueEdge: " . $e->getMessage());
            return false;
        }
    }

    public function getAccuracyByLeague() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT league, COUNT(*) as total,
                       SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) as correct,
                       SUM(CASE WHEN result = 'incorrect' THEN 1 ELSE 0 END) as incorrect,
                       ROUND(SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as accuracy
                FROM bayesian_predictions
                WHERE result IN ('correct','incorrect')
                GROUP BY league
                HAVING total >= 5
                ORDER BY accuracy DESC
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function getAgreementScore($matchName, $pickValue) {
        // How much does Bayesian agree with a given pick?
        if (!$this->db) return null;
        try {
            $stmt = $this->db->prepare("
                SELECT prob_1, prob_x, prob_2, prob_1x, prob_x2, prob_12,
                       over_25, under_25, btts_yes, btts_no, confidence
                FROM bayesian_predictions
                WHERE match_name = ? AND match_date = CURDATE()
                LIMIT 1
            ");
            $stmt->execute([$matchName]);
            $bp = $stmt->fetch();
            if (!$bp) return null;

            $pv = strtoupper(trim($pickValue));
            $bayesProb = null;

            switch ($pv) {
                case '1': $bayesProb = (float)$bp['prob_1']; break;
                case 'X': case 'DRAW': $bayesProb = (float)$bp['prob_x']; break;
                case '2': $bayesProb = (float)$bp['prob_2']; break;
                case '1X': $bayesProb = (float)$bp['prob_1x']; break;
                case 'X2': $bayesProb = (float)$bp['prob_x2']; break;
                case '12': $bayesProb = (float)$bp['prob_12']; break;
            }
            if (preg_match('/^OVER\s+(\d+\.?\d*)\s*GOALS?$/i', $pv, $m)) {
                $thresh = (float)$m[1];
                if ($thresh == 2.5) $bayesProb = (float)$bp['over_25'];
                elseif ($thresh == 1.5) $bayesProb = (float)$bp['over_25'] > 50 ? (float)$bp['over_25'] : 100 - (float)$bp['under_25'];
            }
            if (preg_match('/^UNDER\s+(\d+\.?\d*)\s*GOALS?$/i', $pv, $m)) {
                $thresh = (float)$m[1];
                if ($thresh == 2.5) $bayesProb = (float)$bp['under_25'];
            }
            if (in_array($pv, ['GG', 'BTS', 'BTTS'])) $bayesProb = (float)$bp['btts_yes'];
            if (in_array($pv, ['NG', 'NBTS'])) $bayesProb = (float)$bp['btts_no'];

            if ($bayesProb === null) return null;
            // Agreement: how much Bayesian probability supports this pick
            // If bayesProb > 50%, it agrees with the pick
            // Score = (bayesProb - 50) * 2 → ranges from 0 to 100
            $agreement = round(($bayesProb - 50) * 2, 1);
            return [
                'probability' => $bayesProb,
                'agreement' => max(0, min(100, $agreement)),
                'confidence' => (float)$bp['confidence'],
                'strongly_agrees' => $agreement > 30,
                'disagrees' => $agreement < -10,
            ];
        } catch (Exception $e) { return null; }
    }

    private function getTeamHistory($team, $league, $isHome, $lookbackDays) {
        $default = ['matches' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'form' => []];
        if (!$this->db) return $default;
        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $teamId = $this->getTeamId($team);
            if ($teamId) {
                $stmt = $this->db->prepare("
                    SELECT home_score, away_score, match_date
                    FROM match_results
                    WHERE (home_team_id = ? OR away_team_id = ?)
                      AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC
                    LIMIT 30
                ");
                $stmt->execute([$teamId, $teamId, $lookback]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT home_score, away_score, match_date
                    FROM match_results
                    WHERE (home_team = ? OR away_team = ?)
                      AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC
                    LIMIT 30
                ");
                $stmt->execute([$team, $team, $lookback]);
            }
            $rows = $stmt->fetchAll();
            if (empty($rows)) return $default;

            $nowTs = time();
            $wins = 0; $draws = 0; $losses = 0; $gf = 0; $ga = 0; $totalWeight = 0; $form = [];
            foreach ($rows as $r) {
                $daysAgo = ($nowTs - strtotime($r['match_date'])) / 86400;
                $weight = exp(-$daysAgo / $this->recencyHalfLife);
                $totalWeight += $weight;
                $h = (int)$r['home_score'];
                $a = (int)$r['away_score'];
                if ($isHome) { $gf += $h * $weight; $ga += $a * $weight; $r_ = $h > $a ? 'W' : ($h === $a ? 'D' : 'L'); }
                else { $gf += $a * $weight; $ga += $h * $weight; $r_ = $a > $h ? 'W' : ($a === $h ? 'D' : 'L'); }
                if ($r_ === 'W') $wins += $weight; elseif ($r_ === 'D') $draws += $weight; else $losses += $weight;
                $form[] = $r_;
            }
            $effectiveMatches = max(1, $totalWeight);
            return [
                'matches' => round($effectiveMatches, 1), 'wins' => round($wins, 1), 'draws' => round($draws, 1), 'losses' => round($losses, 1),
                'gf' => round($gf, 1), 'ga' => round($ga, 1),
                'form' => implode('', array_slice($form, 0, 5)),
                'form_rating' => round(($wins * 3 + $draws) / ($effectiveMatches * 3) * 10, 1),
            ];
        } catch (Exception $e) {
            error_log("BayesianModel::getTeamHistory: " . $e->getMessage());
            return $default;
        }
    }

    private function getTeamHomeAdvantage($team, $league) {
        $teamId = $this->getTeamId($team);
        if (!$teamId) return null;
        $cacheKey = "ha_{$teamId}";
        if (isset($this->homeAdvantageCache[$cacheKey])) return $this->homeAdvantageCache[$cacheKey];
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN home_score > away_score THEN 1 ELSE 0 END) as home_wins,
                       SUM(CASE WHEN home_score = away_score THEN 1 ELSE 0 END) as draws,
                       SUM(CASE WHEN home_score < away_score THEN 1 ELSE 0 END) as losses
                FROM match_results
                WHERE home_team_id = ?
                  AND match_date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
                  AND home_score IS NOT NULL AND away_score IS NOT NULL
            ");
            $stmt->execute([$teamId]);
            $r = $stmt->fetch();
            if (!$r || $r['total'] < 5) return null;
            $total = (int)$r['total'];
            $result = [
                'home_win_rate' => $r['home_wins'] / $total,
                'draw_rate' => $r['draws'] / $total,
                'loss_rate' => $r['losses'] / $total,
                'total' => $total,
            ];
            $this->homeAdvantageCache[$cacheKey] = $result;
            return $result;
        } catch (Exception $e) { return null; }
    }

    private function getDefenseStats($team, $lookbackDays) {
        $teamId = $this->getTeamId($team);
        if (!$teamId) return null;
        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $stmt = $this->db->prepare("
                SELECT
                    SUM(CASE WHEN home_team_id = ? AND away_score = 0 THEN 1
                             WHEN away_team_id = ? AND home_score = 0 THEN 1 ELSE 0 END) as clean_sheets,
                    SUM(CASE WHEN home_team_id = ? THEN away_score ELSE home_score END) as goals_conceded,
                    COUNT(*) as total_matches,
                    AVG(CASE WHEN home_team_id = ? THEN away_score ELSE home_score END) as avg_conceded,
                    SUM(CASE WHEN match_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN
                        CASE WHEN home_team_id = ? THEN away_score ELSE home_score END ELSE NULL END) as recent_conceded,
                    SUM(CASE WHEN match_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE NULL END) as recent_matches
                FROM match_results
                WHERE (home_team_id = ? OR away_team_id = ?)
                  AND match_date >= ? AND match_date <= CURDATE()
                  AND home_score IS NOT NULL AND away_score IS NOT NULL
            ");
            $stmt->execute([$teamId, $teamId, $teamId, $teamId, $teamId, $teamId, $teamId, $lookback]);
            $r = $stmt->fetch();
            if (!$r || $r['total_matches'] < 3) return null;
            $total = (int)$r['total_matches'];
            $recentTotal = (int)($r['recent_matches'] ?? 0);
            return [
                'clean_sheet_pct' => $total > 0 ? $r['clean_sheets'] / $total * 100 : 0,
                'avg_conceded' => (float)$r['avg_conceded'],
                'recent_avg_conceded' => $recentTotal > 0 ? $r['recent_conceded'] / $recentTotal : (float)$r['avg_conceded'],
                'total_matches' => $total,
            ];
        } catch (Exception $e) { return null; }
    }

    private function credibleInterval($prob, $n, $z = 1.96) {
        $var = max(0.0001, $prob * (1 - $prob) / max(1, $n));
        $se = sqrt($var);
        return [
            'lower' => round(max(0.01, $prob - $z * $se) * 100, 1),
            'upper' => round(min(0.99, $prob + $z * $se) * 100, 1),
        ];
    }

    private function detectValue($pred, $bookmakerOdds) {
        if (empty($bookmakerOdds)) return null;
        $result = ['edges' => [], 'pick' => null, 'max_edge' => 0, 'is_value' => false];
        $modelProbs = [
            '1' => ($pred['probs']['1'] ?? 0) / 100,
            'X' => ($pred['probs']['X'] ?? 0) / 100,
            '2' => ($pred['probs']['2'] ?? 0) / 100,
        ];
        $keys = ['1', 'X', '2'];
        foreach ($keys as $k) {
            if (!isset($bookmakerOdds[$k]) || $bookmakerOdds[$k] <= 1) continue;
            $implied = 1 / $bookmakerOdds[$k];
            $edge = $modelProbs[$k] - $implied;
            $result['edges'][$k] = round($edge * 100, 2);
            if ($edge > $result['max_edge']) {
                $result['max_edge'] = round($edge * 100, 2);
                $result['pick'] = $k;
                $result['odds'] = $bookmakerOdds[$k];
                $result['model_prob'] = round($modelProbs[$k] * 100, 1);
                $result['implied_prob'] = round($implied * 100, 1);
            }
        }
        $dcPairs = ['1X' => ['1','X'], 'X2' => ['X','2'], '12' => ['1','2']];
        foreach ($dcPairs as $dcKey => $pair) {
            if (!isset($bookmakerOdds[$dcKey]) || $bookmakerOdds[$dcKey] <= 1) continue;
            $dcModelProb = $modelProbs[$pair[0]] + $modelProbs[$pair[1]];
            $implied = 1 / $bookmakerOdds[$dcKey];
            $edge = $dcModelProb - $implied;
            $result['edges'][$dcKey] = round($edge * 100, 2);
            if ($edge > $result['max_edge']) {
                $result['max_edge'] = round($edge * 100, 2);
                $result['pick'] = $dcKey;
                $result['odds'] = $bookmakerOdds[$dcKey];
                $result['model_prob'] = round($dcModelProb * 100, 1);
                $result['implied_prob'] = round($implied * 100, 1);
            }
        }

        // Over/Under markets
        $ouModel = $pred['over_under'] ?? null;
        if ($ouModel) {
            $ouMarkets = [
                'over_25' => ['over_25', $ouModel['over_25'] ?? null],
                'under_25' => ['under_25', $ouModel['under_25'] ?? null],
                'over_15' => ['over_15', $ouModel['over_15'] ?? null],
                'under_35' => ['under_35', $ouModel['under_35'] ?? null],
            ];
            foreach ($ouMarkets as $label => [$oddsKey, $modelProb]) {
                if ($modelProb === null) continue;
                if (!isset($bookmakerOdds[$oddsKey]) || $bookmakerOdds[$oddsKey] <= 1) continue;
                $implied = 1 / $bookmakerOdds[$oddsKey];
                $edge = ($modelProb / 100) - $implied;
                $result['edges'][$label] = round($edge * 100, 2);
                if ($edge > $result['max_edge']) {
                    $result['max_edge'] = round($edge * 100, 2);
                    $result['pick'] = $label;
                    $result['odds'] = $bookmakerOdds[$oddsKey];
                    $result['model_prob'] = round($modelProb, 1);
                    $result['implied_prob'] = round($implied * 100, 1);
                }
            }
        }

        // BTTS markets
        $bttsModel = $pred['btts'] ?? null;
        if ($bttsModel) {
            $bttsMarkets = [
                'btts_yes' => ['btts_yes', $bttsModel['yes'] ?? null],
                'btts_no' => ['btts_no', $bttsModel['no'] ?? null],
            ];
            foreach ($bttsMarkets as $label => [$oddsKey, $modelProb]) {
                if ($modelProb === null) continue;
                if (!isset($bookmakerOdds[$oddsKey]) || $bookmakerOdds[$oddsKey] <= 1) continue;
                $implied = 1 / $bookmakerOdds[$oddsKey];
                $edge = ($modelProb / 100) - $implied;
                $result['edges'][$label] = round($edge * 100, 2);
                if ($edge > $result['max_edge']) {
                    $result['max_edge'] = round($edge * 100, 2);
                    $result['pick'] = $label;
                    $result['odds'] = $bookmakerOdds[$oddsKey];
                    $result['model_prob'] = round($modelProb, 1);
                    $result['implied_prob'] = round($implied * 100, 1);
                }
            }
        }

        $result['is_value'] = $result['max_edge'] >= 5;
        return $result;
    }

    private function getHeadToHead($homeTeam, $awayTeam, $lookbackDays) {
        $default = ['matches' => 0, 'home_wins' => 0, 'draws' => 0, 'away_wins' => 0, 'avg_goals' => 0, 'btts_rate' => 0];
        if (!$this->db) return $default;
        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $homeId = $this->getTeamId($homeTeam);
            $awayId = $this->getTeamId($awayTeam);
            if ($homeId && $awayId) {
                $stmt = $this->db->prepare("
                    SELECT mr.home_team_id, mr.home_score, mr.away_score, mr.match_date
                    FROM match_results mr
                    WHERE ((mr.home_team_id = ? AND mr.away_team_id = ?) OR (mr.home_team_id = ? AND mr.away_team_id = ?))
                      AND mr.match_date >= ? AND mr.match_date <= CURDATE()
                      AND mr.home_score IS NOT NULL AND mr.away_score IS NOT NULL
                    ORDER BY mr.match_date DESC LIMIT 10
                ");
                $stmt->execute([$homeId, $awayId, $awayId, $homeId, $lookback]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT home_team, home_team_id, home_score, away_score, match_date
                    FROM match_results
                    WHERE ((home_team = ? AND away_team = ?) OR (home_team = ? AND away_team = ?))
                      AND match_date >= ? AND match_date <= CURDATE()
                      AND home_score IS NOT NULL AND away_score IS NOT NULL
                    ORDER BY match_date DESC LIMIT 10
                ");
                $stmt->execute([$homeTeam, $awayTeam, $awayTeam, $homeTeam, $lookback]);
            }
            $rows = $stmt->fetchAll();
            if (empty($rows)) return $default;

            $nowTs = time();
            $hw = 0; $d = 0; $aw = 0; $totalG = 0; $btts = 0; $totalWeight = 0;
            foreach ($rows as $r) {
                $daysAgo = ($nowTs - strtotime($r['match_date'])) / 86400;
                $weight = exp(-$daysAgo / $this->recencyHalfLife);
                $totalWeight += $weight;
                $h = (int)$r['home_score']; $a = (int)$r['away_score'];
                // Use team_id if available for reliable winner detection
                $isHomeActual = isset($r['home_team_id']) ? ((int)$r['home_team_id'] === $homeId) : ($r['home_team'] === $homeTeam);
                if ($isHomeActual) { if ($h > $a) $hw += $weight; elseif ($h === $a) $d += $weight; else $aw += $weight; }
                else { if ($a > $h) $hw += $weight; elseif ($a === $h) $d += $weight; else $aw += $weight; }
                $totalG += ($h + $a) * $weight;
                if ($h > 0 && $a > 0) $btts += $weight;
            }
            $n = max(1, $totalWeight);
            return [
                'matches' => round($totalWeight, 1), 'home_wins' => round($hw, 1), 'draws' => round($d, 1), 'away_wins' => round($aw, 1),
                'avg_goals' => round($totalG / $n, 2), 'btts_rate' => round($btts / $n * 100, 1),
            ];
        } catch (Exception $e) {
            error_log("BayesianModel::getHeadToHead: " . $e->getMessage());
            return $default;
        }
    }

    private function predictOverUnder($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays, $homeStats = null, $awayStats = null) {
        $avgGoals = $prior['avg_goals'];
        $homeGFperGame = $homeForm['matches'] > 0 ? $homeForm['gf'] / $homeForm['matches'] : $avgGoals / 2;
        $homeGAperGame = $homeForm['matches'] > 0 ? $homeForm['ga'] / $homeForm['matches'] : $avgGoals / 2;
        $awayGFperGame = $awayForm['matches'] > 0 ? $awayForm['gf'] / $awayForm['matches'] : $avgGoals / 2;
        $awayGAperGame = $awayForm['matches'] > 0 ? $awayForm['ga'] / $awayForm['matches'] : $avgGoals / 2;

        $homeExpected = ($homeGFperGame + $awayGAperGame) / 2;
        $awayExpected = ($awayGFperGame + $homeGAperGame) / 2;

        $statsUsed = false;
        if ($homeStats && $awayStats && $homeStats['xg_for'] !== null && $awayStats['xg_for'] !== null) {
            $statsBlend = min(0.6, ($homeStats['data_weight'] + $awayStats['data_weight']) / 2);
            $homeExpected = $homeExpected * (1 - $statsBlend) + $homeStats['xg_for'] * $statsBlend;
            $awayExpected = $awayExpected * (1 - $statsBlend) + $awayStats['xg_for'] * $statsBlend;
            $statsUsed = true;
        } elseif ($homeStats && $awayStats && $homeStats['sot_for'] > 0 && $awayStats['sot_for'] > 0) {
            // Shots on target as weak signal — blend conservatively
            $sotWeight = min(0.2, ($homeStats['data_weight'] + $awayStats['data_weight']) / 4);
            // SOT → expected goals approx: SOT * conversion_rate (~0.15)
            $homeExpected = $homeExpected * (1 - $sotWeight) + ($homeStats['sot_for'] * 0.15) * $sotWeight;
            $awayExpected = $awayExpected * (1 - $sotWeight) + ($awayStats['sot_for'] * 0.15) * $sotWeight;
        }

        if ($h2h['matches'] >= 2) {
            $h2hAvg = $h2h['avg_goals'];
            $homeExpected = ($homeExpected * 3 + $h2hAvg / 2) / 4;
            $awayExpected = ($awayExpected * 3 + $h2hAvg / 2) / 4;
        }

        $expectedTotal = $homeExpected + $awayExpected;
        $over15 = 1 - $this->poissonCdf($expectedTotal, 1);
        $over25 = 1 - $this->poissonCdf($expectedTotal, 2);
        $over35 = 1 - $this->poissonCdf($expectedTotal, 3);

        return [
            'expected_total_goals' => round($expectedTotal, 2),
            'home_expected' => round($homeExpected, 2),
            'away_expected' => round($awayExpected, 2),
            'stats_used' => $statsUsed,
            'over_15' => round($over15 * 100, 1),
            'over_25' => round($over25 * 100, 1),
            'over_35' => round($over35 * 100, 1),
            'under_15' => round((1 - $over15) * 100, 1),
            'under_25' => round((1 - $over25) * 100, 1),
            'under_35' => round((1 - $over35) * 100, 1),
        ];
    }

    private function predictBTTS($homeTeam, $awayTeam, $league, $prior, $homeForm, $awayForm, $h2h, $lookbackDays, $homeStats = null, $awayStats = null) {
        $bttsPrior = $prior['btts_rate'];
        $avgGoals = $prior['avg_goals'] ?? 2.5;

        // Use goals-for rate as proxy for scoring probability
        // P(at least 1 goal) ≈ 1 - e^(-goals_per_game) — Poisson P(0 goals)
        if ($homeForm['matches'] > 0) {
            $homeGFpg = $homeForm['gf'] / $homeForm['matches'];
            $homeScored = 1 - exp(-$homeGFpg);
            $homeGApg = $homeForm['ga'] / $homeForm['matches'];
            $homeConceded = 1 - exp(-$homeGApg);
        } else { $homeScored = $bttsPrior; $homeConceded = $bttsPrior; }

        if ($awayForm['matches'] > 0) {
            $awayGFpg = $awayForm['gf'] / $awayForm['matches'];
            $awayScored = 1 - exp(-$awayGFpg);
            $awayGApg = $awayForm['ga'] / $awayForm['matches'];
            $awayConceded = 1 - exp(-$awayGApg);
        } else { $awayScored = $bttsPrior; $awayConceded = $bttsPrior; }

        if ($homeStats && $awayStats && $homeStats['xg_for'] !== null && $awayStats['xg_for'] !== null) {
            $blend = min(0.4, ($homeStats['data_weight'] + $awayStats['data_weight']) / 2);
            $homeXgScored = 1 - exp(-$homeStats['xg_for']);
            $awayXgScored = 1 - exp(-$awayStats['xg_for']);
            $homeScored = $homeScored * (1 - $blend) + $homeXgScored * $blend;
            $awayScored = $awayScored * (1 - $blend) + $awayXgScored * $blend;
            if ($homeStats['xg_against'] !== null) {
                $homeXgConceded = 1 - exp(-$homeStats['xg_against']);
                $homeConceded = $homeConceded * (1 - $blend) + $homeXgConceded * $blend;
            }
            if ($awayStats['xg_against'] !== null) {
                $awayXgConceded = 1 - exp(-$awayStats['xg_against']);
                $awayConceded = $awayConceded * (1 - $blend) + $awayXgConceded * $blend;
            }
        }

        $k = $this->priorStrength;
        // BTTS = P(home scores) * P(away scores) OR P(away scores) * P(home scores) — both must happen
        $bttsFromForm = $homeScored * $awayScored;
        $bttsPost = ($bttsPrior * $k + $h2h['btts_rate'] / 100 * $h2h['matches'] + $bttsFromForm * 2) / ($k + $h2h['matches'] + 2);
        $bttsPost = min(0.95, max(0.05, $bttsPost));

        return ['yes' => round($bttsPost * 100, 1), 'no' => round((1 - $bttsPost) * 100, 1)];
    }

    private function poissonCdf($lambda, $k) {
        if ($lambda <= 0) return 1.0;
        // Use log-space computation for numerical stability
        $sum = 0;
        for ($i = 0; $i <= $k; $i++) {
            $logTerm = -$lambda + $i * log($lambda) - $this->logFactorial($i);
            $sum += exp($logTerm);
        }
        return min(1.0, max(0.0, $sum));
    }

    private function logFactorial($n) {
        if ($n <= 1) return 0;
        // Stirling's approximation for large n
        if ($n > 170) {
            return 0.5 * log(2 * M_PI * $n) + $n * log($n) - $n;
        }
        $r = 0;
        for ($i = 2; $i <= $n; $i++) $r += log($i);
        return $r;
    }

    private function getRecommendedPick($ph, $pd, $pa, $ou, $btts) {
        $picks = [];
        $bestOdds = max($ph, $pd, $pa);
        $bestLabel = $ph === $bestOdds ? '1' : ($pd === $bestOdds ? 'X' : '2');
        if ($bestOdds > 0.40) $picks[] = ['type' => $bestLabel, 'prob' => round($bestOdds * 100, 1)];

        foreach ([['1X', $ph + $pd], ['X2', $pd + $pa], ['12', $ph + $pa]] as $c) {
            if ($c[1] > 0.75) $picks[] = ['type' => $c[0], 'prob' => round($c[1] * 100, 1)];
        }
        if ($ou['over_25'] > 55) $picks[] = ['type' => 'Over 2.5', 'prob' => $ou['over_25']];
        elseif ($ou['under_25'] > 55) $picks[] = ['type' => 'Under 2.5', 'prob' => $ou['under_25']];
        if ($ou['over_15'] > 70) $picks[] = ['type' => 'Over 1.5', 'prob' => $ou['over_15']];
        elseif ($ou['under_35'] > 60) $picks[] = ['type' => 'Under 3.5', 'prob' => $ou['under_35']];
        if ($btts['yes'] > 58) $picks[] = ['type' => 'GG', 'prob' => $btts['yes']];
        elseif ($btts['no'] > 58) $picks[] = ['type' => 'NG', 'prob' => $btts['no']];

        return $picks;
    }

    public function tunePriorStrength($kMin = 5, $kMax = 100, $step = 5) {
        $bestK = $this->priorStrength;
        $bestErr = PHP_FLOAT_MAX;
        $results = [];
        if (!$this->db) return $bestK;

        $histStmt = $this->db->query("
            SELECT bp.prob_1, bp.prob_x, bp.prob_2, bp.over_25, bp.under_25, bp.btts_yes, bp.btts_no,
                   bp.home_team, bp.away_team, bp.league,
                   mr.home_score, mr.away_score,
                   CASE WHEN mr.home_team_id = bpHome.id THEN 0 ELSE 1 END as swapped
            FROM bayesian_predictions bp
            LEFT JOIN teams bpHome ON bpHome.name = bp.home_team
            LEFT JOIN teams bpAway ON bpAway.name = bp.away_team
            JOIN match_results mr ON (
                (bpHome.id IS NOT NULL AND bpAway.id IS NOT NULL AND (
                    (mr.home_team_id = bpHome.id AND mr.away_team_id = bpAway.id) OR
                    (mr.home_team_id = bpAway.id AND mr.away_team_id = bpHome.id)
                )) OR
                (bp.home_team = mr.home_team AND bp.away_team = mr.away_team) OR
                (bp.home_team = mr.away_team AND bp.away_team = mr.home_team)
            )
            WHERE bp.result = 'pending'
              AND mr.home_score IS NOT NULL
              AND bp.match_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
              AND bp.match_date <= CURDATE()
            LIMIT 500
        ");
        $testCases = $histStmt->fetchAll();
        if (count($testCases) < 20) return $this->priorStrength;

        for ($k = $kMin; $k <= $kMax; $k += $step) {
            $this->priorStrength = $k;
            $total = 0; $correct = 0;
            foreach ($testCases as $tc) {
                $total++;
                $pred = $this->predict($tc['home_team'], $tc['away_team'], $tc['league'], 365);
                $pick = $pred['recommended_pick'][0]['type'] ?? null;
                if (!$pick) continue;
                $hs = (int)$tc['home_score'];
                $as = (int)$tc['away_score'];
                $swapped = (int)($tc['swapped'] ?? 0);
                if ($swapped) {
                    $effHs = $as; $effAs = $hs;
                } else {
                    $effHs = $hs; $effAs = $as;
                }
                $correctPick = false;
                switch ($pick) {
                    case '1': $correctPick = $effHs > $effAs; break;
                    case 'X': $correctPick = $effHs === $effAs; break;
                    case '2': $correctPick = $effHs < $effAs; break;
                    case '1X': $correctPick = $effHs >= $effAs; break;
                    case 'X2': $correctPick = $effHs <= $effAs; break;
                    case '12': $correctPick = $effHs !== $effAs; break;
                    case 'Over 2.5': $correctPick = ($hs + $as) > 2.5; break;
                    case 'Under 2.5': $correctPick = ($hs + $as) < 2.5; break;
                    case 'Over 1.5': $correctPick = ($hs + $as) > 1.5; break;
                    case 'Under 3.5': $correctPick = ($hs + $as) < 3.5; break;
                    case 'GG': $correctPick = $hs > 0 && $as > 0; break;
                    case 'NG': $correctPick = $hs === 0 || $as === 0; break;
                }
                if ($correctPick) $correct++;
            }
            $err = $total > 0 ? 1 - ($correct / $total) : 1;
            $results[$k] = ['err' => round($err, 4), 'correct' => $correct, 'total' => $total];
            if ($err < $bestErr) { $bestErr = $err; $bestK = $k; }
        }
        $this->priorStrength = $bestK;
        return ['best_k' => $bestK, 'best_err' => round($bestErr, 4), 'sweep' => $results];
    }

    public function setPriorStrength($k) {
        $this->priorStrength = max(1, min(500, (int)$k));
    }

    public function getPriorStrength() {
        return $this->priorStrength;
    }

    public function setRecencyHalfLife($days) {
        $this->recencyHalfLife = max(14, min(730, (int)$days));
    }

    public function getRecencyHalfLife() {
        return $this->recencyHalfLife;
    }

    public function getAccuracyTrend($days = 30) {
        if (!$this->db) return [];
        try {
            $start = date('Y-m-d', strtotime("-{$days} days"));
            $stmt = $this->db->prepare("
                SELECT
                    DATE(match_date) as day,
                    COUNT(*) as total,
                    SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) as correct,
                    ROUND(SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as accuracy
                FROM bayesian_predictions
                WHERE result IN ('correct','incorrect')
                  AND match_date >= ?
                GROUP BY DATE(match_date)
                ORDER BY day ASC
            ");
            $stmt->execute([$start]);
            return $stmt->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function getDataQualitySummary($league = null) {
        if (!$this->db) return [];
        try {
            $where = $league ? "WHERE league = ?" : "";
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_matches, COUNT(DISTINCT league) as leagues,
                       COUNT(DISTINCT home_team) as teams,
                       MIN(match_date) as earliest, MAX(match_date) as latest
                FROM match_results $where
            ");
            $stmt->execute($league ? [$league] : []);
            return $stmt->fetch();
        } catch (Exception $e) { return []; }
    }

    public function getAvailableLeagues() {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT league, COUNT(*) as matches,
                       MIN(match_date) as from_date, MAX(match_date) as to_date
                FROM match_results GROUP BY league ORDER BY matches DESC
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function getRecentPredictions($limit = 20) {
        if (!$this->db) return [];
        try {
            return $this->db->query("
                SELECT bp.*,
                       CASE WHEN bp.result = 'correct' THEN 1 ELSE 0 END as is_correct
                FROM bayesian_predictions bp
                ORDER BY bp.id DESC LIMIT $limit
            ")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    public function backtest($days = 90, $minMatches = 50, $k = null) {
        if (!$this->db) return null;
        $originalK = $this->priorStrength;
        if ($k !== null) $this->priorStrength = max(1, min(500, (int)$k));
        try {
            $start = date('Y-m-d', strtotime("-{$days} days"));
            // Step 1: Get all pending predictions with resolved team IDs
            $bpStmt = $this->db->prepare("
                SELECT id, home_team, away_team, league, match_date,
                       prob_1, prob_x, prob_2, over_25, under_25,
                       btts_yes, btts_no, recommended_pick
                FROM bayesian_predictions
                WHERE match_date >= ? AND match_date <= CURDATE()
                ORDER BY match_date DESC
                LIMIT 2000
            ");
            $bpStmt->execute([$start]);
            $predictions = $bpStmt->fetchAll();
            if (count($predictions) < $minMatches) {
                if ($k !== null) $this->priorStrength = $originalK;
                return ['error' => 'Not enough data', 'found' => count($predictions), 'min_required' => $minMatches];
            }

            // Step 2: Batch-resolve all team IDs
            $allTeams = [];
            foreach ($predictions as $p) {
                $allTeams[$p['home_team']] = true;
                $allTeams[$p['away_team']] = true;
            }
            $teamIdMap = [];
            foreach (array_keys($allTeams) as $teamName) {
                $teamIdMap[$teamName] = $this->getTeamId($teamName);
            }

            // Step 3: Match predictions to results using team_id + date
            $cases = [];
            foreach ($predictions as $p) {
                $homeId = $teamIdMap[$p['home_team']] ?? null;
                $awayId = $teamIdMap[$p['away_team']] ?? null;
                $matchRow = null;
                $swapped = false;

                if ($homeId && $awayId) {
                    $q = $this->db->prepare("
                        SELECT home_team_id, home_score, away_score FROM match_results
                        WHERE ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?))
                          AND match_date = ? AND home_score IS NOT NULL AND away_score IS NOT NULL
                        LIMIT 1
                    ");
                    $q->execute([$homeId, $awayId, $awayId, $homeId, $p['match_date']]);
                    $matchRow = $q->fetch();
                    if ($matchRow && (int)$matchRow['home_team_id'] === $awayId) {
                        $swapped = true;
                    }
                }
                // Fallback: text match
                if (!$matchRow) {
                    $q = $this->db->prepare("
                        SELECT home_team, home_score, away_score FROM match_results
                        WHERE ((home_team = ? AND away_team = ?) OR (home_team = ? AND away_team = ?))
                          AND match_date = ? AND home_score IS NOT NULL AND away_score IS NOT NULL
                        LIMIT 1
                    ");
                    $q->execute([$p['home_team'], $p['away_team'], $p['away_team'], $p['home_team'], $p['match_date']]);
                    $matchRow = $q->fetch();
                    if ($matchRow && $matchRow['home_team'] === $p['away_team']) {
                        $swapped = true;
                    }
                }
                if (!$matchRow) continue;
                $cases[] = array_merge($p, [
                    'home_score' => $matchRow['home_score'],
                    'away_score' => $matchRow['away_score'],
                    'swapped' => $swapped ? 1 : 0,
                ]);
            }

            if (count($cases) < $minMatches) {
                if ($k !== null) $this->priorStrength = $originalK;
                return ['error' => 'Not enough matched data', 'found' => count($cases), 'min_required' => $minMatches];
            }

            $stats = [];
            $brier = ['1' => 0.0, 'X' => 0.0, '2' => 0.0, 'total' => 0];
            $mainCorrect = 0;
            $mainTotal = 0;
            foreach ($cases as $tc) {
                $hs = (int)$tc['home_score'];
                $as = (int)$tc['away_score'];
                $totalG = $hs + $as;
                $swapped = (int)$tc['swapped'];
                if ($swapped) {
                    $actualWinner = $as > $hs ? '1' : ($as === $hs ? 'X' : '2');
                } else {
                    $actualWinner = $hs > $as ? '1' : ($hs === $as ? 'X' : '2');
                }
                $bttsActual = ($hs > 0 && $as > 0);
                $picks = explode(', ', $tc['recommended_pick'] ?? '');
                foreach ($picks as $p) {
                    $parts = explode(':', $p);
                    if (count($parts) !== 2) continue;
                    $type = $parts[0];
                    $prob = (float)$parts[1] / 100;
                    if (!isset($stats[$type])) $stats[$type] = ['correct' => 0, 'total' => 0, 'sum_prob' => 0];
                    $stats[$type]['total']++;
                    $stats[$type]['sum_prob'] += $prob;
                    $correct = false;
                    $effHs = $swapped ? $as : $hs;
                    $effAs = $swapped ? $hs : $as;
                    switch ($type) {
                        case '1': $correct = $effHs > $effAs; break;
                        case 'X': $correct = $effHs === $effAs; break;
                        case '2': $correct = $effHs < $effAs; break;
                        case '1X': $correct = $effHs >= $effAs; break;
                        case 'X2': $correct = $effHs <= $effAs; break;
                        case '12': $correct = $effHs !== $effAs; break;
                        case 'Over 2.5': $correct = $totalG > 2.5; break;
                        case 'Under 2.5': $correct = $totalG < 2.5; break;
                        case 'Over 1.5': $correct = $totalG > 1.5; break;
                        case 'Under 3.5': $correct = $totalG < 3.5; break;
                        case 'GG': $correct = $bttsActual; break;
                        case 'NG': $correct = !$bttsActual; break;
                    }
                    if ($correct) $stats[$type]['correct']++;
                }
                $probs = [
                    '1' => (float)$tc['prob_1'] / 100,
                    'X' => (float)$tc['prob_x'] / 100,
                    '2' => (float)$tc['prob_2'] / 100,
                ];
                $bestPick = array_search(max($probs), $probs);
                if ($bestPick === $actualWinner) $mainCorrect++;
                $mainTotal++;
                foreach ($probs as $ok => $pv) {
                    $actual = $ok === $actualWinner ? 1.0 : 0.0;
                    $brier[$ok] += ($pv - $actual) ** 2;
                    $brier['total']++;
                }
            }
            $accuracy = [];
            foreach ($stats as $type => $s) {
                $accuracy[$type] = [
                    'correct' => $s['correct'],
                    'total' => $s['total'],
                    'accuracy' => $s['total'] > 0 ? round($s['correct'] / $s['total'] * 100, 1) : 0,
                    'avg_prob' => $s['total'] > 0 ? round($s['sum_prob'] / $s['total'] * 100, 1) : 0,
                    'calibration_gap' => $s['total'] > 0
                        ? round(abs(($s['sum_prob'] / $s['total']) - ($s['correct'] / $s['total'])) * 100, 1)
                        : 0,
                ];
            }
            $brierScore = $brier['total'] > 0
                ? round(($brier['1'] + $brier['X'] + $brier['2']) / ($brier['total'] / 3), 4)
                : null;
            if ($k !== null) $this->priorStrength = $originalK;
            return [
                'total_matches' => count($cases),
                'period_days' => $days,
                'prior_strength' => $this->priorStrength,
                'main_outcome_accuracy' => $mainTotal > 0 ? round($mainCorrect / $mainTotal * 100, 1) : 0,
                'main_outcome_correct' => $mainCorrect,
                'main_outcome_total' => $mainTotal,
                'brier_score' => $brierScore,
                'accuracy_by_type' => $accuracy,
            ];
        } catch (Exception $e) {
            if ($k !== null) $this->priorStrength = $originalK;
            error_log("BayesianModel::backtest: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    private function getTeamStatsProfile($teamName, $lookbackDays) {
        if (!$this->db) return null;
        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $teamId = $this->resolveTeamId($teamName);

            if ($teamId) {
                $stmt = $this->db->prepare("
                    SELECT
                        COUNT(*) as matches,
                        AVG(CASE WHEN home_team_id = ? THEN home_expected_goals ELSE away_expected_goals END) as avg_xg_for,
                        AVG(CASE WHEN home_team_id = ? THEN away_expected_goals ELSE home_expected_goals END) as avg_xg_against,
                        AVG(CASE WHEN home_team_id = ? THEN home_shots_on_goal ELSE away_shots_on_goal END) as avg_sot_for,
                        AVG(CASE WHEN home_team_id = ? THEN away_shots_on_goal ELSE home_shots_on_goal END) as avg_sot_against,
                        AVG(CASE WHEN home_team_id = ? THEN home_total_shots ELSE away_total_shots END) as avg_shots_for,
                        AVG(CASE WHEN home_team_id = ? THEN away_total_shots ELSE home_total_shots END) as avg_shots_against,
                        AVG(CASE WHEN home_team_id = ? THEN CAST(REPLACE(home_ball_possession,'%','') AS DECIMAL) ELSE CAST(REPLACE(away_ball_possession,'%','') AS DECIMAL) END) as avg_possession,
                        AVG(CASE WHEN home_team_id = ? THEN home_corner_kicks ELSE away_corner_kicks END) as avg_corners_for,
                        AVG(CASE WHEN home_team_id = ? THEN home_fouls ELSE away_fouls END) as avg_fouls,
                        AVG(CASE WHEN home_team_id = ? THEN home_yellow_cards + home_red_cards ELSE away_yellow_cards + away_red_cards END) as avg_cards,
                        SUM(CASE WHEN home_team_id = ? AND away_score = 0 THEN 1 WHEN away_team_id = ? AND home_score = 0 THEN 1 ELSE 0 END) as clean_sheets,
                        AVG(CASE WHEN home_team_id = ? THEN home_passes_accurate ELSE away_passes_accurate END) as avg_passes_accurate,
                        AVG(CASE WHEN home_team_id = ? THEN home_total_passes ELSE away_total_passes END) as avg_passes_total,
                        AVG(CASE WHEN home_team_id = ? THEN home_goals_prevented ELSE away_goals_prevented END) as avg_goals_prevented
                    FROM match_statistics
                    WHERE (home_team_id = ? OR away_team_id = ?)
                      AND match_date >= ? AND match_date <= CURDATE()
                ");
                $params = array_merge([$teamId], array_fill(0, 13, $teamId), [$lookback]);
                $stmt->execute($params);
            } else {
                $stmt = $this->db->prepare("
                    SELECT
                        COUNT(*) as matches,
                        AVG(CASE WHEN home_team_api = ? THEN home_expected_goals ELSE away_expected_goals END) as avg_xg_for,
                        AVG(CASE WHEN home_team_api = ? THEN away_expected_goals ELSE home_expected_goals END) as avg_xg_against,
                        AVG(CASE WHEN home_team_api = ? THEN home_shots_on_goal ELSE away_shots_on_goal END) as avg_sot_for,
                        AVG(CASE WHEN home_team_api = ? THEN away_shots_on_goal ELSE home_shots_on_goal END) as avg_sot_against,
                        AVG(CASE WHEN home_team_api = ? THEN home_total_shots ELSE away_total_shots END) as avg_shots_for,
                        AVG(CASE WHEN home_team_api = ? THEN away_total_shots ELSE home_total_shots END) as avg_shots_against,
                        AVG(CASE WHEN home_team_api = ? THEN CAST(REPLACE(home_ball_possession,'%','') AS DECIMAL) ELSE CAST(REPLACE(away_ball_possession,'%','') AS DECIMAL) END) as avg_possession,
                        AVG(CASE WHEN home_team_api = ? THEN home_corner_kicks ELSE away_corner_kicks END) as avg_corners_for,
                        AVG(CASE WHEN home_team_api = ? THEN home_fouls ELSE away_fouls END) as avg_fouls,
                        AVG(CASE WHEN home_team_api = ? THEN home_yellow_cards + home_red_cards ELSE away_yellow_cards + away_red_cards END) as avg_cards,
                        SUM(CASE WHEN home_team_api = ? AND away_score = 0 THEN 1 WHEN away_team_api = ? AND home_score = 0 THEN 1 ELSE 0 END) as clean_sheets,
                        AVG(CASE WHEN home_team_api = ? THEN home_passes_accurate ELSE away_passes_accurate END) as avg_passes_accurate,
                        AVG(CASE WHEN home_team_api = ? THEN home_total_passes ELSE away_total_passes END) as avg_passes_total,
                        AVG(CASE WHEN home_team_api = ? THEN home_goals_prevented ELSE away_goals_prevented END) as avg_goals_prevented
                    FROM match_statistics
                    WHERE (home_team_api = ? OR away_team_api = ?)
                      AND match_date >= ? AND match_date <= CURDATE()
                ");
                $params = array_merge([$teamName], array_fill(0, 13, $teamName), [$lookback]);
                $stmt->execute($params);
            }
            $r = $stmt->fetch();
            if (!$r || $r['matches'] < 2) return null;

            $total = (int)$r['matches'];
            $cs = (int)($r['clean_sheets'] ?? 0);
            $xgFor = $r['avg_xg_for'] !== null ? (float)$r['avg_xg_for'] : null;
            $xgAgainst = $r['avg_xg_against'] !== null ? (float)$r['avg_xg_against'] : null;
            $sotFor = (float)($r['avg_sot_for'] ?? 0);
            $sotAgainst = (float)($r['avg_sot_against'] ?? 0);
            $shotsFor = (float)($r['avg_shots_for'] ?? 0);
            $shotsAgainst = (float)($r['avg_shots_against'] ?? 0);
            $possession = $r['avg_possession'] !== null ? (float)$r['avg_possession'] : null;
            $cornersFor = (float)($r['avg_corners_for'] ?? 0);
            $fouls = (float)($r['avg_fouls'] ?? 0);
            $cards = (float)($r['avg_cards'] ?? 0);
            $passAcc = ($r['avg_passes_total'] ?? 0) > 0
                ? (float)$r['avg_passes_accurate'] / (float)$r['avg_passes_total'] * 100 : null;
            $goalsPrevented = $r['avg_goals_prevented'] !== null ? (float)$r['avg_goals_prevented'] : null;

            $offensiveStrength = 0;
            $offensiveSignals = 0;
            if ($xgFor !== null) { $offensiveStrength += $xgFor / 1.5; $offensiveSignals++; }
            if ($sotFor > 0) { $offensiveStrength += $sotFor / 6; $offensiveSignals++; }
            if ($shotsFor > 0) { $offensiveStrength += $shotsFor / 14; $offensiveSignals++; }
            $offensiveStrength = $offensiveSignals > 0 ? $offensiveStrength / $offensiveSignals : null;

            $defensiveStrength = 0;
            $defensiveSignals = 0;
            if ($xgAgainst !== null) { $defensiveStrength += max(0, 1 - $xgAgainst / 2); $defensiveSignals++; }
            if ($sotAgainst > 0) { $defensiveStrength += max(0, 1 - $sotAgainst / 6); $defensiveSignals++; }
            if ($shotsAgainst > 0) { $defensiveStrength += max(0, 1 - $shotsAgainst / 14); $defensiveSignals++; }
            $defensiveStrength = $defensiveSignals > 0 ? $defensiveStrength / $defensiveSignals : null;

            $controlStrength = null;
            if ($possession !== null && $passAcc !== null) {
                $controlStrength = ($possession / 100 * 0.5 + $passAcc / 100 * 0.5);
            } elseif ($possession !== null) {
                $controlStrength = $possession / 100;
            }

            return [
                'matches' => $total,
                'xg_for' => $xgFor,
                'xg_against' => $xgAgainst,
                'sot_for' => $sotFor,
                'sot_against' => $sotAgainst,
                'shots_for' => $shotsFor,
                'shots_against' => $shotsAgainst,
                'possession' => $possession,
                'corners_for' => $cornersFor,
                'fouls_per_game' => $fouls,
                'cards_per_game' => $cards,
                'clean_sheet_pct' => $total > 0 ? $cs / $total * 100 : 0,
                'pass_accuracy' => $passAcc,
                'goals_prevented' => $goalsPrevented,
                'offensive_strength' => $offensiveStrength,
                'defensive_strength' => $defensiveStrength,
                'control_strength' => $controlStrength,
                'data_weight' => min(1.0, $total / 15),
            ];
        } catch (Exception $e) {
            error_log("BayesianModel::getTeamStatsProfile: " . $e->getMessage());
            return null;
        }
    }

    private function getLeagueStatsBaseline($lookbackDays = 365) {
        if (!$this->db) return null;
        try {
            $lookback = date('Y-m-d', strtotime("-{$lookbackDays} days"));
            $r = $this->db->query("
                SELECT
                    AVG(home_expected_goals) as avg_xg_home,
                    AVG(away_expected_goals) as avg_xg_away,
                    AVG(home_shots_on_goal) as avg_sot_home,
                    AVG(away_shots_on_goal) as avg_sot_away,
                    AVG(home_total_shots) as avg_shots_home,
                    AVG(away_total_shots) as avg_shots_away,
                    AVG(CAST(REPLACE(home_ball_possession,'%','') AS DECIMAL)) as avg_poss_home,
                    COUNT(*) as total
                FROM match_statistics
                WHERE match_date >= '$lookback' AND match_date <= CURDATE()
                  AND home_expected_goals IS NOT NULL
            ");
            if (!$r) return null;
            $row = $r->fetch();
            if (!$row || $row['total'] < 10) return null;
            return [
                'avg_xg_home' => (float)$row['avg_xg_home'],
                'avg_xg_away' => (float)$row['avg_xg_away'],
                'avg_sot_home' => (float)$row['avg_sot_home'],
                'avg_sot_away' => (float)$row['avg_sot_away'],
                'avg_shots_home' => (float)$row['avg_shots_home'],
                'avg_shots_away' => (float)$row['avg_shots_away'],
                'avg_poss_home' => (float)$row['avg_poss_home'],
                'total' => (int)$row['total'],
            ];
        } catch (Exception $e) {
            return null;
        }
    }
}
