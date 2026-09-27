#!/bin/sh
# CodexPicker setup for macOS and Linux. Requires curl, jq, and awk.
# Usage: ./setup.sh <config-id> [--api-endpoint URL]

set -u
SCRIPT_VERSION='2.1.0'
DEFAULT_API_ENDPOINT='http://codexpicker.test/api/v1'
PROVIDER_ID='codexpicker'
PROVIDER_SECTION=model_providers.$PROVIDER_ID
TOP_KEYS='model model_provider model_reasoning_effort model_catalog_json'
PROVIDER_KEYS='name base_url wire_api experimental_bearer_token'
DESKTOP_KEY='enabled-reasoning-efforts'
BACKUP_DIRNAME='backup-codexpicker'
CATALOG_FILENAME='codex_picker_models.json'
CODEX_DIR=${CODEX_HOME:-"$HOME/.codex"}
CONFIG_PATH=$CODEX_DIR/config.toml
MODELS_PATH=$CODEX_DIR/$CATALOG_FILENAME
BACKUP_DIR=$CODEX_DIR/$BACKUP_DIRNAME
BACKUP_CONFIG=$BACKUP_DIR/config.toml
BACKUP_MODELS=$BACKUP_DIR/$CATALOG_FILENAME
MANIFEST=$BACKUP_DIR/manifest.txt

ok() { printf '[OK] %s\n' "$1"; }
warn() { printf '[!]  %s\n' "$1"; }
die() { printf '\n[X] %s\n' "$1" >&2; exit 1; }
usage() { printf 'Usage: ./setup.sh <config-id> [--api-endpoint URL]\n'; }
ask() { printf '%s ' "$1"; IFS= read -r ANSWER || die 'Cannot read input.'; }

if [ "${1:-}" = '--help' ] || [ "${1:-}" = '-h' ]; then usage; exit 0; fi
if [ "$#" -eq 1 ]; then
    CONFIG_ID=$1; API_ENDPOINT=$DEFAULT_API_ENDPOINT
elif [ "$#" -eq 3 ] && { [ "$2" = '--api-endpoint' ] || [ "$2" = '-ApiEndpoint' ]; }; then
    CONFIG_ID=$1; API_ENDPOINT=$3
else
    usage >&2; exit 1
fi
for tool in curl jq awk; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is required to run setup.sh."
done
[ -n "$CONFIG_ID" ] || die 'Config ID cannot be empty.'
case $API_ENDPOINT in http://*|https://*) ;; *) die 'API endpoint must use http or https.' ;; esac
API_ENDPOINT=${API_ENDPOINT%/}
[ -d "$CODEX_DIR" ] || die "Codex configuration directory not found: $CODEX_DIR"

TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/codexpicker.XXXXXXXX") || die 'Could not create temporary files.'
chmod 700 "$TMP_DIR"
trap 'rm -rf "$TMP_DIR"' EXIT
REMOTE_CONFIG=$TMP_DIR/config.json
REMOTE_MODELS=$TMP_DIR/models.json
DESIRED_VALUES=$TMP_DIR/values.txt
AWK_SCRIPT=$TMP_DIR/update.awk
UPDATED_CONFIG=$TMP_DIR/config.toml

restore() {
    [ -d "$BACKUP_DIR" ] || die "Backup directory not found: $BACKUP_DIR"
    HAD_CONFIG=1
    if [ -f "$MANIFEST" ] && grep -q '^original_config_existed=0$' "$MANIFEST"; then HAD_CONFIG=0; fi
    HAD_CATALOG=0
    if [ -f "$MANIFEST" ] && grep -q '^original_catalog_existed=1$' "$MANIFEST"; then HAD_CATALOG=1; fi
    if [ "$HAD_CONFIG" -eq 1 ] && [ ! -f "$BACKUP_CONFIG" ]; then
        die "Backup is incomplete: $BACKUP_CONFIG is missing."
    fi
    if [ "$HAD_CATALOG" -eq 1 ] && [ ! -f "$BACKUP_MODELS" ]; then
        die "Backup is incomplete: $BACKUP_MODELS is missing."
    fi
    printf '\nRestore will update %s and %s, then remove %s.\n' "$CONFIG_PATH" "$MODELS_PATH" "$BACKUP_DIR"
    ask 'Restore now? Type y to continue, anything else to cancel:'
    case $ANSWER in y|Y|yes|YES) ;; *) printf 'Cancelled; nothing was modified.\n'; return ;; esac
    if [ "$HAD_CONFIG" -eq 1 ]; then
        cp "$BACKUP_CONFIG" "$CONFIG_PATH" || die 'Could not restore config.toml.'
        ok 'config.toml restored'
    else
        rm -f "$CONFIG_PATH" || die 'Could not remove config.toml.'
        ok 'config.toml removed'
    fi
    if [ "$HAD_CATALOG" -eq 1 ]; then
        cp "$BACKUP_MODELS" "$MODELS_PATH" || die 'Could not restore the model catalog.'
        ok 'Model catalog restored'
    else
        rm -f "$MODELS_PATH" || die 'Could not remove the model catalog.'
    fi
    rm -rf "$BACKUP_DIR" || die 'Could not remove the backup directory.'
    ok 'Restore complete.'
    warn 'Fully quit and reopen the Codex client for the change to take effect.'
}

