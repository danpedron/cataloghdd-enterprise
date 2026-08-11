#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:?Uso: smoke-test.sh https://catalog.example.com/catalog/ /caminho/credencial-admin}"
CREDENTIAL_FILE="${2:?Informe o caminho do arquivo de credencial de teste.}"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

curl -sSc "$WORK_DIR/cookies.txt" "${BASE_URL}?r=login" > "$WORK_DIR/login.html"
CSRF="$(sed -nE 's/.*name="csrf" value="([a-f0-9]+)".*/\1/p' "$WORK_DIR/login.html" | head -n 1)"
PASSWORD="$(sed -n 's/^password=//p' "$CREDENTIAL_FILE")"

if [[ ${#CSRF} -ne 64 || -z "$PASSWORD" ]]; then
  echo "Falha ao preparar o teste de login." >&2
  exit 1
fi

STATUS="$(curl -sS -b "$WORK_DIR/cookies.txt" -c "$WORK_DIR/cookies.txt" -o /dev/null -w '%{http_code}' \
  -X POST --data-urlencode "csrf=$CSRF" --data-urlencode 'username=admin' --data-urlencode "password=$PASSWORD" \
  "${BASE_URL}?r=login")"

if [[ "$STATUS" != "303" ]]; then
  echo "Login retornou HTTP $STATUS." >&2
  exit 1
fi

DASHBOARD_STATUS="$(curl -sS -b "$WORK_DIR/cookies.txt" -o /dev/null -w '%{http_code}' "${BASE_URL}?r=dashboard")"
if [[ "$DASHBOARD_STATUS" != "303" ]]; then
  echo "A política de troca de senha não redirecionou corretamente (HTTP $DASHBOARD_STATUS)." >&2
  exit 1
fi
curl -sS -b "$WORK_DIR/cookies.txt" "${BASE_URL}?r=profile" > "$WORK_DIR/profile.html"
grep -q 'Atualizar senha' "$WORK_DIR/profile.html"

HEAD_STATUS="$(curl -sSI -o /dev/null -w '%{http_code}' "$BASE_URL")"
if [[ "$HEAD_STATUS" != "200" && "$HEAD_STATUS" != "303" ]]; then
  echo "HEAD retornou HTTP $HEAD_STATUS." >&2
  exit 1
fi

echo "Teste de fumaça aprovado."
