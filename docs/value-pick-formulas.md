# VALUE Pick Formulas

Three different VALUE detection formulas are used across the project. Each compares a probability estimate against market odds to determine if a pick offers "value" (i.e., the model thinks the true probability is higher than what the odds imply).

---

## 1. Best View (`best-picks-view.php`)

**Location:** `best-picks-view.php:171-172`

```php
$ev = $c['probability'] / (1 / $c['best_odds']);
$isValue = $hasOdds && $ev > 1;
```

**Simplified:** `EV = probability × odds`

- `probability` — Bayesian model's predicted probability stored as a **percentage** in `recommended_pick` (e.g., 65.4 means 65.4%).
- `best_odds` — decimal odds from Google Sheets (`Odds_Drops` tab).
- **VALUE when:** `probability × odds > 1` (i.e., the product exceeds 1.0)
- **Edge display:** `round(($ev - 1) * 100)` → e.g., "36% edge"
- **Badge shown:** `BOT TARGET`

**Example:** probability = 65.4%, odds = 2.10
→ EV = 65.4 × 2.10 = 137.34 → VALUE (137.34 > 1)

---

## 2. Banker of the Day (`dashboard.php`)

**Location:** `dashboard.php:198`

```php
$ev = $bc['probability'] / (1 / $bc['best_odds']);
$isValue = $hasOdds && $ev > 1;
```

**Identical formula to Best View.** Same data source (`recommended_pick`), same odds source (Google Sheets), same threshold (`> 1`).

The only difference is the UI rendering: Banker shows a green `VALUE` badge with checkmark icon, while Best View shows `BOT TARGET` badge and a "Value bet — X% edge" line.

---

## 3. PRO Tab (`dashboard.php`)

**Location:** `dashboard.php:12-29` (`getBankerEVValue()`) + `includes/value_calculator.php:3-41` (`stripMarginThreeWay()`, `calculateEV()`)

This is a fundamentally different approach. Instead of using the Bayesian model's probability, it derives **true probabilities from the odds themselves** (removing bookmaker overround), then checks if the odds offer edge.

### Step 1: Remove overround — `stripMarginThreeWay($homeOdds, $drawOdds, $awayOdds)`

```php
// value_calculator.php:3-36
$probs = [1 / $homeOdds, 1 / $drawOdds, 1 / $awayOdds];
$sumProbs = array_sum($probs);        // e.g., 1.05 (5% overround)
$overround = $sumProbs - 1;
// Shin's method iteratively solves for true probabilities
// that account for favorite-longshot bias
return ['home' => $true[0], 'draw' => $true[1], 'away' => $true[2]];
```

Takes the three 1X2 odds, converts to implied probabilities, removes the bookmaker's margin using Shin's method (200-iteration Newton solver), returns true probabilities summing to 1.0.

### Step 2: Calculate EV — `calculateEV($trueProb, $decimalOdds)`

```php
// value_calculator.php:38-41
return ($trueProb * $decimalOdds) - 1;
```

Returns a **decimal**: 0.05 means 5% edge.

### Step 3: Determine VALUE — `getBankerEVValue($pick)`

```php
// dashboard.php:12-29
$tp = stripMarginThreeWay($homeOdds, $drawOdds, $awayOdds);
if (str_contains($pv, '(1X)')) return calculateEV($tp['home'] + $tp['draw'], $odds);
if (str_contains($pv, '(12)')) return calculateEV($tp['home'] + $tp['away'], $odds);
if (str_contains($pv, '(X2)')) return calculateEV($tp['draw'] + $tp['away'], $odds);
// Single outcome: home, draw, or away
return calculateEV($tp[$k], $odds);
```

For double chance picks (1X, 12, X2), it sums the two relevant true probabilities before calculating EV.

- **VALUE when:** `$bankerEv >= 0.05` (5% edge or more)
- **Badge shown:** `BANKER +X%` (e.g., "BANKER +12%")

---

## Comparison Table

| Aspect | Best View & Banker | PRO Tab |
|---|---|---|
| **Probability source** | Bayesian model (`recommended_pick`) | Odds-implied true probability (Shin's method) |
| **Formula** | `probability × odds` | `(trueProb × odds) - 1` |
| **Threshold** | `> 1` (ratio) | `>= 0.05` (decimal) |
| **Output type** | Ratio (e.g., 1.37) | Decimal (e.g., 0.05) |
| **Badge** | `BOT TARGET` / `VALUE` | `BANKER +X%` |
| **Edge text** | "Value bet — 36% edge" | "BANKER +12%" |

### Why the difference?

- **Best View / Banker** uses the model's own prediction against market odds. If the model says 65% and odds imply only 47% (odds = 2.10), that's VALUE.
- **PRO** uses the market's own probabilities (after removing margin) against its odds. It's checking: "Do these odds offer edge based on what the market itself thinks?" This catches mispriced odds even without a model prediction.
