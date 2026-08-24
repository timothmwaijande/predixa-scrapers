"""
ML Training Pipeline — runs on GitHub Actions runner.

1. Fetches training data + upcoming matches from predixa.co.tz API
2. Builds feature matrix with rolling form, H2H, standings, Bayesian priors
3. Trains XGBoost classifier (multiclass: 1/X/2)
4. Generates predictions for upcoming matches
5. POSTs predictions back to predixa.co.tz
"""
import json
import os
import sys
from datetime import datetime, timedelta

import numpy as np
import pandas as pd
import requests
from xgboost import XGBClassifier


API_BASE = os.environ.get("API_BASE", "https://predixa.co.tz")
API_KEY = os.environ.get("API_KEY", "pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580")
LOOKBACK_DAYS = int(os.environ.get("LOOKBACK_DAYS", "730"))
MIN_SAMPLES = 100


def fetch(endpoint):
    url = f"{API_BASE}/{endpoint}?key={API_KEY}"
    resp = requests.get(url, timeout=120, headers={"User-Agent": "PREDIXA-ML-Bot"})
    resp.raise_for_status()
    return resp.json()


def post(endpoint, data):
    url = f"{API_BASE}/{endpoint}?key={API_KEY}"
    resp = requests.post(
        url,
        json=data,
        timeout=60,
        headers={"User-Agent": "PREDIXA-ML-Bot", "Content-Type": "application/json"},
    )
    resp.raise_for_status()
    return resp.json()


def compute_team_form(team, target_date, results_df, n=5):
    """Compute rolling form stats for a team before a given date."""
    before = results_df[
        (results_df["match_date"] < target_date)
        & (
            (results_df["home_team"] == team)
            | (results_df["away_team"] == team)
        )
    ].tail(n)
    if len(before) == 0:
        return {"gf_avg": 1.2, "ga_avg": 1.2, "ppg": 1.0, "matches": 0}

    gf, ga, pts = [], [], []
    for _, r in before.iterrows():
        if r["home_team"] == team:
            gf.append(r["home_score"])
            ga.append(r["away_score"])
            pts.append(3 if r["home_score"] > r["away_score"] else (1 if r["home_score"] == r["away_score"] else 0))
        else:
            gf.append(r["away_score"])
            ga.append(r["home_score"])
            pts.append(3 if r["away_score"] > r["home_score"] else (1 if r["away_score"] == r["home_score"] else 0))
    return {
        "gf_avg": round(np.mean(gf), 2),
        "ga_avg": round(np.mean(ga), 2),
        "ppg": round(np.mean(pts), 2),
        "matches": len(before),
    }


def compute_h2h(home, away, target_date, results_df):
    """Compute H2H stats between home and away before given date."""
    mask = (
        (results_df["match_date"] < target_date)
        & (
            ((results_df["home_team"] == home) & (results_df["away_team"] == away))
            | ((results_df["home_team"] == away) & (results_df["away_team"] == home))
        )
    )
    h2h = results_df[mask]
    if len(h2h) == 0:
        return {"hw": 0, "d": 0, "aw": 0, "n": 0, "avg_goals": 2.5}

    hw = d = aw = 0
    for _, r in h2h.iterrows():
        if r["home_team"] == home:
            if r["home_score"] > r["away_score"]:
                hw += 1
            elif r["home_score"] == r["away_score"]:
                d += 1
            else:
                aw += 1
        else:
            if r["away_score"] > r["home_score"]:
                hw += 1
            elif r["away_score"] == r["home_score"]:
                d += 1
            else:
                aw += 1
    return {
        "hw": hw, "d": d, "aw": aw, "n": len(h2h),
        "avg_goals": round(h2h["home_score"].sum() + h2h["away_score"].sum(), 2),
    }


def get_standings_map(standings_list):
    """Build {(team, league): {position, points}} lookup."""
    smap = {}
    for s in standings_list:
        key = (s["team"].lower().strip(), s["league"].lower().strip())
        smap[key] = {"pos": int(s.get("position", 99)), "pts": int(s.get("points", 0)), "gd": int(s.get("goal_diff", 0))}
    return smap


def get_priors_map(priors_list):
    pmap = {}
    for p in priors_list:
        pmap[p["league"]] = {
            "hw_pct": float(p.get("home_win_pct", 45)),
            "d_pct": float(p.get("draw_pct", 25)),
            "avg_goals": float(p.get("avg_goals", 2.5)),
            "btts_pct": float(p.get("btts_pct", 50)),
        }
    return pmap


