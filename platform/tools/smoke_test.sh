#!/usr/bin/env bash
# End-to-end smoke test against a running install.
# Usage: ADMIN_USER=admin ADMIN_PASS=... BASE=http://localhost:8995 bash tools/smoke_test.sh
# Creates polls named "zz-test-*" and moves them to the Trash at the end.
set -u
BASE=${BASE:-http://localhost:8995}
T=$(mktemp -d)
ADMIN=$T/admin.jar
pass=0; fail=0
ok()   { pass=$((pass+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  \033[31m✗ %s\033[0m\n' "$1"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }
json() { node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{try{const d=JSON.parse(s);console.log(eval(process.argv[1]))}catch(e){console.log('ERR')}})" "$1"; }
csrf() { curl -s -b $ADMIN -c $ADMIN "$BASE/$1" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
voter(){ echo "$T/voter-$1.jar"; }
vget() { curl -s -b "$(voter $1)" -c "$(voter $1)" "$BASE/$2"; }
vpost(){ curl -s -b "$(voter $1)" -c "$(voter $1)" -H 'Content-Type: application/json' -d "$3" "$BASE/$2"; }

echo "== Admin login"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin")
check "admin redirects to login when logged out ($code)" '[ "$code" = 302 ]'
C=$(csrf admin/login)
code=$(curl -s -o /dev/null -w '%{http_code}' -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" --data-urlencode "username=$ADMIN_USER" --data-urlencode "password=wrong-password" "$BASE/admin/login")
check "wrong password is rejected" '[ "$code" = 200 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" --data-urlencode "username=$ADMIN_USER" --data-urlencode "password=$ADMIN_PASS" "$BASE/admin/login")
check "correct password logs in ($code)" '[ "$code" = 302 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -b $ADMIN "$BASE/admin")
check "dashboard loads" '[ "$code" = 200 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -b $ADMIN -d "title=x" "$BASE/admin/polls/new")
check "POST without CSRF token is refused ($code)" '[ "$code" = 403 ]'

new_poll() { # type title options -> echoes poll id
  local C; C=$(csrf admin/polls/new)
  curl -s -o /dev/null -w '%{redirect_url}' -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" --data-urlencode "type=$1" \
    --data-urlencode "title=$2" --data-urlencode "options=$3" "$BASE/admin/polls/new" | grep -o '[0-9]*$'
}
save_poll() { # id slug status extra-args...
  local id=$1 slug=$2 status=$3; shift 3
  local page C; page=$(curl -s -b $ADMIN -c $ADMIN "$BASE/admin/polls/$id")
  C=$(echo "$page" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
  local args=(); local i=0
  while IFS='|' read -r oid label; do
    args+=(--data-urlencode "opt_id[$i]=$oid" --data-urlencode "opt_label[$i]=$label" --data-urlencode "opt_desc[$i]=" --data-urlencode "opt_icon_type[$i]=initials" --data-urlencode "opt_icon_value[$i]=" --data-urlencode "opt_hidden[$i]=0")
    i=$((i+1))
  done < <(echo "$page" | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{const ids=[...s.matchAll(/name=\"opt_id\[\]\" value=\"(\d*)\"/g)].map(m=>m[1]);const labels=[...s.matchAll(/name=\"opt_label\[\]\" value=\"([^\"]*)\"/g)].map(m=>m[1]);ids.forEach((id,i)=>{if(id)console.log(id+'|'+labels[i])})})")
  curl -s -o /dev/null -w '%{http_code}' -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" --data-urlencode "slug=$slug" --data-urlencode "status=$status" \
    --data-urlencode "title=$(echo "$page" | grep -o 'name="title" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')" "${args[@]}" "$@" "$BASE/admin/polls/$id"
}

echo "== Single-choice poll + special 3-vote codes"
P1=$(new_poll single "ZZ Test Leader" $'-JUSTIN\n-JADE\n• CHRIS\n4) KK')
check "poll created (#$P1)" '[ -n "$P1" ]'
save_poll "$P1" zz-test-leader open --data-urlencode "type=single" --data-urlencode "votes_per_device=1" --data-urlencode "access=open" \
  --data-urlencode "results_visibility=always" --data-urlencode "top_n=5" --data-urlencode "answers_wall=1" --data-urlencode "comment_mode=off" >/dev/null
cfg=$(vget a api/p/zz-test-leader)
check "public config: 4 options, list bullets cleaned" '[ "$(echo "$cfg" | json "d.poll.options.map(o=>o.label).join(\",\")")" = "JUSTIN,JADE,CHRIS,KK" ]'
check "voter has 1 vote" '[ "$(echo "$cfg" | json "d.me.remaining")" = 1 ]'
O1=$(echo "$cfg" | json "d.poll.options[0].id"); O2=$(echo "$cfg" | json "d.poll.options[1].id"); O3=$(echo "$cfg" | json "d.poll.options[2].id")
r=$(vpost a api/p/zz-test-leader/vote "{\"picks\":[{\"option_id\":$O1}]}")
check "vote accepted" '[ "$(echo "$r" | json "d.ok")" = true ]'
r=$(vpost a api/p/zz-test-leader/vote "{\"picks\":[{\"option_id\":$O2}]}")
check "second vote from same device refused" '[ "$(echo "$r" | json "d.ok")" != true ]'
r=$(vpost b api/p/zz-test-leader/vote "{\"picks\":[{\"option_id\":$O1},{\"option_id\":$O2}]}")
check "normal voter cannot submit 2 picks" '[ "$(echo "$r" | json "d.ok")" != true ]'
r=$(vpost b api/p/zz-test-leader/vote '{"picks":[{"option_id":999999}]}')
check "unknown option refused" '[ "$(echo "$r" | json "d.ok")" != true ]'
C=$(csrf "admin/polls/$P1/codes")
curl -s -o /dev/null -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" -d action=generate -d count=2 -d votes=3 --data-urlencode "label=Special ballot" "$BASE/admin/polls/$P1/codes"
CODE=$(curl -s -b $ADMIN "$BASE/admin/polls/$P1/codes" | grep -o '?k=[A-Z0-9]*' | head -1 | cut -c4-)
check "access code generated ($CODE)" '[ ${#CODE} = 8 ]'
me=$(vget s "api/p/zz-test-leader?k=$CODE")
check "code gives 3 votes" '[ "$(echo "$me" | json "d.me.remaining")" = 3 ]'
r=$(vpost s api/p/zz-test-leader/vote "{\"picks\":[{\"option_id\":$O1},{\"option_id\":$O2},{\"option_id\":$O3}]}")
check "special ballot submits 3 picks at once" '[ "$(echo "$r" | json "d.me.remaining")" = 0 ]'
me=$(vget other "api/p/zz-test-leader?k=$CODE")
check "used code refused on another device" '[ "$(echo "$me" | json "d.me.remaining")" = 1 ] && echo "$me" | grep -q "already been used"'
res=$(vget a "api/p/zz-test-leader/results?full=1")
check "results: 4 responses, JUSTIN leads with 2" '[ "$(echo "$res" | json "d.results.responses+\"|\"+d.results.rows[0].label+\"|\"+d.results.rows[0].score")" = "4|JUSTIN|2" ]'
v=$(echo "$res" | json "d.live.version")
same=$(vget a "api/p/zz-test-leader/results?v=$v")
check "unchanged poll gets tiny 'same' reply" '[ "$(echo "$same" | json "d.same")" = true ]'

echo "== Ranked poll with required comments"
P2=$(new_poll ranked "ZZ Test Strategies" $'AnV\nCES\nEnE via AI\nBe Agile\nUrgency')
save_poll "$P2" zz-test-rank open --data-urlencode "type=ranked" --data-urlencode "picks=3" --data-urlencode "points=3,2,1" \
  --data-urlencode "comment_mode=required" --data-urlencode "comment_label=How will you apply it?" --data-urlencode "votes_per_device=1" \
  --data-urlencode "access=open" --data-urlencode "results_visibility=after_vote" --data-urlencode "top_n=5" --data-urlencode "answers_wall=1" >/dev/null
cfg=$(vget a api/p/zz-test-rank)
R=($(echo "$cfg" | json "d.poll.options.map(o=>o.id).join(' ')"))
res=$(vget a "api/p/zz-test-rank/results?full=1")
check "results hidden before voting (after_vote)" '[ "$(echo "$res" | json "d.results")" = null ]'
r=$(vpost a api/p/zz-test-rank/vote "{\"picks\":[{\"option_id\":${R[0]},\"comment\":\"x\"},{\"option_id\":${R[1]},\"comment\":\"y\"}]}")
check "ranked needs exactly 3" '[ "$(echo "$r" | json "d.ok")" != true ]'
r=$(vpost a api/p/zz-test-rank/vote "{\"picks\":[{\"option_id\":${R[0]},\"comment\":\"a\"},{\"option_id\":${R[1]},\"comment\":\" \"},{\"option_id\":${R[2]},\"comment\":\"c\"}]}")
check "blank required comment refused" '[ "$(echo "$r" | json "d.ok")" != true ]'
r=$(vpost a api/p/zz-test-rank/vote "{\"picks\":[{\"option_id\":${R[3]},\"comment\":\"Weekly retro\"},{\"option_id\":${R[4]},\"comment\":\"Same-day replies\"},{\"option_id\":${R[0]},\"comment\":\"Monthly review\"}]}")
check "valid ranked ballot accepted" '[ "$(echo "$r" | json "d.ok")" = true ]'
r=$(vpost b api/p/zz-test-rank/vote "{\"picks\":[{\"option_id\":${R[4]},\"comment\":\"Do it now\"},{\"option_id\":${R[3]},\"comment\":\"Pilot small\"},{\"option_id\":${R[1]},\"comment\":\"<script>alert(1)</script>\"}]}")
res=$(vget a "api/p/zz-test-rank/results?full=1")
check "points: Be Agile 3+2=5 and Urgency 2+3=5, tie broken by order" '[ "$(echo "$res" | json "d.results.rows.slice(0,2).map(r=>r.label+\":\"+r.score).join(\",\")")" = "Be Agile:5,Urgency:5" ]'
ans=$(vget a api/p/zz-test-rank/answers)
check "answers wall returns grouped comments" '[ "$(echo "$ans" | json "d.groups.length")" -ge 3 ]'

echo "== Reveal mode"
C=$(csrf "admin/polls/$P2")
curl -s -o /dev/null -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" -d action=reveal_hide "$BASE/admin/polls/$P2/action"
res=$(vget x "api/p/zz-test-rank/results?view=screen&full=1")
check "projector gets no results while hidden" '[ "$(echo "$res" | json "d.results")" = null ] && [ "$(echo "$res" | json "d.responses")" = 2 ]'
curl -s -o /dev/null -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" -d action=reveal_show "$BASE/admin/polls/$P2/action"
res=$(vget x "api/p/zz-test-rank/results?view=screen&full=1")
check "projector gets results after reveal" '[ "$(echo "$res" | json "d.live.reveal+\"|\"+d.results.rows.length")" = "revealed|5" ]'

echo "== Exports, report, backup"
curl -s -b $ADMIN -o $T/r.csv "$BASE/admin/polls/$P2/export.csv"
check "CSV export has both responses" 'grep -q "Weekly retro" $T/r.csv && grep -q "Pilot small" $T/r.csv'
curl -s -b $ADMIN -o $T/r.xlsx "$BASE/admin/polls/$P2/export.xlsx"
check "Excel export is a valid .xlsx zip" 'unzip -l $T/r.xlsx 2>/dev/null | grep -q "xl/worksheets/sheet2.xml"'
curl -s -b $ADMIN -o $T/report.html "$BASE/admin/polls/$P2/report"
check "report escapes HTML in comments" 'grep -q "&lt;script&gt;alert(1)&lt;/script&gt;" $T/report.html && ! grep -q "<script>alert(1)" $T/report.html'
curl -s -b $ADMIN -o $T/backup.zip "$BASE/admin/backup"
check "backup zip contains database.sql" 'unzip -l $T/backup.zip 2>/dev/null | grep -q database.sql'

echo "== Duplicate, schedule, trash"
C=$(csrf "admin/polls/$P1")
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" -d action=duplicate "$BASE/admin/polls/$P1/action")
P3=$(echo "$loc" | grep -o '[0-9]*$')
check "duplicate creates a new draft (#$P3)" '[ -n "$P3" ] && [ "$P3" != "$P1" ]'
save_poll "$P3" zz-test-scheduled open --data-urlencode "type=single" --data-urlencode "opens_at=2099-01-01T09:00" --data-urlencode "votes_per_device=1" --data-urlencode "top_n=5" --data-urlencode "results_visibility=always" >/dev/null
cfg=$(vget a api/p/zz-test-scheduled)
check "future opening time => scheduled" '[ "$(echo "$cfg" | json "d.live.state")" = scheduled ]'
O=$(echo "$cfg" | json "d.poll.options[0].id")
r=$(vpost a api/p/zz-test-scheduled/vote "{\"picks\":[{\"option_id\":$O}]}")
check "voting refused before opening" '[ "$(echo "$r" | json "d.ok")" != true ]'
for id in $P1 $P2 $P3; do C=$(csrf "admin/polls/$id"); curl -s -o /dev/null -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" -d action=trash "$BASE/admin/polls/$id/action"; done
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/zz-test-leader")
check "trashed poll is gone from the public ($code)" '[ "$code" = 404 ]'
check "trash lists them" 'curl -s -b $ADMIN "$BASE/admin/trash" | grep -q "ZZ Test Leader"'
for id in $P1 $P2 $P3; do C=$(csrf admin/trash); curl -s -o /dev/null -b $ADMIN -c $ADMIN --data-urlencode "_csrf=$C" -d action=destroy "$BASE/admin/polls/$id/action"; done
check "delete forever removes them" '! curl -s -b $ADMIN "$BASE/admin/trash" | grep -q "ZZ Test"'

echo "== Security"
for p in config.php src/core.php sql/schema.sql storage/ LOCAL-CREDENTIALS.md; do
  code=$(curl -s -o $T/sec -w '%{http_code}' "$BASE/$p")
  # Must not leak DB credentials, SQL, PHP source or the local login.
  check "/$p is not served ($code)" '! grep -qE "=> .?pass|CREATE TABLE|<\?php|^Password:|function config" $T/sec'
done

echo; echo "Passed: $pass   Failed: $fail"
rm -rf "$T"
[ "$fail" = 0 ]
