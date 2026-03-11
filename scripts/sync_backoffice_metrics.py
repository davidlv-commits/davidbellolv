#!/usr/bin/env python3
"""Sync dashboard metrics.json from GA4 Data API and optional Clarity export API.

Required env vars:
- GA4_PROPERTY_ID
- GCP_SA_KEY_JSON  (raw JSON for a service account with GA4 read permissions)

Optional env vars:
- GA4_LOOKBACK_DAYS (default: 30)
- CLARITY_API_TOKEN
- CLARITY_PROJECT_ID (default: vif42io02i)
- CLARITY_LOOKBACK_DAYS (default: 3, allowed: 1..3)
"""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import DateRange, Dimension, Metric, RunReportRequest
from google.api_core.exceptions import GoogleAPICallError
from google.oauth2 import service_account
import requests

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "backoffice" / "metrics.json"
CLARITY_EXPORT_URL = "https://www.clarity.ms/export-data/api/v1/project-live-insights"


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _load_existing_payload() -> Dict[str, Any]:
    if not OUTPUT.exists():
        return {}


def _to_int(value: Any) -> int:
    try:
        return int(float(value))
    except Exception:
        return 0


def _to_float(value: Any) -> float:
    try:
        return float(value)
    except Exception:
        return 0.0
    try:
        return json.loads(OUTPUT.read_text(encoding="utf-8"))
    except Exception:
        return {}


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
    return _to_int(report.rows[0].metric_values[metric_idx].value)


def _first_float(report: Any, metric_idx: int = 0) -> float:
    if not report.rows:
        return 0.0
    return _to_float(report.rows[0].metric_values[metric_idx].value)


