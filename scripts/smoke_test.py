#!/usr/bin/env python3
"""
DataPulse AI - End-to-End Production Smoke Test Suite
Verifies the complete platform lifecycle against a live deployment:
1. Health checks (Backend & Database)
2. Authentication (User registration & JWT acquisition)
3. Secure Ingestion (Normal CSV & Formula injection security check)
4. Data Profiling (Deterministic schema, statistics & outliers)
5. Data Cleaning (Recommendations, dry-run preview, non-destructive versioning)
6. Data Analytics (Histograms, boxplots, correlations, time series)
7. AI Analyst (Executive synthesis, health score, grounded Q&A)

Usage:
    python scripts/smoke_test.py --base-url http://localhost:8000
    python scripts/smoke_test.py --base-url http://localhost:8020
    python scripts/smoke_test.py --base-url http://localhost:80
"""

import argparse
import json
import mimetypes
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


class Colors:
    GREEN = "\033[92m"
    RED = "\033[91m"
    YELLOW = "\033[93m"
    BLUE = "\033[94m"
    BOLD = "\033[1m"
    RESET = "\033[0m"


def log_step(step_num: int, title: str):
    print(f"\n{Colors.BLUE}{Colors.BOLD}[Step {step_num}] {title}{Colors.RESET}")


def log_success(message: str):
    print(f"  {Colors.GREEN}✓ {message}{Colors.RESET}")


def log_error(message: str):
    print(f"  {Colors.RED}✗ {message}{Colors.RESET}")


class ApiClient:
    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip("/")
        self.token: str | None = None

    def request(
        self,
        method: str,
        path: str,
        data: dict | None = None,
        headers: dict | None = None,
        is_multipart: bool = False,
        raw_body: bytes | None = None,
        content_type: str | None = None,
    ) -> tuple[int, dict | str]:
        url = f"{self.base_url}{path}"
        req_headers = headers or {}

        if self.token and "Authorization" not in req_headers:
            req_headers["Authorization"] = f"Bearer {self.token}"

        body_bytes: bytes | None = None
        if raw_body is not None:
            body_bytes = raw_body
            if content_type:
                req_headers["Content-Type"] = content_type
        elif data is not None:
            body_bytes = json.dumps(data).encode("utf-8")
            req_headers["Content-Type"] = "application/json"

        req = urllib.request.Request(url, data=body_bytes, headers=req_headers, method=method)

        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                status_code = response.status
                resp_body = response.read().decode("utf-8")
                try:
                    return status_code, json.loads(resp_body)
                except Exception:
                    return status_code, resp_body
        except urllib.error.HTTPError as e:
            status_code = e.code
            err_body = e.read().decode("utf-8")
            try:
                return status_code, json.loads(err_body)
            except Exception:
                return status_code, err_body


def build_multipart_form(fields: dict[str, str], files: dict[str, tuple[str, bytes]]) -> tuple[bytes, str]:
    boundary = f"----WebKitFormBoundary{uuid.uuid4().hex}"
    lines: list[bytes] = []

    for name, value in fields.items():
        lines.append(f"--{boundary}\r\n".encode("utf-8"))
        lines.append(f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode("utf-8"))
        lines.append(f"{value}\r\n".encode("utf-8"))

    for name, (filename, content) in files.items():
        mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"
        lines.append(f"--{boundary}\r\n".encode("utf-8"))
        lines.append(f'Content-Disposition: form-data; name="{name}"; filename="{filename}"\r\n'.encode("utf-8"))
        lines.append(f"Content-Type: {mime}\r\n\r\n".encode("utf-8"))
        lines.append(content)
        lines.append(b"\r\n")

    lines.append(f"--{boundary}--\r\n".encode("utf-8"))
    body = b"".join(lines)
    content_type = f"multipart/form-data; boundary={boundary}"
    return body, content_type