printf '\nCodexPicker Setup v%s\nConfig ID: %s\nConfig API: %s\nCodex directory: %s\n' \
    "$SCRIPT_VERSION" "$CONFIG_ID" "$API_ENDPOINT" "$CODEX_DIR"

# Fetch both documents before offering installation.
ENCODED_ID=$(jq -nr --arg value "$CONFIG_ID" '$value | @uri') || die 'Could not encode config ID.'
CONFIG_URL=$API_ENDPOINT/config/$ENCODED_ID
MODELS_URL=$CONFIG_URL/models
API_ERROR=''
printf 'GET %s\n' "$CONFIG_URL"
if ! curl -fsSL --max-time 30 "$CONFIG_URL" -o "$REMOTE_CONFIG"; then
    API_ERROR='Could not read the remote config.'
elif ! jq -e '.provider | type == "object"' "$REMOTE_CONFIG" >/dev/null 2>&1; then
    API_ERROR='Remote config has no provider.'
elif ! jq -e '.provider.name | type == "string" and length > 0' "$REMOTE_CONFIG" >/dev/null 2>&1; then
    API_ERROR='Remote config has no provider.name.'
elif ! jq -e '.provider.api | type == "string" and length > 0' "$REMOTE_CONFIG" >/dev/null 2>&1; then
    API_ERROR='Remote config has no provider.api.'
elif ! jq -e '(.reasoning_efforts // []) | type == "array"' "$REMOTE_CONFIG" >/dev/null 2>&1; then
    API_ERROR='reasoning_efforts must be an array.'
else
    PROVIDER_NAME=$(jq -r '.provider.name' "$REMOTE_CONFIG")
    PROVIDER_API=$(jq -r '.provider.api' "$REMOTE_CONFIG")
    PROVIDER_API=${PROVIDER_API%/}
    if ! printf '%s' "$PROVIDER_API" | jq -R -e 'test("^https?://[^/[:space:]]+")' >/dev/null 2>&1; then
        API_ERROR='provider.api must be a valid http or https URL.'
    fi
    if [ -z "$API_ERROR" ]; then
        printf 'GET %s\n' "$MODELS_URL"
        if ! curl -fsSL --max-time 30 "$MODELS_URL" -o "$REMOTE_MODELS"; then
            API_ERROR='Could not read the model catalog.'
        elif ! jq -e '.models | type == "array" and length > 0' "$REMOTE_MODELS" >/dev/null 2>&1; then
            API_ERROR='The model catalog contains no models.'
        elif ! jq -e '.models[0].slug | type == "string" and length > 0' "$REMOTE_MODELS" >/dev/null 2>&1; then
            API_ERROR='The first model has no slug.'
        elif ! jq -e '(.models[0].supported_reasoning_levels // []) | type == "array"' "$REMOTE_MODELS" >/dev/null 2>&1; then
            API_ERROR='supported_reasoning_levels must be an array.'
        fi
    fi
fi

if [ -n "$API_ERROR" ]; then
    warn "$API_ERROR"
    [ -d "$BACKUP_DIR" ] || die 'API failed and no backup exists. Nothing was modified.'
    printf '0. Restore from backup\n'
    ask 'Choose 0 to restore, anything else to cancel:'
    [ "$ANSWER" = 0 ] || { printf 'Cancelled; nothing was modified.\n'; exit 0; }
    restore; exit 0
fi

MODEL_SLUG=$(jq -r '.models[0].slug' "$REMOTE_MODELS")
MODEL_EFFORT=$(jq -r '[.models[0].supported_reasoning_levels[]?.effort | select(type == "string" and length > 0)][0] // "none"' "$REMOTE_MODELS")
REASONING_EFFORTS=$(jq -r '[.reasoning_efforts[]? | select(type == "string" and length > 0)] | join(",")' "$REMOTE_CONFIG")

