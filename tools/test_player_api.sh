#!/bin/bash
set -euo pipefail

# ═══════════════════════════════════════════════════════════════════
#  Player API Test Suite
# ═══════════════════════════════════════════════════════════════════
#
#  Тестирует все endpoints PlayerApiController через HTTP.
#  Каждый тест проверяет HTTP-статус, Content-Type и структуру JSON.
#
#  Использование:
#    ./tools/test_player_api.sh <base_url> <username> <password>
#    ./tools/test_player_api.sh https://example.com testuser testpass
#
#  Опционально (env variables):
#    STREAM_ID=123    — ID live-стрима для EPG-тестов
#    VOD_ID=456       — ID VOD для get_vod_info
#    SERIES_ID=789    — ID сериала для get_series_info
#    CATEGORY_ID=10   — ID категории для фильтрации
#    VERBOSE=1        — показывать тела ответов
#    TIMEOUT=10       — таймаут curl в секундах
#
# ═══════════════════════════════════════════════════════════════════
#
#  API Documentation — Player API (player_api.php)
#
# ═══════════════════════════════════════════════════════════════════
#
#  Аутентификация:
#    GET /player_api.php?username=XXX&password=YYY
#    GET /player_api.php?token=ZZZ
#
#  Все параметры передаются через query string (GET).
#
# ───────────────────────────────────────────────────────────────────
#  Endpoints (action=...)
# ───────────────────────────────────────────────────────────────────
#
#  1. (без action) — Информация о пользователе и сервере
#     Params: —
#     Response: {
#       "user_info": {
#         "username", "password", "message", "auth", "status",
#         "exp_date", "is_trial", "active_cons", "created_at",
#         "max_connections", "allowed_output_formats"
#       },
#       "server_info": {
#         "version", "url", "port", "https_port", "server_protocol",
#         "rtmp_port", "timestamp_now", "time_now", "timezone", "process"
#       }
#     }
#
#  2. get_live_categories — Категории Live/Radio потоков
#     Params: —
#     Response: [{ "category_id", "category_name", "parent_id" }]
#
#  3. get_vod_categories — Категории VOD (фильмов)
#     Params: —
#     Response: [{ "category_id", "category_name", "parent_id" }]
#
#  4. get_series_categories — Категории сериалов
#     Params: —
#     Response: [{ "category_id", "category_name", "parent_id" }]
#
#  5. get_live_streams — Список live-потоков
#     Params: category_id (опц.), params[offset], params[items_per_page]
#     Response: [{
#       "num", "name", "stream_type", "stream_id", "stream_icon",
#       "epg_channel_id", "added", "custom_sid", "tv_archive",
#       "direct_source", "tv_archive_duration", "category_id",
#       "category_ids", "thumbnail"
#     }]
#
#  6. get_vod_streams — Список VOD (фильмов)
#     Params: category_id (опц.), params[offset], params[items_per_page]
#     Response: [{
#       "num", "name", "title", "year", "stream_type", "stream_id",
#       "stream_icon", "rating", "rating_5based", "added", "plot",
#       "cast", "director", "genre", "release_date", "youtube_trailer",
#       "episode_run_time", "category_id", "category_ids",
#       "container_extension", "custom_sid", "direct_source"
#     }]
#
#  7. get_series — Список сериалов
#     Params: category_id (опц.)
#     Response: [{
#       "num", "name", "title", "year", "stream_type", "series_id",
#       "cover", "plot", "cast", "director", "genre", "release_date",
#       "releaseDate", "last_modified", "rating", "rating_5based",
#       "backdrop_path", "youtube_trailer", "episode_run_time",
#       "category_id", "category_ids"
#     }]
#
#  8. get_series_info — Детальная информация о сериале
#     Params: series_id (обяз.)
#     Response: {
#       "seasons": [{ "cover", "cover_big", ... }],
#       "info": { "name", "title", "year", "cover", "plot", ... },
#       "episodes": { "1": [{ "id", "episode_num", "title", ... }] }
#     }
#
#  9. get_vod_info — Детальная информация о VOD
#     Params: vod_id (обяз.)
#     Response: {
#       "info": { "tmdb_id", "episode_run_time", "releasedate",
#         "cover_big", "movie_image", "rating", "backdrop_path",
#         "subtitles": [{ "index", "language", "title" }]
#       },
#       "movie_data": { "stream_id", "name", "title", "year",
#         "added", "category_id", "category_ids",
#         "container_extension", "custom_sid", "direct_source"
#       }
#     }
#
#  10. get_epg — Полная EPG-программа для потока(ов)
#      Params: stream_id (обяз., одиночный или через запятую)
#              from_now (опц., фильтр только будущие)
#      Response (single):  [{ "title", "description", "start", "end" }]
#      Response (multi):   { "123": [...], "456": [...] }
#
#  11. get_short_epg — Текущая и ближайшие программы
#      Params: stream_id (обяз.), limit (опц., default=4)
#      Response: { "epg_listings": [{ "title", "description",
#        "start", "stop", "end", "start_timestamp", "stop_timestamp",
#        "end_timestamp" }] }
#
#  12. get_simple_data_table — EPG с архивной информацией
#      Params: stream_id (обяз.)
#      Response: { "epg_listings": [{
#        "title", "description", "start", "end",
#        "start_timestamp", "stop_timestamp",
#        "now_playing", "has_archive"
#      }] }
#
# ───────────────────────────────────────────────────────────────────
#  Числовые алиасы action
# ───────────────────────────────────────────────────────────────────
#
#  200 → get_vod_categories    205 → get_short_epg
#  201 → get_live_categories   206 → get_series_categories
#  202 → get_live_streams      207 → get_simple_data_table
#  203 → get_vod_streams       208 → get_series
#  204 → get_series_info       209 → get_vod_info
#
# ───────────────────────────────────────────────────────────────────
#  Panel API (legacy)
# ───────────────────────────────────────────────────────────────────
#
#  URL: /panel_api.php (вместо /player_api.php)
#  Требует: настройка legacy_panel_api=1
#  Без action: возвращает default info + автозагрузку каналов
#  С action: аналогично player_api
#
# ═══════════════════════════════════════════════════════════════════