def normalize_team(name):
    n = name.lower().strip()
    n = "".join(c for c in n if c.isalnum() or c in " -'")
    subs = {
        "man utd": "manchester united", "man united": "manchester united",
        "man city": "manchester city", "manchester cty": "manchester city",
        "tot": "tottenham", "spurs": "tottenham",
        "ars": "arsenal", "che": "chelsea", "liv": "liverpool",
        "new": "newcastle", "inter": "inter milan", "milan": "ac milan",
        "juve": "juventus", "nap": "napoli", "barca": "barcelona",
        "realmadrid": "real madrid", "psg": "paris saint germain",
        "bayern": "bayern munich", "bvb": "borussia dortmund",
        "fc ": "", "cf ": "", "ac ": "", "sc ": "", "rc ": "", "ss ": "",
        "cd ": "", "as ": "", "sk ": "", "fk ": "", "nk ": "", "ud ": "",
        "ca ": "", "cr ": "", "ec ": "", "aa ": "", "ae ": "", "ssc ": "", "if ": "", "bk ": "",
    }
    for a, b in subs.items():
        n = n.replace(a, b)
    return " ".join(n.split())


def build_feature_matrix(matches, results_df, standings_map, priors_map):
    features = []
    for m in matches:
        home = m["home_team"]
        away = m["away_team"]
        league = m.get("league", "") or ""
        mdate = pd.Timestamp(m["match_date"]) if isinstance(m["match_date"], str) else m["match_date"]

        hf = compute_team_form(home, mdate, results_df)
        af = compute_team_form(away, mdate, results_df)
        h2h = compute_h2h(home, away, mdate, results_df)

        h_norm = normalize_team(home)
        a_norm = normalize_team(away)
        l_norm = league.lower().strip()

        # Standings
        sk_h = standings_map.get((h_norm, l_norm)) or standings_map.get((h_norm, "")) or {"pos": 99, "pts": 0, "gd": 0}
        sk_a = standings_map.get((a_norm, l_norm)) or standings_map.get((a_norm, "")) or {"pos": 99, "pts": 0, "gd": 0}

        # Priors
        lp = priors_map.get(league) or priors_map.get("__global__") or {"hw_pct": 45, "d_pct": 25, "avg_goals": 2.5, "btts_pct": 50}

        bayes_1 = float(m.get("bayes_prob_1") or 0) / 100 if m.get("bayes_prob_1") else None
        bayes_x = float(m.get("bayes_prob_x") or 0) / 100 if m.get("bayes_prob_x") else None
        bayes_2 = float(m.get("bayes_prob_2") or 0) / 100 if m.get("bayes_prob_2") else None

        row = {
            "home_gf_avg": hf["gf_avg"],
            "home_ga_avg": hf["ga_avg"],
            "home_ppg": hf["ppg"],
            "home_form_matches": hf["matches"],
            "away_gf_avg": af["gf_avg"],
            "away_ga_avg": af["ga_avg"],
            "away_ppg": af["ppg"],
            "away_form_matches": af["matches"],
            "h2h_n": h2h["n"],
            "h2h_home_wins": h2h["hw"],
            "h2h_draws": h2h["d"],
            "h2h_away_wins": h2h["aw"],
            "h2h_avg_goals": h2h["avg_goals"],
            "home_pos": sk_h["pos"],
            "away_pos": sk_a["pos"],
            "pos_diff": sk_h["pos"] - sk_a["pos"],
            "home_pts": sk_h["pts"],
            "away_pts": sk_a["pts"],
            "league_hw_pct": lp["hw_pct"],
            "league_d_pct": lp["d_pct"],
            "league_avg_goals": lp["avg_goals"],
            "league_btts_pct": lp["btts_pct"],
            "bayes_prob_1": bayes_1,
            "bayes_prob_x": bayes_x,
            "bayes_prob_2": bayes_2,
        }
        if "home_score" in m and m["home_score"] is not None:
            hs, aws = int(m["home_score"]), int(m["away_score"])
            row["label"] = 0 if hs > aws else (1 if hs == aws else 2)

        features.append(row)
    return pd.DataFrame(features)


def train_model(X_train, y_train):
    model = XGBClassifier(
        objective="multi:softprob",
        num_class=3,
        n_estimators=300,
        max_depth=6,
        learning_rate=0.1,
        subsample=0.8,
        colsample_bytree=0.8,
        reg_alpha=0.1,
        reg_lambda=1.0,
        random_state=42,
        eval_metric="mlogloss",
        early_stopping_rounds=20,
        verbosity=0,
    )
    split = int(len(X_train) * 0.8)
    model.fit(
        X_train[:split], y_train[:split],
        eval_set=[(X_train[split:], y_train[split:])],
        verbose=False,
    )
    return model