printf '\nRemote config: %s (%s)\n' "$PROVIDER_NAME" "$PROVIDER_API"
printf '1. Install this config\n'
[ -d "$BACKUP_DIR" ] && printf '0. Restore from backup\n'
ask 'Choose an option (anything else cancels):'
case $ANSWER in
    1) ;;
    0) [ -d "$BACKUP_DIR" ] || die 'No backup exists.'; restore; exit 0 ;;
    *) printf 'Cancelled; nothing was modified.\n'; exit 0 ;;
esac

ask 'Enter your provider API key:'
API_KEY=$ANSWER
[ -n "$API_KEY" ] || die 'API key cannot be empty.'
case $API_KEY in *'"'*) die 'The API key must not contain double quotes.' ;; esac

# A JSON quoted string is also a TOML basic string; jq handles escaping.
toml_string() { jq -n --arg value "$1" '$value'; }
{
    toml_string "$MODEL_SLUG"; toml_string "$PROVIDER_ID"
    toml_string "$MODEL_EFFORT"; toml_string "$MODELS_PATH"
    toml_string "$PROVIDER_NAME"; toml_string "$PROVIDER_API"
    toml_string 'responses'; toml_string "$API_KEY"
    jq -c '[.reasoning_efforts[]? | select(type == "string" and length > 0)]' "$REMOTE_CONFIG"
} > "$DESIRED_VALUES" || die 'Could not build TOML values.'
chmod 600 "$DESIRED_VALUES"