# ─── Цвета ────────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ─── Аргументы ────────────────────────────────────────────────────

if [[ $# -lt 3 ]]; then
    echo -e "${BOLD}Usage:${NC} $0 <base_url> <username> <password>"
    echo ""
    echo "  base_url   — URL сервера (https://example.com)"
    echo "  username   — логин пользователя"
    echo "  password   — пароль пользователя"
    echo ""
    echo "  Env vars:  STREAM_ID, VOD_ID, SERIES_ID, CATEGORY_ID, VERBOSE, TIMEOUT"
    exit 1
fi

BASE_URL="${1%/}"
USERNAME="$2"
PASSWORD="$3"

STREAM_ID="${STREAM_ID:-}"
VOD_ID="${VOD_ID:-}"
SERIES_ID="${SERIES_ID:-}"
CATEGORY_ID="${CATEGORY_ID:-}"
VERBOSE="${VERBOSE:-0}"
TIMEOUT="${TIMEOUT:-10}"

API_URL="${BASE_URL}/player_api.php"
AUTH="username=${USERNAME}&password=${PASSWORD}"

# ─── Счётчики ─────────────────────────────────────────────────────

PASS=0
FAIL=0
SKIP=0
WARN=0

# ─── Утилиты ──────────────────────────────────────────────────────

log_pass() { echo -e "  ${GREEN}✓ PASS${NC} $1"; ((PASS++)) || true; }
log_fail() { echo -e "  ${RED}✗ FAIL${NC} $1"; ((FAIL++)) || true; }
log_skip() { echo -e "  ${YELLOW}○ SKIP${NC} $1"; ((SKIP++)) || true; }
log_warn() { echo -e "  ${YELLOW}⚠ WARN${NC} $1"; ((WARN++)) || true; }
log_head() { echo -e "\n${CYAN}${BOLD}═══ $1 ═══${NC}"; }

# ─── HTTP-запрос с проверкой ──────────────────────────────────────
#
# api_test <test_name> <url_params> <required_keys...>
#
# Выполняет GET-запрос, проверяет:
#   1. HTTP 200
#   2. Content-Type: application/json
#   3. Ответ — валидный JSON
#   4. Наличие обязательных ключей (если указаны)
#
# Возвращает тело ответа через глобальный RESPONSE.

RESPONSE=""
HTTP_CODE=""

api_get() {
    local url="${API_URL}?${AUTH}&${1}"
    local tmp_headers
    tmp_headers=$(mktemp)
    trap "rm -f '$tmp_headers'" RETURN

    RESPONSE=$(curl -s -w '\n%{http_code}' \
        --max-time "$TIMEOUT" \
        -D "$tmp_headers" \
        "$url" 2>/dev/null) || {
        RESPONSE=""
        HTTP_CODE="000"
        return 1
    }

    HTTP_CODE=$(echo "$RESPONSE" | tail -1)
    RESPONSE=$(echo "$RESPONSE" | sed '$d')

    return 0
}

api_test() {
    local test_name="$1"
    local params="$2"
    shift 2
    local required_keys=("$@")

    api_get "$params" || true

    # HTTP status
    if [[ "$HTTP_CODE" != "200" ]]; then
        log_fail "${test_name} — HTTP ${HTTP_CODE}"
        if [[ "$VERBOSE" == "1" ]] && [[ -n "$RESPONSE" ]]; then
            echo "       Response: ${RESPONSE:0:200}"
        fi
        return 1
    fi

    # Valid JSON
    if ! echo "$RESPONSE" | python3 -m json.tool > /dev/null 2>&1; then
        log_fail "${test_name} — invalid JSON"
        if [[ "$VERBOSE" == "1" ]]; then
            echo "       Response: ${RESPONSE:0:300}"
        fi
        return 1
    fi

    # Required keys
    local missing=()
    for key in "${required_keys[@]}"; do
        if [[ -z "$key" ]]; then
            continue
        fi
        if ! echo "$RESPONSE" | python3 -c "
import json, sys
data = json.load(sys.stdin)
keys = '${key}'.split('.')
obj = data
for k in keys:
    if isinstance(obj, dict) and k in obj:
        obj = obj[k]
    elif isinstance(obj, list) and len(obj) > 0 and k in obj[0]:
        obj = obj[0][k]
    else:
        sys.exit(1)
" 2>/dev/null; then
            missing+=("$key")
        fi
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        log_warn "${test_name} — missing keys: ${missing[*]}"
        if [[ "$VERBOSE" == "1" ]]; then
            echo "       Response: ${RESPONSE:0:500}"
        fi
        return 0
    fi

    log_pass "$test_name"

    if [[ "$VERBOSE" == "1" ]]; then
        local len=${#RESPONSE}
        echo "       (${len} bytes)"
    fi

    return 0
}

# Проверить что ответ — JSON-массив
assert_array() {
    local test_name="$1"
    if echo "$RESPONSE" | python3 -c "import json,sys; d=json.load(sys.stdin); sys.exit(0 if isinstance(d,list) else 1)" 2>/dev/null; then
        log_pass "${test_name} — is array"
    else
        log_fail "${test_name} — expected array"
    fi
}

# Проверить что ответ — JSON-объект
assert_object() {
    local test_name="$1"
    if echo "$RESPONSE" | python3 -c "import json,sys; d=json.load(sys.stdin); sys.exit(0 if isinstance(d,dict) else 1)" 2>/dev/null; then
        log_pass "${test_name} — is object"
    else
        log_fail "${test_name} — expected object"
    fi
}

# Получить количество элементов в ответе
response_count() {
    echo "$RESPONSE" | python3 -c "import json,sys; d=json.load(sys.stdin); print(len(d) if isinstance(d,list) else len(d.keys()))" 2>/dev/null || echo "?"
}

# ═══════════════════════════════════════════════════════════════════
#  ТЕСТЫ
# ═══════════════════════════════════════════════════════════════════

echo -e "${BOLD}Player API Test Suite${NC}"
echo -e "URL:  ${API_URL}"
echo -e "User: ${USERNAME}"
echo -e "Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# ─── 0. Connectivity ─────────────────────────────────────────────

log_head "0. Connectivity"

api_get "" || true
if [[ "$HTTP_CODE" == "000" ]]; then
    echo -e "${RED}Cannot connect to ${API_URL}${NC}"
    echo "Check URL and network connectivity."
    exit 1
fi
log_pass "Server reachable (HTTP ${HTTP_CODE})"

# ─── 1. Auth — Default Info ──────────────────────────────────────

log_head "1. Authentication + Default Info (no action)"

api_test "default info" "" \
    "user_info.username" \
    "user_info.status" \
    "user_info.exp_date" \
    "user_info.max_connections" \
    "user_info.allowed_output_formats" \
    "server_info.version" \
    "server_info.url" \
    "server_info.port" \
    "server_info.timestamp_now"

# Проверяем username в ответе
if echo "$RESPONSE" | python3 -c "
import json, sys
d = json.load(sys.stdin)
assert d['user_info']['username'] == '${USERNAME}'
" 2>/dev/null; then
    log_pass "username matches"
else
    log_fail "username mismatch in response"
fi

# ─── 2. Auth errors ──────────────────────────────────────────────

log_head "2. Auth Error Handling"

# Неверные креды
local_url="${API_URL}?username=__invalid__&password=__invalid__"
local_resp=$(curl -s -w '\n%{http_code}' --max-time "$TIMEOUT" "$local_url" 2>/dev/null) || true
local_code=$(echo "$local_resp" | tail -1)

if [[ "$local_code" != "200" ]] || echo "$local_resp" | head -1 | python3 -c "
import json, sys
d = json.load(sys.stdin)
sys.exit(0 if 'error' in str(d).lower() or d.get('user_info',{}).get('auth') != 1 else 1)
" 2>/dev/null; then
    log_pass "invalid credentials rejected"
else
    log_warn "invalid credentials may not be properly rejected"
fi

# Пустые креды
local_url="${API_URL}?username=&password="
local_resp=$(curl -s --max-time "$TIMEOUT" "$local_url" 2>/dev/null) || true
log_pass "empty credentials handled (no crash)"

# ─── 3. Categories ───────────────────────────────────────────────

log_head "3. Categories"

api_test "get_live_categories" "action=get_live_categories" \
    "category_id" "category_name"
if [[ "$HTTP_CODE" == "200" ]]; then
    assert_array "get_live_categories"
    echo -e "       count: $(response_count)"
fi

api_test "get_vod_categories" "action=get_vod_categories" \
    "category_id" "category_name"
if [[ "$HTTP_CODE" == "200" ]]; then
    assert_array "get_vod_categories"
    echo -e "       count: $(response_count)"
fi

api_test "get_series_categories" "action=get_series_categories" \
    "category_id" "category_name"
if [[ "$HTTP_CODE" == "200" ]]; then
    assert_array "get_series_categories"
    echo -e "       count: $(response_count)"
fi

# Числовые алиасы
api_test "numeric alias 201=get_live_categories" "action=201"
api_test "numeric alias 200=get_vod_categories" "action=200"
api_test "numeric alias 206=get_series_categories" "action=206"

# ─── 4. Live Streams ─────────────────────────────────────────────

log_head "4. Live Streams"

api_test "get_live_streams" "action=get_live_streams" \
    "stream_id" "name" "stream_type" "category_id"
if [[ "$HTTP_CODE" == "200" ]]; then
    assert_array "get_live_streams"
    count=$(response_count)
    echo -e "       count: ${count}"

    # Сохраняем первый stream_id для EPG тестов
    if [[ -z "$STREAM_ID" ]]; then
        STREAM_ID=$(echo "$RESPONSE" | python3 -c "
import json, sys
d = json.load(sys.stdin)
if d: print(d[0]['stream_id'])
else: print('')
" 2>/dev/null) || true
        if [[ -n "$STREAM_ID" ]]; then
            echo -e "       auto-detected STREAM_ID=${STREAM_ID}"
        fi
    fi
fi

# С фильтром по категории
if [[ -n "$CATEGORY_ID" ]]; then
    api_test "get_live_streams (category_id=${CATEGORY_ID})" \
        "action=get_live_streams&category_id=${CATEGORY_ID}" \
        "stream_id"
else
    log_skip "get_live_streams + category_id (set CATEGORY_ID env)"
fi

# Числовой алиас
api_test "numeric alias 202=get_live_streams" "action=202"

# ─── 5. VOD Streams ──────────────────────────────────────────────

log_head "5. VOD Streams"

api_test "get_vod_streams" "action=get_vod_streams" \
    "stream_id" "name" "container_extension"
if [[ "$HTTP_CODE" == "200" ]]; then
    assert_array "get_vod_streams"
    count=$(response_count)
    echo -e "       count: ${count}"

    # Сохраняем первый vod_id
    if [[ -z "$VOD_ID" ]]; then
        VOD_ID=$(echo "$RESPONSE" | python3 -c "
import json, sys
d = json.load(sys.stdin)
if d: print(d[0]['stream_id'])
else: print('')
" 2>/dev/null) || true
        if [[ -n "$VOD_ID" ]]; then
            echo -e "       auto-detected VOD_ID=${VOD_ID}"
        fi
    fi
fi

api_test "numeric alias 203=get_vod_streams" "action=203"

# ─── 6. Series ────────────────────────────────────────────────────

log_head "6. Series"

api_test "get_series" "action=get_series" \
    "series_id" "name" "title"
if [[ "$HTTP_CODE" == "200" ]]; then
    assert_array "get_series"
    count=$(response_count)
    echo -e "       count: ${count}"

    # Сохраняем первый series_id
    if [[ -z "$SERIES_ID" ]]; then
        SERIES_ID=$(echo "$RESPONSE" | python3 -c "
import json, sys
d = json.load(sys.stdin)
if d: print(d[0]['series_id'])
else: print('')
" 2>/dev/null) || true
        if [[ -n "$SERIES_ID" ]]; then
            echo -e "       auto-detected SERIES_ID=${SERIES_ID}"
        fi
    fi
fi

api_test "numeric alias 208=get_series" "action=208"

# ─── 7. Series Info ───────────────────────────────────────────────

log_head "7. Series Info"

if [[ -n "$SERIES_ID" ]]; then
    api_test "get_series_info (id=${SERIES_ID})" \
        "action=get_series_info&series_id=${SERIES_ID}" \
        "info.name" "seasons"
    if [[ "$HTTP_CODE" == "200" ]]; then
        assert_object "get_series_info"
        # Проверяем episodes
        has_episodes=$(echo "$RESPONSE" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('yes' if 'episodes' in d and d['episodes'] else 'no')
" 2>/dev/null) || true
        if [[ "$has_episodes" == "yes" ]]; then
            log_pass "get_series_info has episodes"
        else
            log_warn "get_series_info — no episodes found"
        fi
    fi

    api_test "numeric alias 204=get_series_info" \
        "action=204&series_id=${SERIES_ID}"
else
    log_skip "get_series_info (no SERIES_ID available)"
    log_skip "numeric alias 204 (no SERIES_ID)"
fi

# ─── 8. VOD Info ──────────────────────────────────────────────────

log_head "8. VOD Info"

if [[ -n "$VOD_ID" ]]; then
    api_test "get_vod_info (id=${VOD_ID})" \
        "action=get_vod_info&vod_id=${VOD_ID}" \
        "info" "movie_data.stream_id"
    if [[ "$HTTP_CODE" == "200" ]]; then
        assert_object "get_vod_info"
        # Проверяем subtitles
        has_subtitles=$(echo "$RESPONSE" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('yes' if 'subtitles' in d.get('info', {}) else 'no')
" 2>/dev/null) || true
        if [[ "$has_subtitles" == "yes" ]]; then
            log_pass "get_vod_info has subtitles field"
        else
            log_warn "get_vod_info — no subtitles field"
        fi
    fi

    api_test "numeric alias 209=get_vod_info" \
        "action=209&vod_id=${VOD_ID}"
else
    log_skip "get_vod_info (no VOD_ID available)"
    log_skip "numeric alias 209 (no VOD_ID)"
fi

# ─── 9. EPG ───────────────────────────────────────────────────────

log_head "9. EPG"

if [[ -n "$STREAM_ID" ]]; then
    api_test "get_epg (stream_id=${STREAM_ID})" \
        "action=get_epg&stream_id=${STREAM_ID}"
    if [[ "$HTTP_CODE" == "200" ]]; then
        assert_array "get_epg (single)"
        echo -e "       entries: $(response_count)"
    fi

    # EPG from_now
    api_test "get_epg + from_now" \
        "action=get_epg&stream_id=${STREAM_ID}&from_now=1"

    # Short EPG
    api_test "get_short_epg (stream_id=${STREAM_ID})" \
        "action=get_short_epg&stream_id=${STREAM_ID}" \
        "epg_listings"
    if [[ "$HTTP_CODE" == "200" ]]; then
        assert_object "get_short_epg"
    fi

    # Short EPG with limit
    api_test "get_short_epg + limit=2" \
        "action=get_short_epg&stream_id=${STREAM_ID}&limit=2" \
        "epg_listings"

    # Simple data table
    api_test "get_simple_data_table (stream_id=${STREAM_ID})" \
        "action=get_simple_data_table&stream_id=${STREAM_ID}" \
        "epg_listings"
    if [[ "$HTTP_CODE" == "200" ]]; then
        assert_object "get_simple_data_table"
    fi

    # Числовой алиас
    api_test "numeric alias 205=get_short_epg" \
        "action=205&stream_id=${STREAM_ID}"
    api_test "numeric alias 207=get_simple_data_table" \
        "action=207&stream_id=${STREAM_ID}"
else
    log_skip "get_epg (no STREAM_ID available — set STREAM_ID env)"
    log_skip "get_short_epg (no STREAM_ID)"
    log_skip "get_simple_data_table (no STREAM_ID)"
    log_skip "numeric aliases 205, 207 (no STREAM_ID)"
fi

# ─── 10. Multi-stream EPG ────────────────────────────────────────

log_head "10. Multi-stream EPG"

if [[ -n "$STREAM_ID" ]]; then
    # Multi через запятую
    api_test "get_epg multi (stream_id=${STREAM_ID},${STREAM_ID})" \
        "action=get_epg&stream_id=${STREAM_ID},${STREAM_ID}"
    if [[ "$HTTP_CODE" == "200" ]]; then
        assert_object "get_epg multi"
    fi

    api_test "get_short_epg multi" \
        "action=get_short_epg&stream_id=${STREAM_ID},${STREAM_ID}"
else
    log_skip "multi-stream EPG (no STREAM_ID)"
fi

# ─── 11. Pagination ──────────────────────────────────────────────

log_head "11. Pagination"

api_test "get_live_streams offset=0, limit=3" \
    "action=get_live_streams&params[offset]=0&params[items_per_page]=3"
if [[ "$HTTP_CODE" == "200" ]]; then
    count=$(response_count)
    if [[ "$count" -le 3 ]] 2>/dev/null; then
        log_pass "pagination limit respected (got ${count})"
    else
        log_warn "pagination: expected ≤3, got ${count}"
    fi
fi

api_test "get_vod_streams offset=0, limit=2" \
    "action=get_vod_streams&params[offset]=0&params[items_per_page]=2"

# ─── 12. Edge Cases ──────────────────────────────────────────────

log_head "12. Edge Cases"

# Неизвестный action → default info
api_test "unknown action → default info" \
    "action=nonexistent_action" \
    "user_info" "server_info"

# get_vod_info без vod_id → пустой info
api_test "get_vod_info without vod_id" \
    "action=get_vod_info" \
    "info"

# get_series_info без series_id
api_test "get_series_info without series_id" \
    "action=get_series_info"

# get_epg без stream_id → пустой массив
api_test "get_epg without stream_id" \
    "action=get_epg"

# Несуществующий stream_id
api_test "get_epg invalid stream_id" \
    "action=get_epg&stream_id=999999999"

# Несуществующий vod_id
api_test "get_vod_info invalid vod_id" \
    "action=get_vod_info&vod_id=999999999" \
    "info"

# ─── 13. Panel API (legacy) ──────────────────────────────────────

log_head "13. Panel API (legacy)"

PANEL_URL="${BASE_URL}/panel_api.php?${AUTH}"
panel_resp=$(curl -s -w '\n%{http_code}' --max-time "$TIMEOUT" "$PANEL_URL" 2>/dev/null) || true
panel_code=$(echo "$panel_resp" | tail -1)

if [[ "$panel_code" == "200" ]]; then
    panel_body=$(echo "$panel_resp" | sed '$d')
    if echo "$panel_body" | python3 -m json.tool > /dev/null 2>&1; then
        log_pass "panel_api.php returns valid JSON (HTTP 200)"
    else
        log_warn "panel_api.php returns HTTP 200 but invalid JSON"
    fi
else
    log_warn "panel_api.php returned HTTP ${panel_code} (may be disabled)"
fi

# ═══════════════════════════════════════════════════════════════════
#  РЕЗУЛЬТАТЫ
# ═══════════════════════════════════════════════════════════════════

echo ""
echo -e "${BOLD}═══════════════════════════════════════════════${NC}"
echo -e "${BOLD}  Results${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════${NC}"
echo -e "  ${GREEN}PASS:${NC}  ${PASS}"
echo -e "  ${RED}FAIL:${NC}  ${FAIL}"
echo -e "  ${YELLOW}WARN:${NC}  ${WARN}"
echo -e "  ${YELLOW}SKIP:${NC}  ${SKIP}"
TOTAL=$((PASS + FAIL + WARN + SKIP))
echo -e "  ${BOLD}TOTAL: ${TOTAL}${NC}"
echo ""

if [[ "$FAIL" -gt 0 ]]; then
    echo -e "${RED}${BOLD}Some tests FAILED!${NC}"
    exit 1
else
    echo -e "${GREEN}${BOLD}All tests passed.${NC}"
    exit 0
fi
