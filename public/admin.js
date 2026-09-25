(() => {
  const $ = id => document.getElementById(id);
  const key = new URLSearchParams(location.search).get('key') || '';
  const ORD = ['1st', '2nd', '3rd'];
  const openState = new Set(); // which strategy sections the organiser has expanded

  function toast(msg) {
    const t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), 2400);
  }

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  async function api(path, body) {
    const res = await fetch(path, {
      method: body ? 'POST' : 'GET',
      headers: { 'X-Admin-Key': key, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  function render(d) {
    $('panel').hidden = false;
    $('total').textContent = d.responses;
    $('live').classList.toggle('off', !d.open);
    $('live-text').textContent = d.open ? 'Voting open' : 'Voting closed';
    $('toggle').textContent = d.open ? 'Close voting' : 'Reopen voting';
    $('csv').href = '/api/admin/export.csv?key=' + encodeURIComponent(key);

    const rows = [...d.rows].sort((a, b) => b.points - a.points || b.ranks[0] - a.ranks[0]);
    const body = $('scores');
    body.textContent = '';
    for (const r of rows) {
      const tr = el('tr');
      tr.append(el('td', null, r.name), el('td', null, r.points), ...r.ranks.map(n => el('td', null, n)));
      body.append(tr);
    }

    // Explanations grouped by strategy, most points first.
    const byName = new Map(rows.map(r => [r.name, []]));
    for (const resp of d.responsesList) {
      resp.ranking.forEach((x, i) => { if (x.why) byName.get(x.name)?.push({ rank: i, why: x.why }); });
    }
    const box = $('whys');
    box.textContent = '';
    for (const r of rows) {
      const items = byName.get(r.name).sort((a, b) => a.rank - b.rank);
      const det = el('details', 'strategy');
      det.open = openState.has(r.name);
      det.addEventListener('toggle', () => { det.open ? openState.add(r.name) : openState.delete(r.name); });
      const sum = el('summary', null, r.name);
      sum.append(el('span', null, `${items.length} explanation${items.length === 1 ? '' : 's'}`));
      det.append(sum);
      if (!items.length) det.append(el('p', 'none', 'None yet.'));
      else {
        const ul = el('ul', 'quotes');
        for (const it of items) {
          const li = el('li');
          li.append(el('b', null, `Ranked ${ORD[it.rank]}`), document.createTextNode(it.why));
          ul.append(li);
        }
        det.append(ul);
      }
      box.append(det);
    }
  }

  async function refresh() {
    try {
      render(await api('/api/admin/info'));
      $('err').hidden = true;
    } catch (e) {
      $('err').hidden = false;
      $('err').textContent = key ? e.message : 'Open this page using the admin link printed when the server starts.';
    }
  }

  $('toggle').onclick = async () => {
    render(await api('/api/admin/toggle', {}));
    toast($('live-text').textContent);
  };
  $('reset').onclick = async () => {
    if (!confirm('Delete ALL responses and explanations? This cannot be undone.')) return;
    if (prompt('Type RESET to confirm') !== 'RESET') return;
    render(await api('/api/admin/reset', {}));
    toast('All responses cleared');
  };

  refresh();
  setInterval(refresh, 5000);
})();
