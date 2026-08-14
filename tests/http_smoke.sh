#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${EDUSHARE_TEST_PORT:-8099}"
BASE="http://127.0.0.1:${PORT}"
TMP="$(mktemp -d)"
TEACHER_JAR="$TMP/teacher.cookies"
STUDENT_JAR="$TMP/student.cookies"
SERVER_LOG="$TMP/php-server.log"
TEACHER_EMAIL="http-teacher-$(date +%s)-$$@example.test"
STUDENT_EMAIL="http-student-$(date +%s)-$$@example.test"
TEACHER_PASSWORD='TeacherHttp123'
STUDENT_PASSWORD='StudentHttp123'

cleanup() {
    if [[ -n "${SERVER_PID:-}" ]]; then kill "$SERVER_PID" 2>/dev/null || true; fi
    rm -rf "$TMP"
}
trap cleanup EXIT

php -S "127.0.0.1:${PORT}" -t "$ROOT" "$ROOT/dev_router.php" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
for _ in {1..30}; do
    if curl -fsS "$BASE/index.php" >/dev/null 2>&1; then break; fi
    sleep 0.2
done
if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    cat "$SERVER_LOG" >&2
    exit 1
fi

csrf_from() {
    sed -n 's/.*name="_csrf" value="\([a-f0-9]\{64\}\)".*/\1/p' "$1" | head -n1
}
meta_csrf_from() {
    sed -n 's/.*name="csrf-token" content="\([a-f0-9]\{64\}\)".*/\1/p' "$1" | head -n1
}
expect_status() {
    local expected="$1" actual="$2" label="$3"
    if [[ "$actual" != "$expected" ]]; then
        echo "FAIL $label: expected HTTP $expected, received $actual" >&2
        cat "$SERVER_LOG" >&2
        exit 1
    fi
    echo "PASS $label"
}

status="$(curl -sS -o "$TMP/index.html" -w '%{http_code}' "$BASE/index.php")"
expect_status 200 "$status" 'canonical landing page renders'
grep -q 'Share Knowledge, Empower Peers' "$TMP/index.html"
status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/mainhome.php")"
expect_status 301 "$status" 'legacy landing route redirects permanently'
status="$(curl -sS -o "$TMP/contributors-anonymous.json" -w '%{http_code}' "$BASE/api/v1/universities/1/contributors")"
expect_status 401 "$status" 'modular contributor route requires authentication'

# Register and authenticate a contributor.
curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/register.php" -o "$TMP/register-teacher.html"
csrf="$(csrf_from "$TMP/register-teacher.html")"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" --data-urlencode 'role=teacher' \
    --data-urlencode 'name=HTTP Synthetic Teacher' --data-urlencode "email=$TEACHER_EMAIL" \
    --data-urlencode "password=$TEACHER_PASSWORD" --data-urlencode 'university_id=1' \
    --data-urlencode 'department_id=1' "$BASE/register.php")"
expect_status 303 "$status" 'teacher registration succeeds'

curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/login1.php" -o "$TMP/login-teacher.html"
csrf="$(csrf_from "$TMP/login-teacher.html")"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" --data-urlencode "email=$TEACHER_EMAIL" \
    --data-urlencode "password=$TEACHER_PASSWORD" "$BASE/login1.php")"
expect_status 303 "$status" 'teacher login succeeds'

status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o "$TMP/contributors.json" -w '%{http_code}' "$BASE/api/v1/universities/1/contributors")"
expect_status 200 "$status" 'modular contributor directory route succeeds'
grep -q '"data"' "$TMP/contributors.json"
grep -q 'HTTP Synthetic Teacher' "$TMP/contributors.json"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o "$TMP/legacy-contributors.json" -w '%{http_code}' "$BASE/university_teachers.php?university_id=1")"
expect_status 200 "$status" 'legacy contributor directory remains available'
grep -q 'HTTP Synthetic Teacher' "$TMP/legacy-contributors.json"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' "$BASE/api/v1/universities/not-a-number/contributors")"
expect_status 422 "$status" 'modular contributor route rejects malformed IDs'
status="$(curl -sS -X POST -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' "$BASE/api/v1/universities/1/contributors")"
expect_status 405 "$status" 'modular contributor route rejects unsupported methods'

# Upload two files as one normalized resource/version through the multipart endpoint.
printf '%%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%%%EOF\n' > "$TMP/lecture.pdf"
printf 'Companion notes for the normalized resource.\n' > "$TMP/companion.txt"
curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/upload.php" -o "$TMP/upload.html"
csrf="$(csrf_from "$TMP/upload.html")"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' \
    -F "_csrf=$csrf" --form-string 'title=<img src=x onerror=alert(1)>' -F 'description=Synthetic integration resource' \
    -F 'university_id=1' -F 'department_id=1' -F 'course_id=1' -F 'subject_id=1' -F 'semester=1' \
    -F "files[]=@$TMP/lecture.pdf;filename=lecture.pdf;type=application/pdf" \
    -F "files[]=@$TMP/companion.txt;filename=companion.txt;type=text/plain" "$BASE/process_upload.php")"
