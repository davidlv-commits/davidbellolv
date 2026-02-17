#!/usr/bin/env python3
"""Sync dashboard metrics.json from GA4 Data API.

Required env vars:
- GA4_PROPERTY_ID
- GCP_SA_KEY_JSON  (raw JSON for a service account with GA4 read permissions)

Optional env vars:
- GA4_LOOKBACK_DAYS (default: 30)
"""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import DateRange, Dimension, Metric, RunReportRequest
from google.oauth2 import service_account

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "backoffice" / "metrics.json"


def _require_env(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Missing required env var: {name}")
    return value


def _get_client() -> tuple[BetaAnalyticsDataClient, str, int]:
    property_id = _require_env("GA4_PROPERTY_ID")
    sa_json_raw = _require_env("GCP_SA_KEY_JSON")
    lookback = int(os.getenv("GA4_LOOKBACK_DAYS", "30"))

    info = json.loads(sa_json_raw)
    creds = service_account.Credentials.from_service_account_info(
        info, scopes=["https://www.googleapis.com/auth/analytics.readonly"]
    )
    return BetaAnalyticsDataClient(credentials=creds), property_id, lookback


def _run_report(
    client: BetaAnalyticsDataClient,
    property_id: str,
    *,
    dimensions: List[str],
    metrics: List[str],
    days: int,
    order_metric_desc: str | None = None,
    limit: int | None = None,
    dimension_filter: Any | None = None,
) -> Any:
    req = RunReportRequest(
        property=f"properties/{property_id}",
        dimensions=[Dimension(name=d) for d in dimensions],
        metrics=[Metric(name=m) for m in metrics],
        date_ranges=[DateRange(start_date=f"{days}daysAgo", end_date="today")],
        limit=limit or 100,
        dimension_filter=dimension_filter,
    )

    if order_metric_desc:
        req.order_bys = [
            {
                "metric": {"metric_name": order_metric_desc},
                "desc": True,
            }
        ]

    return client.run_report(req)


def _first_int(report: Any, metric_idx: int = 0) -> int:
    if not report.rows:
        return 0
    try:
        return int(float(report.rows[0].metric_values[metric_idx].value))
    except Exception:
        return 0


def _first_float(report: Any, metric_idx: int = 0) -> float:
    if not report.rows:
        return 0.0
    try:
        return float(report.rows[0].metric_values[metric_idx].value)
    except Exception:
        return 0.0


def _rows_to_named_pairs(report: Any, name_key: str, value_metric_idx: int = 0) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    for row in report.rows:
        out.append(
            {
                name_key: row.dimension_values[0].value,
                "count": int(float(row.metric_values[value_metric_idx].value)),
            }
        )
    return out


def _daily_series(report: Any) -> Dict[str, List[Any]]:
    labels: List[str] = []
    sessions: List[int] = []
    users: List[int] = []

    for row in report.rows:
        raw = row.dimension_values[0].value  # YYYYMMDD
        label = f"{raw[6:8]}/{raw[4:6]}"
        labels.append(label)
        sessions.append(int(float(row.metric_values[0].value)))
        users.append(int(float(row.metric_values[1].value)))

    return {"labels": labels, "sessions": sessions, "users": users}


def build_metrics() -> Dict[str, Any]:
    client, property_id, lookback = _get_client()

    summary = _run_report(
        client,
        property_id,
        dimensions=["date"],
        metrics=["totalUsers", "sessions", "engagementRate", "screenPageViews"],
        days=lookback,
        limit=1,
    )

    # aggregate summary using total metric report (no dimensions)
    totals = client.run_report(
        RunReportRequest(
            property=f"properties/{property_id}",
            metrics=[
                Metric(name="totalUsers"),
                Metric(name="sessions"),
                Metric(name="engagementRate"),
                Metric(name="screenPageViews"),
            ],
            date_ranges=[DateRange(start_date=f"{lookback}daysAgo", end_date="today")],
        )
    )

    total_users = _first_int(totals, 0)
    total_sessions = _first_int(totals, 1)
    engagement_rate = _first_float(totals, 2)
    total_views = _first_int(totals, 3)

    daily = _run_report(
        client,
        property_id,
        dimensions=["date"],
        metrics=["sessions", "totalUsers"],
        days=lookback,
        order_metric_desc=None,
        limit=lookback + 2,
    )

    pages = _run_report(
        client,
        property_id,
        dimensions=["pagePath"],
        metrics=["screenPageViews"],
        days=lookback,
        order_metric_desc="screenPageViews",
        limit=10,
    )

    countries = _run_report(
        client,
        property_id,
        dimensions=["country"],
        metrics=["totalUsers"],
        days=lookback,
        order_metric_desc="totalUsers",
        limit=8,
    )

    devices = _run_report(
        client,
        property_id,
        dimensions=["deviceCategory"],
        metrics=["totalUsers"],
        days=lookback,
        order_metric_desc="totalUsers",
        limit=5,
    )

    channels = _run_report(
        client,
        property_id,
        dimensions=["sessionDefaultChannelGroup"],
        metrics=["sessions"],
        days=lookback,
        order_metric_desc="sessions",
        limit=8,
    )

    key_events = client.run_report(
        RunReportRequest(
            property=f"properties/{property_id}",
            dimensions=[Dimension(name="eventName")],
            metrics=[Metric(name="eventCount")],
            date_ranges=[DateRange(start_date=f"{lookback}daysAgo", end_date="today")],
            dimension_filter={
                "filter": {
                    "field_name": "eventName",
                    "in_list_filter": {
                        "values": [
                            "cta_click",
                            "video_play",
                            "video_complete",
                            "scroll_depth",
                            "engaged_time",
                        ]
                    },
                }
            },
            order_bys=[{"metric": {"metric_name": "eventCount"}, "desc": True}],
            limit=20,
        )
    )

    cta_breakdown: Dict[str, int] = {
        "Spotify": 0,
        "Apple Music": 0,
        "Novela": 0,
        "Instagram": 0,
        "Regalo": 0,
        "Música": 0,
    }

    # Optional: if custom dimension customEvent:cta_name is available in GA4 property.
    try:
        cta_report = _run_report(
            client,
            property_id,
            dimensions=["customEvent:cta_name"],
            metrics=["eventCount"],
            days=lookback,
            order_metric_desc="eventCount",
            limit=20,
            dimension_filter={
                "filter": {
                    "field_name": "eventName",
                    "string_filter": {"value": "cta_click", "match_type": "EXACT"},
                }
            },
        )
        for row in cta_report.rows:
            key = row.dimension_values[0].value
            val = int(float(row.metric_values[0].value))
            mapped = {
                "release_spotify_click": "Spotify",
                "release_apple_click": "Apple Music",
                "quick_book_click": "Novela",
                "quick_instagram_click": "Instagram",
                "quick_gift_click": "Regalo",
                "quick_music_click": "Música",
            }.get(key)
            if mapped:
                cta_breakdown[mapped] = val
    except Exception:
        # Graceful fallback when custom dimension is not registered yet.
        pass

    events = [
        {"name": row.dimension_values[0].value, "count": int(float(row.metric_values[0].value))}
        for row in key_events.rows
    ]

    top_pages = [
        {"path": row.dimension_values[0].value, "views": int(float(row.metric_values[0].value))}
        for row in pages.rows
    ]

    return {
        "last_updated": datetime.now(timezone.utc).isoformat(),
        "window_days": lookback,
        "kpis": {
            "users_7d": total_users,
            "sessions_7d": total_sessions,
            "engagement_rate": engagement_rate,
            "cta_clicks_7d": sum(cta_breakdown.values()),
            "pageviews_7d": total_views,
        },
        "series": _daily_series(daily),
        "cta_breakdown": cta_breakdown,
        "top_pages": top_pages,
        "events": events,
        "countries": _rows_to_named_pairs(countries, "country"),
        "devices": _rows_to_named_pairs(devices, "device"),
        "channels": _rows_to_named_pairs(channels, "channel"),
        "source": {
            "property_id": property_id,
            "ga4_measurement_id": "G-4V3KPXTJPN",
            "clarity_project_id": "vif42io02i",
        },
    }


def main() -> None:
    payload = build_metrics()
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Updated {OUTPUT}")


if __name__ == "__main__":
    main()