def main():
    print("ML Training Pipeline — starting")

    # 1. Fetch data
    print("Fetching training data...")
    data = fetch(f"cron/ml_features.php?lookback={LOOKBACK_DAYS}")
    print(f"  Training samples: {data['stats']['training_count']}")
    print(f"  Upcoming matches: {data['stats']['upcoming_count']}")
    print(f"  Standings entries: {data['stats']['standings_count']}")
    print(f"  Leagues: {data['stats']['league_count']}")

    if data["stats"]["training_count"] < MIN_SAMPLES:
        print(f"  Too few samples ({data['stats']['training_count']} < {MIN_SAMPLES}), skipping")
        sys.exit(0)

    # Build Bayesian prediction lookup for upcoming matches
    bayes_map = {}
    for bp in data.get("bayesian_upcoming", []):
        h = normalize_team(bp.get("home_team", ""))
        a = normalize_team(bp.get("away_team", ""))
        bayes_map[h + "|" + a] = bp
        bayes_map[a + "|" + h] = bp  # both orientations

    # 2. Build feature matrix
    print("Building feature matrix...")
    results_df = pd.DataFrame(data["training"])
    results_df["match_date"] = pd.to_datetime(results_df["match_date"])
    results_df["home_team"] = results_df["home_team"].str.strip()
    results_df["away_team"] = results_df["away_team"].str.strip()
    results_df.sort_values("match_date", inplace=True)

    standings_map = get_standings_map(data["standings"])
    priors_map = get_priors_map(data["priors"])

    df = build_feature_matrix(data["training"], results_df, standings_map, priors_map)
    df = df.dropna(subset=["label"])

    print(f"  Feature rows: {len(df)}")
    if len(df) < MIN_SAMPLES:
        print(f"  Too few feature rows ({len(df)} < {MIN_SAMPLES}), skipping")
        sys.exit(0)

    # 3. Train
    feature_cols = [c for c in df.columns if c != "label"]
    df[feature_cols] = df[feature_cols].fillna(0)

    X = df[feature_cols].values
    y = df["label"].values

    print(f"  Class distribution: home={sum(y==0)}, draw={sum(y==1)}, away={sum(y==2)}")
    print("Training XGBoost...")
    model = train_model(X, y)

    # 4. Predict upcoming matches
    print(f"Predicting {data['stats']['upcoming_count']} upcoming matches...")
    upcoming_results = []
    for m in data["upcoming"]:
        parts = m["match_name"].split(" vs ", 1)
        if len(parts) != 2:
            continue
        home_raw = parts[0].strip()
        away_raw = parts[1].strip()
        # Look up Bayesian prediction for this match
        h_n = normalize_team(home_raw)
        a_n = normalize_team(away_raw)
        bp = bayes_map.get(h_n + "|" + a_n)
        mm = {
            "home_team": home_raw,
            "away_team": away_raw,
            "league": m.get("league", ""),
            "match_date": datetime.now().strftime("%Y-%m-%d"),
            "bayes_prob_1": bp.get("bayes_prob_1") if bp else None,
            "bayes_prob_x": bp.get("bayes_prob_x") if bp else None,
            "bayes_prob_2": bp.get("bayes_prob_2") if bp else None,
        }
        upcoming_results.append(mm)

    if upcoming_results:
        uf = build_feature_matrix(upcoming_results, results_df, standings_map, priors_map)
        uf[feature_cols] = uf[feature_cols].fillna(0)
        probs = model.predict_proba(uf[feature_cols].values)

        predictions = []
        for i, m in enumerate(upcoming_results):
            p1 = round(float(probs[i][0]) * 100, 1)
            px = round(float(probs[i][1]) * 100, 1)
            p2 = round(float(probs[i][2]) * 100, 1)
            conf = round((abs(p1 - 33.3) + abs(px - 33.3) + abs(p2 - 33.3)) / (2 * 66.7) * 100, 1)

            pred = {
                "match_name": m["home_team"] + " vs " + m["away_team"],
                "home_team": m["home_team"],
                "away_team": m["away_team"],
                "league": m.get("league", ""),
                "match_date": datetime.now().strftime("%Y-%m-%d"),
                "prob_1": p1,
                "prob_x": px,
                "prob_2": p2,
                "confidence": conf,
                "model_name": "xgboost",
                "model_version": datetime.now().strftime("%Y-%m-%d"),
            }
            predictions.append(pred)

        print(f"  Posting {len(predictions)} predictions...")
        result = post("cron/fetch_ml_predictions.php", {
            "predictions": predictions,
            "model_name": "xgboost",
            "model_version": datetime.now().strftime("%Y-%m-%d"),
        })
        print(f"  Result: {result}")
    else:
        print("  No upcoming matches to predict")

    # 5. Compute accuracy on held-out test set if available
    split = int(len(X) * 0.8)
    if split < len(X):
        test_probs = model.predict_proba(X[split:])
        test_preds = np.argmax(test_probs, axis=1)
        accuracy = np.mean(test_preds == y[split:])
        print(f"\n  Test accuracy: {accuracy:.1%} ({int(accuracy * len(y[split:]))}/{len(y[split:])})")

    print("Done.")


if __name__ == "__main__":
    main()