expect_status 303 "$status" 'contributor multi-file resource upload succeeds'
curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/upload.php" -o "$TMP/upload-result.html"
grep -q 'Resource created atomically with 2 file(s).' "$TMP/upload-result.html"

curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/dashboard.php" -o "$TMP/teacher-dashboard.html"
RESOURCE_ID="$(sed -n 's/.*resource.php?id=\([0-9][0-9]*\).*/\1/p' "$TMP/teacher-dashboard.html" | head -n1)"
[[ "$RESOURCE_ID" =~ ^[1-9][0-9]*$ ]]
grep -q '2 current file(s) · Version 1 of 1' "$TMP/teacher-dashboard.html"
curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/resource.php?id=$RESOURCE_ID" -o "$TMP/resource-v1.html"
grep -q "Stable resource #$RESOURCE_ID · Current version 1" "$TMP/resource-v1.html"
grep -q 'lecture.pdf' "$TMP/resource-v1.html"
grep -q 'companion.txt' "$TMP/resource-v1.html"
FILE_ID="$(sed -n 's/.*download.php?id=[0-9][0-9]*&amp;file_id=\([0-9][0-9]*\).*/\1/p' "$TMP/resource-v1.html" | head -n1)"
[[ "$FILE_ID" =~ ^[1-9][0-9]*$ ]]
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o "$TMP/download.pdf" -w '%{http_code}' "$BASE/download.php?id=$RESOURCE_ID&file_id=$FILE_ID")"
expect_status 200 "$status" 'owner can download a resource file by logical IDs'
grep -q '^%PDF-' "$TMP/download.pdf"
PROFILE_ID="$(sed -n 's/.*teacher_profile.php?id=\([0-9][0-9]*\).*/\1/p' "$TMP/teacher-dashboard.html" | head -n1)"
[[ "$PROFILE_ID" =~ ^[1-9][0-9]*$ ]]
status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/download.php?id=$RESOURCE_ID&file_id=$FILE_ID")"
expect_status 401 "$status" 'anonymous user cannot download an authenticated resource file'

# Add a complete second version and verify stable identity plus retained history.
printf '%%PDF-1.4\nversion two\n%%%%EOF\n' > "$TMP/lecture-v2.pdf"
curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/upload.php?resource_id=$RESOURCE_ID" -o "$TMP/add-version.html"
csrf="$(csrf_from "$TMP/add-version.html")"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' \
    -F "_csrf=$csrf" -F "resource_id=$RESOURCE_ID" -F 'change_description=Corrected HTTP edition' \
    -F "files[]=@$TMP/lecture-v2.pdf;filename=lecture-v2.pdf;type=application/pdf" "$BASE/process_upload.php")"
expect_status 303 "$status" 'owner can append a numbered resource version'
curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/resource.php?id=$RESOURCE_ID" -o "$TMP/resource-v2.html"
grep -q "Stable resource #$RESOURCE_ID · Current version 2" "$TMP/resource-v2.html"
grep -q 'Version 2 · Current' "$TMP/resource-v2.html"
grep -q 'Version 1' "$TMP/resource-v2.html"
grep -q 'lecture-v2.pdf' "$TMP/resource-v2.html"
grep -q 'companion.txt' "$TMP/resource-v2.html"

csrf="$(csrf_from "$TMP/resource-v2.html")"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" --data-urlencode "id=$RESOURCE_ID" \
    --data-urlencode 'title=<img src=x onerror=alert(1)>' \
    --data-urlencode 'description=Updated without replacing version history' \
    --data-urlencode 'visibility=authenticated' "$BASE/update_resource.php")"
expect_status 303 "$status" 'owner can update logical resource metadata'
curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" "$BASE/resource.php?id=$RESOURCE_ID" -o "$TMP/resource-updated.html"
grep -q 'Updated without replacing version history' "$TMP/resource-updated.html"
grep -q 'Version 2 · Current' "$TMP/resource-updated.html"
grep -q 'Version 1' "$TMP/resource-updated.html"

# Register a student and verify negative role paths.
curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" "$BASE/register.php" -o "$TMP/register-student.html"
csrf="$(csrf_from "$TMP/register-student.html")"
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" --data-urlencode 'role=student' \
    --data-urlencode 'name=HTTP Synthetic Student' --data-urlencode "email=$STUDENT_EMAIL" \
    --data-urlencode "password=$STUDENT_PASSWORD" --data-urlencode 'university_id=1' \
    --data-urlencode 'department_id=1' --data-urlencode 'branch=Computer Science' \
    --data-urlencode 'year=2' "$BASE/register.php")"