def _rows_to_named_pairs(report: Any, name_key: str, value_metric_idx: int = 0) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    for row in report.rows:
        out.append(
            {
                name_key: row.dimension_values[0].value,
                "count": _to_int(row.metric_values[value_metric_idx].value),
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
        sessions.append(_to_int(row.metric_values[0].value))
        users.append(_to_int(row.metric_values[1].value))

    return {"labels": labels, "sessions": sessions, "users": users}


def _clarity_base(project_id: str) -> Dict[str, Any]:
    return {
        "project_id": project_id,
        "window_days": 3,
        "overview": {
            "sessions": 0,
            "bot_sessions": 0,
            "users": 0,
            "pages_per_session": 0.0,
        },
        "top_pages": [],
        "top_devices": [],
        "top_browsers": [],
        "frustrations": [],
        "sync": {
            "status": "disabled",
            "message": "Clarity token not configured.",
            "last_attempted": _now_iso(),
        },
    }


def _clarity_metric_name(name: str) -> str:
    return (
        name.lower()
        .replace("/", " ")
        .replace("-", " ")
        .replace("_", " ")
        .replace("  ", " ")
        .strip()
    )


def _pick_row_count(row: Dict[str, Any], preferred_keys: List[str]) -> int:
    for key in preferred_keys:
        if key in row:
            return _to_int(row[key])
    for key, value in row.items():
        if isinstance(value, (int, float)):
            return _to_int(value)
        if isinstance(value, str) and value.replace(".", "", 1).isdigit():
            return _to_int(value)
    return 0


def _label_value(row: Dict[str, Any], keys: List[str]) -> str:
    for key in keys:
        value = row.get(key)
        if value:
            return str(value)
    return "-"


def _fetch_clarity_metrics() -> Dict[str, Any]:
    project_id = os.getenv("CLARITY_PROJECT_ID", "vif42io02i").strip() or "vif42io02i"
    token = os.getenv("CLARITY_API_TOKEN", "").strip()
    clarity = _clarity_base(project_id)

    if not token:
        return clarity

    lookback = max(1, min(3, _to_int(os.getenv("CLARITY_LOOKBACK_DAYS", "3")) or 3))
    clarity["window_days"] = lookback

    response = requests.get(
        CLARITY_EXPORT_URL,
        params={"numOfDays": str(lookback)},
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        timeout=30,
    )
    response.raise_for_status()
    payload = response.json()

    frustrations: List[Dict[str, Any]] = []
    for entry in payload if isinstance(payload, list) else []:
        metric_name = str(entry.get("metricName", "")).strip()
        metric_key = _clarity_metric_name(metric_name)
        information = entry.get("information") or []

        if metric_key == "traffic":
            total_sessions = sum(_to_int(row.get("totalSessionCount")) for row in information)
            total_bot_sessions = sum(_to_int(row.get("totalBotSessionCount")) for row in information)
            total_users = sum(_to_int(row.get("distantUserCount")) for row in information)
            pps_values = [_to_float(row.get("PagesPerSessionPercentage")) for row in information]
            clarity["overview"] = {
                "sessions": total_sessions,
                "bot_sessions": total_bot_sessions,
                "users": total_users,
                "pages_per_session": round(sum(pps_values) / len(pps_values), 2) if pps_values else 0.0,
            }
            continue

        if metric_key == "popular pages":
            clarity["top_pages"] = [
                {
                    "url": _label_value(row, ["URL", "PageTitle", "Page Title"]),
                    "count": _pick_row_count(row, ["totalSessionCount", "sessionCount", "PageViews"]),
                }
                for row in information[:10]
            ]
            continue

        if metric_key == "device":
            clarity["top_devices"] = [
                {
                    "device": _label_value(row, ["Device"]),
                    "count": _pick_row_count(row, ["totalSessionCount", "sessionCount"]),
                }
                for row in information[:8]
            ]
            continue

        if metric_key == "browser":
            clarity["top_browsers"] = [
                {
                    "browser": _label_value(row, ["Browser"]),
                    "count": _pick_row_count(row, ["totalSessionCount", "sessionCount"]),
                }
                for row in information[:8]
            ]
            continue

        if metric_key in {
            "dead click count",
            "rage click count",
            "quickback click",
            "script error count",
            "error click count",
            "excessive scroll",
        }:
            frustrations.append(
                {
                    "name": metric_name,
                    "count": sum(
                        _pick_row_count(
                            row,
                            [
                                "count",
                                "totalCount",
                                "DeadClickCount",
                                "RageClickCount",
                                "QuickbackClick",
                                "ScriptErrorCount",
                                "ErrorClickCount",
                                "ExcessiveScroll",
                            ],
                        )
                        for row in information
                    ),
                }
            )

    clarity["frustrations"] = frustrations
    clarity["sync"] = {
        "status": "ok",
        "message": "Clarity metrics updated successfully.",
        "last_attempted": _now_iso(),
    }
    return clarity


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
        {"path": row.dimension_values[0].value, "views": _to_int(row.metric_values[0].value)}
        for row in pages.rows
    ]

    clarity = _fetch_clarity_metrics()

    return {
        "last_updated": _now_iso(),
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
        "clarity": clarity,
        "sync": {
            "status": "ok",
            "message": "Metrics updated from GA4 successfully.",
            "last_attempted": _now_iso(),
        },
    }


def build_error_payload(exc: Exception) -> Dict[str, Any]:
    existing = _load_existing_payload()
    payload: Dict[str, Any] = existing if isinstance(existing, dict) else {}
    payload.setdefault("window_days", 30)
    payload.setdefault("kpis", {})
    payload.setdefault("series", {"labels": [], "sessions": [], "users": []})
    payload.setdefault("cta_breakdown", {})
    payload.setdefault("top_pages", [])
    payload.setdefault("events", [])
    payload.setdefault("countries", [])
    payload.setdefault("devices", [])
    payload.setdefault("channels", [])
    payload.setdefault("clarity", _clarity_base(os.getenv("CLARITY_PROJECT_ID", "vif42io02i").strip() or "vif42io02i"))
    payload.setdefault(
        "source",
        {
            "property_id": os.getenv("GA4_PROPERTY_ID", "").strip(),
            "ga4_measurement_id": "G-4V3KPXTJPN",
            "clarity_project_id": "vif42io02i",
        },
    )
    payload["sync"] = {
        "status": "error",
        "message": str(exc),
        "error_type": exc.__class__.__name__,
        "last_attempted": _now_iso(),
    }
    return payload


def main() -> None:
    try:
        payload = build_metrics()
    except (GoogleAPICallError, RuntimeError, ValueError, KeyError, json.JSONDecodeError) as exc:
        payload = build_error_payload(exc)
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Updated {OUTPUT}")


if __name__ == "__main__":
    main()
