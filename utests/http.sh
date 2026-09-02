#!/bin/sh
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TEST_DIR=$(mktemp -d)
PORT=18091
SERVER_PID=""
cleanup() { [ -z "$SERVER_PID" ] || kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$TEST_DIR"; }
trap cleanup EXIT HUP INT TERM
export ATUM_STATE_DIR="$TEST_DIR/state"
export KAMAILIO_CONFIG="$ROOT/examples/kamailio.cfg"
mkdir -m 0700 "$ATUM_STATE_DIR"
php -r 'require $argv[1]."/admin/bootstrap.php"; $a=Atum::create(); $a->Modules->installBundled(true); $a->Auth->createUser("httpadmin","HTTP test password 123","admin"); $a->Auth->createUser("httpviewer","HTTP viewer password 123","viewer"); $a->Auth->createUser("httplimited","HTTP limited password 123","viewer");' "$ROOT"
php -S "127.0.0.1:$PORT" -t "$ROOT/public" >"$TEST_DIR/server.log" 2>&1 & SERVER_PID=$!
i=0; while ! curl -fsS -c "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/index.php" > "$TEST_DIR/login"; do i=$((i+1)); [ "$i" -lt 20 ] || exit 1; sleep 1; done
csrf=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TEST_DIR/login" | head -n1)
[ -n "$csrf" ]
before_session=$(awk '$6=="ATUMSESSID"{print $7}' "$TEST_DIR/cookies")
attempt=0; while [ "$attempt" -lt 5 ]; do curl -sS -b "$TEST_DIR/cookies" -d "login=1&csrf=$csrf&username=httpviewer&password=wrong" "http://127.0.0.1:$PORT/index.php" >/dev/null; attempt=$((attempt+1)); done
curl -sS -b "$TEST_DIR/cookies" -d "login=1&csrf=$csrf&username=httpviewer&password=HTTP%20viewer%20password%20123" "http://127.0.0.1:$PORT/index.php" | grep -q 'Invalid username or password'
curl -sS -c "$TEST_DIR/cookies" -b "$TEST_DIR/cookies" -d "login=1&csrf=$csrf&username=httpadmin&password=wrong" "http://127.0.0.1:$PORT/index.php" | grep -q 'Invalid username or password'
curl -sS -c "$TEST_DIR/cookies" -b "$TEST_DIR/cookies" -d "login=1&csrf=$csrf&username=httpadmin&password=HTTP%20test%20password%20123" -o /dev/null -D "$TEST_DIR/headers" "http://127.0.0.1:$PORT/index.php"
grep -qi '^Location: index.php' "$TEST_DIR/headers"
after_session=$(awk '$6=="ATUMSESSID"{print $7}' "$TEST_DIR/cookies")
[ "$before_session" != "$after_session" ]
curl -fsS -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/index.php" > "$TEST_DIR/page"
auth_csrf=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TEST_DIR/page" | head -n1)
[ -n "$auth_csrf" ]
curl -fsS -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/index.php?display=discovery" > "$TEST_DIR/discovery-page"
grep -q 'System interpretation' "$TEST_DIR/discovery-page"
grep -q 'Discovery confidence' "$TEST_DIR/discovery-page"
grep -q 'SIP over UDP listening on 0.0.0.0:5060' "$TEST_DIR/discovery-page"
grep -q 'Configuration composition' "$TEST_DIR/discovery-page"
grep -q 'Custom routing logic detected' "$TEST_DIR/discovery-page"
grep -q 'Detected module support' "$TEST_DIR/discovery-page"
grep -q 'Dispatching and load balancing' "$TEST_DIR/discovery-page"
grep -q 'Destination selection and load balancing' "$TEST_DIR/discovery-page"
grep -q 'discovered parameter.*provenance' "$TEST_DIR/discovery-page"
grep -q '&quot;&lt;redacted-dsn&gt;&quot;' "$TEST_DIR/discovery-page"
! grep -q 'supersecret' "$TEST_DIR/discovery-page"
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$TEST_DIR/cookies" -X POST "http://127.0.0.1:$PORT/ajax.php?module=userman&command=disable")" = 403 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/ajax.php?module=userman&command=disable")" = 405 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/ajax.php?module=discovery&command=not-allowed")" = 403 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/module-asset.php?module=discovery&file=../../Discovery.class.php")" = 400 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/module-asset.php?module=discovery&file=js/discovery.js")" = 200 ]
session_id=$(awk '$6=="ATUMSESSID"{print $7}' "$TEST_DIR/cookies")
php -r 'session_name("ATUMSESSID"); session_save_path($argv[1]); session_id($argv[2]); session_start(); $_SESSION["atum_last_seen"]=time()-1901; session_write_close();' "$ATUM_STATE_DIR/sessions" "$session_id"
curl -fsS -c "$TEST_DIR/cookies" -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/index.php" > "$TEST_DIR/expired"
grep -q 'Sign in' "$TEST_DIR/expired"
csrf=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TEST_DIR/expired" | head -n1)
curl -sS -c "$TEST_DIR/cookies" -b "$TEST_DIR/cookies" -d "login=1&csrf=$csrf&username=httpadmin&password=HTTP%20test%20password%20123" -o /dev/null "http://127.0.0.1:$PORT/index.php"
php -r '$db=new PDO("sqlite:".$argv[1]); $db->exec("UPDATE users SET session_version=session_version+1 WHERE username=\"httpadmin\"");' "$ATUM_STATE_DIR/atum.sqlite"
curl -fsS -c "$TEST_DIR/cookies" -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/index.php" > "$TEST_DIR/revalidated"
grep -q 'Sign in' "$TEST_DIR/revalidated"
csrf=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TEST_DIR/revalidated" | head -n1)
curl -sS -c "$TEST_DIR/cookies" -b "$TEST_DIR/cookies" -d "login=1&csrf=$csrf&username=httplimited&password=HTTP%20limited%20password%20123" -o /dev/null "http://127.0.0.1:$PORT/index.php"
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/index.php?display=moduleadmin")" = 404 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' -b "$TEST_DIR/cookies" "http://127.0.0.1:$PORT/ajax.php?module=userman&command=delete")" = 403 ]

# Remote mode refuses plain HTTP before starting a session, while the trusted
# local TLS boundary produces Secure/HttpOnly/SameSite cookies and permits login.
kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; SERVER_PID=""
ATUM_REQUIRE_HTTPS=true php -S "127.0.0.1:$PORT" -t "$ROOT/public" >"$TEST_DIR/plain-remote.log" 2>&1 & SERVER_PID=$!
i=0; while [ "$i" -lt 20 ]; do code=$(curl -sS -o "$TEST_DIR/plain-body" -D "$TEST_DIR/plain-headers" -w '%{http_code}' "http://127.0.0.1:$PORT/index.php" || true); [ "$code" = 403 ] && break; i=$((i+1)); sleep 1; done
[ "$code" = 403 ]
! grep -qi '^Set-Cookie:' "$TEST_DIR/plain-headers"
kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; SERVER_PID=""
ATUM_REQUIRE_HTTPS=true php -S "127.0.0.1:$PORT" "$ROOT/utests/https-router.php" >"$TEST_DIR/https-boundary.log" 2>&1 & SERVER_PID=$!
i=0; while ! curl -fsS -D "$TEST_DIR/secure-headers" "http://127.0.0.1:$PORT/index.php" > "$TEST_DIR/secure-login"; do i=$((i+1)); [ "$i" -lt 20 ] || exit 1; sleep 1; done
grep -qi '^Set-Cookie: ATUMSESSID=.*Secure.*HttpOnly.*SameSite=Strict' "$TEST_DIR/secure-headers"
secure_session=$(sed -n 's/^Set-Cookie: ATUMSESSID=\([^;]*\).*/\1/ip' "$TEST_DIR/secure-headers" | head -n1)
secure_csrf=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TEST_DIR/secure-login" | head -n1)
[ -n "$secure_session" ] && [ -n "$secure_csrf" ]
curl -sS -D "$TEST_DIR/secure-login-headers" -H "Cookie: ATUMSESSID=$secure_session" -d "login=1&csrf=$secure_csrf&username=httpadmin&password=HTTP%20test%20password%20123" "http://127.0.0.1:$PORT/index.php" -o /dev/null
grep -qi '^Location: index.php' "$TEST_DIR/secure-login-headers"
secure_admin_session=$(sed -n 's/^Set-Cookie: ATUMSESSID=\([^;]*\).*/\1/ip' "$TEST_DIR/secure-login-headers" | head -n1)
[ -n "$secure_admin_session" ]
curl -fsS -H "Cookie: ATUMSESSID=$secure_admin_session" "http://127.0.0.1:$PORT/index.php" > "$TEST_DIR/secure-admin-page"
grep -q 'Dashboard' "$TEST_DIR/secure-admin-page"
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H "Cookie: ATUMSESSID=$secure_admin_session" -X POST "http://127.0.0.1:$PORT/ajax.php?module=userman&command=disable")" = 403 ]