expect_status 303 "$status" 'student registration succeeds'
curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" "$BASE/login1.php" -o "$TMP/login-student.html"
csrf="$(csrf_from "$TMP/login-student.html")"
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" --data-urlencode "email=$STUDENT_EMAIL" \
    --data-urlencode "password=$STUDENT_PASSWORD" "$BASE/login1.php")"
expect_status 303 "$status" 'student login succeeds'

status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' "$BASE/upload.php")"
expect_status 403 "$status" 'student cannot access uploads'
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' "$BASE/admin.php")"
expect_status 403 "$status" 'student cannot access administration'
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' "$BASE/delete_material.php?id=$RESOURCE_ID")"
expect_status 405 "$status" 'GET cannot delete resource'
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' "$BASE/download.php?id=..%2Fetc%2Fpasswd")"
expect_status 422 "$status" 'traversal-shaped download ID is rejected'

curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" "$BASE/homepage.php" -o "$TMP/student-home.html"
if grep -q '<img src=x onerror=alert(1)>' "$TMP/student-home.html"; then
    echo 'FAIL stored XSS title rendered as active markup' >&2
    exit 1
fi
grep -q '&lt;img src=x onerror=alert(1)&gt;' "$TMP/student-home.html"
csrf="$(meta_csrf_from "$TMP/student-home.html")"
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o "$TMP/favorite.json" -w '%{http_code}' \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    --data "{\"resource_id\":$RESOURCE_ID}" "$BASE/toggle_favorite.php")"
expect_status 200 "$status" 'student can add a visible resource favorite'
grep -q '"action":"added"' "$TMP/favorite.json"
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o "$TMP/favorite.json" -w '%{http_code}' \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    --data "{\"resource_id\":$RESOURCE_ID}" "$BASE/toggle_favorite.php")"
expect_status 200 "$status" 'student can remove a resource favorite'
grep -q '"action":"removed"' "$TMP/favorite.json"

status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o "$TMP/university-favorite.json" -w '%{http_code}' \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    --data '{"university_id":1}' "$BASE/toggle_university_favorite.php")"
expect_status 200 "$status" 'student can toggle a university favorite'
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-CSRF-Token: invalid' \
    --data "{\"resource_id\":$RESOURCE_ID}" "$BASE/toggle_favorite.php")"
expect_status 419 "$status" 'favorite API rejects invalid CSRF token'
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' \
    -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    --data '{broken' "$BASE/toggle_favorite.php")"
expect_status 400 "$status" 'favorite API rejects malformed JSON'

# A student cannot delete another contributor's material even with a valid token.
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" --data-urlencode "id=$RESOURCE_ID" "$BASE/delete_material.php")"
expect_status 403 "$status" 'student cannot delete contributor resource'
status="$(curl -sS -c "$STUDENT_JAR" -b "$STUDENT_JAR" -o /dev/null -w '%{http_code}' "$BASE/download.php?id=$RESOURCE_ID&file_id=$FILE_ID")"
expect_status 200 "$status" 'authenticated student can download a visible historical resource file'

status="$(curl -sS -o "$TMP/departments.json" -w '%{http_code}' "$BASE/get_departments.php?id=1")"
expect_status 200 "$status" 'valid dependent selector request succeeds'
status="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/get_departments.php?id=invalid")"
expect_status 422 "$status" 'invalid dependent selector ID is rejected'

# The owner can delete via POST; the row/file are unavailable afterwards.
csrf="$(csrf_from "$TMP/teacher-dashboard.html")"
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" --data-urlencode "id=$RESOURCE_ID" "$BASE/delete_material.php")"
expect_status 303 "$status" 'owner can delete resource with CSRF-protected POST'
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' "$BASE/download.php?id=$RESOURCE_ID&file_id=$FILE_ID")"
expect_status 404 "$status" 'deleted resource is no longer downloadable'

status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' "$BASE/logout.php")"
expect_status 405 "$status" 'GET cannot log out a session'
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "_csrf=$csrf" "$BASE/logout.php")"
expect_status 303 "$status" 'authenticated CSRF-protected logout succeeds'
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o "$TMP/public-profile.html" -w '%{http_code}' "$BASE/teacher_profile.php?id=$PROFILE_ID")"
expect_status 200 "$status" 'privacy-minimized contributor profile remains public'
grep -q 'HTTP Synthetic Teacher' "$TMP/public-profile.html"
if grep -q "$TEACHER_EMAIL" "$TMP/public-profile.html"; then
    echo 'FAIL public contributor profile disclosed an email address' >&2
    exit 1
fi
status="$(curl -sS -c "$TEACHER_JAR" -b "$TEACHER_JAR" -o /dev/null -w '%{http_code}' "$BASE/dashboard.php")"
expect_status 302 "$status" 'logged-out session cannot access the dashboard'

echo 'HTTP smoke checks completed successfully.'