# Rewrite only CodexPicker fields. Track arrays and multiline strings so their
# contents are not mistaken for section headers or assignments.
cat > "$AWK_SCRIPT" <<'AWK'
function scan(line,    i,c,three) {
    for (i = 1; i <= length(line); i++) {
        c = substr(line, i, 1); three = substr(line, i, 3)
        if (multi != "") {
            if (three == multi) { multi = ""; i += 2 }
            else if (multi == "\"\"\"" && c == "\\") i++
            continue
        }
        if (quoted != "") {
            if (quoted == "\"" && c == "\\") i++
            else if (c == quoted) quoted = ""
            continue
        }
        if (c == "#") break
        if (three == "\"\"\"" || three == "\047\047\047") { multi = three; i += 2; continue }
        if (c == "\"" || c == "\047") { quoted = c; continue }
        if (c == "[") depth++
        else if (c == "]" && depth > 0) depth--
    }
}
function section(line,    s) {
    if (multi != "" || depth != 0 || line !~ /^[[:space:]]*\[\[?[^]]+\]\]?[[:space:]]*(#.*)?$/) return "!"
    s = line; sub(/^[[:space:]]*\[\[?/, "", s)
    sub(/\]\]?[[:space:]]*(#.*)?$/, "", s)
    gsub(/["\047]/, "", s)
    return s
}
function key(line,    s) {
    if (multi != "" || depth != 0 || line !~ /^[[:space:]]*[A-Za-z0-9_"\047-]+[[:space:]]*=/) return ""
    s = line; sub(/=.*/, "", s)
    gsub(/^[[:space:]]+|[[:space:]]+$/, "", s)
    gsub(/["\047]/, "", s)
    return s
}
function missing(kind,    i,k) {
    if (kind == "top") for (i = 1; i <= 4; i++) { k = top_key[i]; if (!seen_top[k]) print k " = " value[i] }
    if (kind == "provider") for (i = 1; i <= 4; i++) { k = provider_key[i]; if (!seen_provider[k]) print k " = " value[i+4] }
    if (kind == "desktop" && !seen_desktop) print desktop_key " = " value[9]
}
BEGIN {
    split(top_keys, top_key, " ")
    split(provider_keys, provider_key, " ")
    for (i = 1; i <= 9; i++) if ((getline value[i] < values_file) <= 0) exit 2
    close(values_file)
    current = ""; top_done = 0; provider_found = 0; desktop_found = 0
}
{
    if (skipping) {
        scan($0)
        if (multi == "" && depth == 0) skipping = 0
        next
    }
    header = section($0)
    if (header != "!") {
        if (!top_done) { missing("top"); top_done = 1 }
        if (current == provider_section) missing("provider")
        if (current == "desktop") missing("desktop")
        current = header
        if (current == provider_section) provider_found = 1
        if (current == "desktop") desktop_found = 1
        print; scan($0); next
    }
    k = key($0)
    if (k != "") {
        if (current == "") for (i = 1; i <= 4; i++) if (k == top_key[i]) {
            if (!seen_top[k]) print k " = " value[i]
            seen_top[k] = 1; scan($0); skipping = (multi != "" || depth != 0); next
        }
        if (current == provider_section) for (i = 1; i <= 4; i++) if (k == provider_key[i]) {
            if (!seen_provider[k]) print k " = " value[i+4]
            seen_provider[k] = 1; scan($0); skipping = (multi != "" || depth != 0); next
        }
        if (current == "desktop" && k == desktop_key) {
            if (!seen_desktop) print k " = " value[9]
            seen_desktop = 1; scan($0); skipping = (multi != "" || depth != 0); next
        }
    }
    print; scan($0)
}
END {
    if (!top_done) missing("top")
    if (current == provider_section) missing("provider")
    if (current == "desktop") missing("desktop")
    if (!provider_found) { print ""; print "[" provider_section "]"; missing("provider") }
    if (!desktop_found) { print ""; print "[desktop]"; missing("desktop") }
}
AWK

if [ -f "$CONFIG_PATH" ]; then
    awk -v values_file="$DESIRED_VALUES" -v top_keys="$TOP_KEYS" -v provider_keys="$PROVIDER_KEYS" -v provider_section="$PROVIDER_SECTION" -v desktop_key="$DESKTOP_KEY" -f "$AWK_SCRIPT" "$CONFIG_PATH" > "$UPDATED_CONFIG" || die 'Could not update config.toml.'
else
    awk -v values_file="$DESIRED_VALUES" -v top_keys="$TOP_KEYS" -v provider_keys="$PROVIDER_KEYS" -v provider_section="$PROVIDER_SECTION" -v desktop_key="$DESKTOP_KEY" -f "$AWK_SCRIPT" /dev/null > "$UPDATED_CONFIG" || die 'Could not create config.toml.'
fi

# Preserve the first pre-CodexPicker snapshot while switching config IDs.
if [ ! -e "$BACKUP_DIR" ]; then
    mkdir "$BACKUP_DIR" || die 'Could not create the backup directory.'
    if [ -f "$CONFIG_PATH" ]; then
        cp "$CONFIG_PATH" "$BACKUP_CONFIG" || die 'Could not back up config.toml.'
        ORIGINAL_CONFIG_EXISTED=1
    else
        ORIGINAL_CONFIG_EXISTED=0
    fi
    if [ -f "$MODELS_PATH" ]; then
        cp "$MODELS_PATH" "$BACKUP_MODELS" || die 'Could not back up the model catalog.'
        ORIGINAL_CATALOG_EXISTED=1
    else
        ORIGINAL_CATALOG_EXISTED=0
    fi
else
    [ -d "$BACKUP_DIR" ] || die 'The backup path is not a directory.'
    if [ -f "$MANIFEST" ] && grep -q '^original_config_existed=0$' "$MANIFEST"; then
        ORIGINAL_CONFIG_EXISTED=0
    else
        ORIGINAL_CONFIG_EXISTED=1
    fi
    if [ -f "$MANIFEST" ] && grep -q '^original_catalog_existed=1$' "$MANIFEST"; then
        ORIGINAL_CATALOG_EXISTED=1
    else
        ORIGINAL_CATALOG_EXISTED=0
    fi
    ok "Existing backup preserved: $BACKUP_DIR"
fi

cp "$REMOTE_MODELS" "$MODELS_PATH.codexpicker-tmp" || die 'Could not save the model catalog.'
mv -f "$MODELS_PATH.codexpicker-tmp" "$MODELS_PATH" || die 'Could not replace the model catalog.'
cp "$UPDATED_CONFIG" "$CONFIG_PATH.codexpicker-tmp" || die 'Could not save config.toml.'
mv -f "$CONFIG_PATH.codexpicker-tmp" "$CONFIG_PATH" || die 'Could not replace config.toml.'
{
    printf 'script_version=%s\nconfig_id=%s\napi_endpoint=%s\n' "$SCRIPT_VERSION" "$CONFIG_ID" "$API_ENDPOINT"
    printf 'installed_at=%s\noriginal_config_existed=%s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$ORIGINAL_CONFIG_EXISTED"
    printf 'original_catalog_existed=%s\n' "$ORIGINAL_CATALOG_EXISTED"
    printf 'provider_name=%s\nprovider_id=%s\nprovider_api=%s\n' "$PROVIDER_NAME" "$PROVIDER_ID" "$PROVIDER_API"
    printf 'catalog_path=%s\nreasoning_efforts=%s\n' "$MODELS_PATH" "$REASONING_EFFORTS"
} > "$MANIFEST.codexpicker-tmp" || die 'Could not write the backup manifest.'
mv -f "$MANIFEST.codexpicker-tmp" "$MANIFEST" || die 'Could not replace the backup manifest.'
ok "Updated: $CONFIG_PATH"
ok "Catalog saved: $MODELS_PATH"
ok "Model: $MODEL_SLUG - Reasoning effort: $MODEL_EFFORT"
ok "Provider: $PROVIDER_NAME ($PROVIDER_API)"
ok 'Installation complete.'
warn 'Fully quit and reopen the Codex client for the change to take effect.'