curl -fsS -D "$TEST_DIR/secure-viewer-headers" "http://127.0.0.1:$PORT/index.php" > "$TEST_DIR/secure-viewer-login"
secure_viewer_session=$(sed -n 's/^Set-Cookie: ATUMSESSID=\([^;]*\).*/\1/ip' "$TEST_DIR/secure-viewer-headers" | head -n1)
secure_viewer_csrf=$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$TEST_DIR/secure-viewer-login" | head -n1)
curl -sS -D "$TEST_DIR/secure-viewer-login-headers" -H "Cookie: ATUMSESSID=$secure_viewer_session" -d "login=1&csrf=$secure_viewer_csrf&username=httplimited&password=HTTP%20limited%20password%20123" "http://127.0.0.1:$PORT/index.php" -o /dev/null
secure_viewer_session=$(sed -n 's/^Set-Cookie: ATUMSESSID=\([^;]*\).*/\1/ip' "$TEST_DIR/secure-viewer-login-headers" | head -n1)
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H "Cookie: ATUMSESSID=$secure_viewer_session" "http://127.0.0.1:$PORT/index.php?display=moduleadmin")" = 404 ]
echo 'PASS  HTTP authentication, throttling, rotation, expiry, revalidation, CSRF, method, allowlist and asset boundary checks'