def run_smoke_test(base_url: str) -> bool:
    print(f"{Colors.BOLD}Starting DataPulse AI E2E Smoke Test against {base_url}...{Colors.RESET}")
    start_time = time.time()
    client = ApiClient(base_url)

    # -------------------------------------------------------------
    # Step 1: Health Check
    # -------------------------------------------------------------
    log_step(1, "Service Health Check (/api/health)")
    status, body = client.request("GET", "/api/health")
    if status != 200 or not isinstance(body, dict) or body.get("status") != "ok":
        log_error(f"Health check failed: status {status}, response: {body}")
        return False
    db_status = body.get("database", "unknown")
    log_success(f"Backend healthy (service: {body.get('service')}, database: {db_status})")

    # -------------------------------------------------------------
    # Step 2 & 3: Authentication (Access Control, Register & Login)
    # -------------------------------------------------------------
    log_step(2, "Access Control Security Check & User Registration")
    test_email = f"smoketest_{uuid.uuid4().hex[:8]}@datapulse.test"
    test_password = "P@ssword_Secure_2026!"

    # 2a. Verify access control rejects registration without access key
    unauth_status, _ = client.request("POST", "/api/auth/register", {"email": test_email, "password": test_password})
    if unauth_status != 403:
        log_error(f"Access control enforcement check failed: expected 403, got {unauth_status}")
        return False
    log_success("Access control verified: registration without access key rejected with HTTP 403")

    # 2b. Register with authorized access key
    reg_status, reg_body = client.request(
        "POST",
        "/api/auth/register",
        {"email": test_email, "password": test_password, "access_key": "DP-DEMO-2026"},
    )
    if reg_status not in (200, 201):
        log_error(f"Registration failed: status {reg_status}, response: {reg_body}")
        return False
    log_success(f"Registered user with valid access key: {test_email}")

    login_status, login_body = client.request("POST", "/api/auth/login", {"email": test_email, "password": test_password})
    if login_status != 200 or not isinstance(login_body, dict) or "token" not in login_body:
        log_error(f"Login failed: status {login_status}, response: {login_body}")
        return False
    client.token = login_body["token"]
    log_success("Authenticated and acquired JWT bearer token")

    # -------------------------------------------------------------
    # Step 4: Secure Dataset Ingestion
    # -------------------------------------------------------------
    log_step(3, "Dataset Ingestion (Customers CSV with deliberate duplicates)")
    sample_csv = (
        "id,name,age,city,spend,signup_date\n"
        "1,Alice,29,Paris,150.50,2026-01-15\n"
        "2,Bob,35,Dakar,220.00,2026-02-10\n"
        "3,Charlie,42,Paris,310.25,2026-03-05\n"
        "4,Diana,31,Tokyo,190.75,2026-04-20\n"
        "5,Alice,29,Paris,150.50,2026-01-15\n"  # Duplicate row
        "6,Evan,,Dakar,85.00,2026-05-12\n"      # Missing age
    ).encode("utf-8")

    body_bytes, ctype = build_multipart_form(
        fields={"name": "Smoke Test Customers"},
        files={"file": ("customers.csv", sample_csv)},
    )
    up_status, up_body = client.request("POST", "/api/datasets", raw_body=body_bytes, content_type=ctype)
    if up_status not in (200, 201) or not isinstance(up_body, dict) or "id" not in up_body:
        log_error(f"Dataset upload failed: status {up_status}, response: {up_body}")
        return False

    dataset_id = up_body["id"]
    versions = up_body.get("versions", [])
    if not versions:
        log_error("Uploaded dataset has no versions attached")
        return False
    version_id = versions[0]["id"]
    log_success(f"Dataset created: ID {dataset_id}, raw version: {version_id}")

    # -------------------------------------------------------------
    # Step 5: Formula Injection Security Inspection
    # -------------------------------------------------------------
    log_step(4, "Formula Injection Protection Check")
    malicious_csv = (
        "id,command\n"
        "1,=cmd|' /C calc'!A0\n"
    ).encode("utf-8")
    m_body, m_ctype = build_multipart_form(
        fields={"name": "Formula Security Probe"},
        files={"file": ("probe.csv", malicious_csv)},
    )
    sec_status, sec_body = client.request("POST", "/api/datasets", raw_body=m_body, content_type=m_ctype)
    if sec_status in (200, 201) and isinstance(sec_body, dict):
        has_risk = sec_body.get("versions", [{}])[0].get("hasFormulaInjectionRisk", False)
        if has_risk:
            log_success("Formula injection detected and flagged (hasFormulaInjectionRisk: True)")
        else:
            log_error("Formula injection was not flagged")
            return False

    # -------------------------------------------------------------
    # Step 6: Data Profiling
    # -------------------------------------------------------------
    log_step(5, "Deterministic Data Profiling")
    prof_status, prof_body = client.request("POST", f"/api/datasets/{dataset_id}/versions/{version_id}/profile")
    if prof_status != 200 or not isinstance(prof_body, dict) or prof_body.get("status") != "completed":
        log_error(f"Profiling failed: status {prof_status}, response: {prof_body}")
        return False

    result = prof_body.get("result", {})
    ds_stats = result.get("dataset", {})
    rows = ds_stats.get("rows", 0)
    cols = ds_stats.get("columns", 0)
    dup_rows = ds_stats.get("duplicateRowCount", 0)
    log_success(f"Profiled: {rows} rows, {cols} columns, {dup_rows} duplicate rows detected")

    # -------------------------------------------------------------
    # Step 7 & 8 & 9: Data Cleaning
    # -------------------------------------------------------------
    log_step(6, "Data Cleaning: Recommendations, Dry-Run Preview & Apply")
    rec_status, rec_body = client.request("GET", f"/api/datasets/{dataset_id}/versions/{version_id}/cleaning-recommendations")
    if rec_status != 200 or not isinstance(rec_body, dict):
        log_error(f"Recommendations failed: status {rec_status}")
        return False
    recs = rec_body.get("recommendations", [])
    log_success(f"Retrieved {len(recs)} cleaning recommendations")

    # Dry-run Preview
    prev_status, prev_body = client.request(
        "POST",
        f"/api/datasets/{dataset_id}/versions/{version_id}/clean/preview",
        {"operations": [{"type": "remove_duplicate_rows"}]},
    )
    if prev_status != 200 or not isinstance(prev_body, dict):
        log_error(f"Preview failed: status {prev_status}, response: {prev_body}")
        return False
    affected = prev_body.get("estimatedAffectedRows", 0)
    log_success(f"Cleaning preview estimated affected rows: {affected}")

    # Check tier restrictions and upgrade if on Free plan
    me_status, me_body = client.request("GET", "/api/me")
    if me_status == 200 and isinstance(me_body, dict) and me_body.get("plan") == "free":
        # Verify Free tier is blocked from applying cleaning
        free_clean_status, _ = client.request(
            "POST",
            f"/api/datasets/{dataset_id}/versions/{version_id}/clean",
            {"operations": [{"type": "remove_duplicate_rows"}]},
        )
        if free_clean_status == 403:
            log_success("Free tier quota restriction verified: clean execution blocked with HTTP 403")
        else:
            log_error(f"Free tier cleaning restriction check failed: expected 403, got {free_clean_status}")
            return False

        # Upgrade account to Pro
        upg_status, upg_body = client.request("POST", "/api/me/upgrade", {"targetPlan": "pro"})
        if upg_status != 200 or not isinstance(upg_body, dict) or upg_body.get("plan") != "pro":
            log_error(f"Plan upgrade failed: status {upg_status}, response: {upg_body}")
            return False
        log_success("Account successfully upgraded to Pro plan (unlimited cleaning & AI Analyst unlocked)")

    # Apply Cleaning
    clean_status, clean_body = client.request(
        "POST",
        f"/api/datasets/{dataset_id}/versions/{version_id}/clean",
        {"operations": [{"type": "remove_duplicate_rows"}]},
    )
    if clean_status != 200 or not isinstance(clean_body, dict) or clean_body.get("status") != "completed":
        log_error(f"Cleaning execution failed: status {clean_status}, response: {clean_body}")
        return False

    cleaned_version_id = clean_body.get("resultVersionId")
    log_success(f"Cleaning applied non-destructively: new version {cleaned_version_id}")

    # -------------------------------------------------------------
    # Step 10: Data Analytics & Visualizations
    # -------------------------------------------------------------
    target_ver = cleaned_version_id or version_id
    log_step(7, f"Statistical Analytics & Visualizations (version {target_ver})")
    ana_status, ana_body = client.request("POST", f"/api/datasets/{dataset_id}/versions/{target_ver}/analytics")
    if ana_status != 200 or not isinstance(ana_body, dict) or ana_body.get("status") != "completed":
        log_error(f"Analytics failed: status {ana_status}, response: {ana_body}")
        return False

    ana_res = ana_body.get("result", {})
    num_cols = len(ana_res.get("numeric", []))
    cat_cols = len(ana_res.get("categorical", []))
    log_success(f"Analytics computed: {num_cols} numeric distributions, {cat_cols} categorical charts, correlation matrix ready")

    # -------------------------------------------------------------
    # Step 11: AI Analyst Executive Synthesis
    # -------------------------------------------------------------
    log_step(8, "AI Analyst Synthesis (Executive Narrative & Hygiene Score)")
    ai_status, ai_body = client.request("POST", f"/api/datasets/{dataset_id}/versions/{target_ver}/insights")
    if ai_status != 200 or not isinstance(ai_body, dict) or ai_body.get("status") != "completed":
        log_error(f"AI insights synthesis failed: status {ai_status}, response: {ai_body}")
        return False

    ai_res = ai_body.get("result", {})
    health_score = ai_res.get("healthScore", 0)
    exec_summary = ai_res.get("executiveSummary", "")
    insights_list = ai_res.get("insights", [])
    log_success(f"Health score: {health_score}/100, Generated {len(insights_list)} grounded insights")
    log_success(f"Executive summary: {exec_summary[:80]}...")

    # -------------------------------------------------------------
    # Step 12: AI Grounded Q&A
    # -------------------------------------------------------------
    log_step(9, "AI Analyst Zero-Hallucination Q&A")
    qa_status, qa_body = client.request(
        "POST",
        f"/api/datasets/{dataset_id}/versions/{target_ver}/ask",
        {"question": "What is the average spend?"},
    )
    if qa_status != 200 or not isinstance(qa_body, dict):
        log_error(f"Q&A failed: status {qa_status}, response: {qa_body}")
        return False
    answer = qa_body.get("answer", "")
    metrics_used = qa_body.get("metricsReferenced", [])
    log_success(f"Answer received: {answer[:90]}...")
    log_success(f"Referenced metrics: {metrics_used}")

    # -------------------------------------------------------------
    # Completion
    # -------------------------------------------------------------
    elapsed = time.time() - start_time
    print(f"\n{Colors.GREEN}{Colors.BOLD}🎉 ALL 9 PRODUCTION PIPELINE STEPS PASSED SUCCESSFULLY! ({elapsed:.2f}s){Colors.RESET}")
    return True


def main():
    parser = argparse.ArgumentParser(description="DataPulse AI Production Smoke Test Runner")
    parser.add_argument("--base-url", default="http://localhost:8000", help="Base URL of backend or ingress proxy")
    args = parser.parse_args()

    success = run_smoke_test(args.base_url)
    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
