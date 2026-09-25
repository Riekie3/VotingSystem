(() => {
  const $ = id => document.getElementById(id);
  const key = new URLSearchParams(location.search).get('key') || '';

  function toast(msg) {
    const t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), 2400);
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

  function qrImg(url) {
    if (!window.qrcode) return null;
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    const img = document.createElement('img');
    img.src = qr.createDataURL(4, 0);
    img.alt = 'QR code for special ballot link';
    return img;
  }

  function render(d) {
    $('panel').hidden = false;
    $('total').textContent = d.total;
    $('voters').textContent = d.voters;
    $('live').classList.toggle('off', !d.open);
    $('live-text').textContent = d.open ? 'Voting open' : 'Voting closed';
    $('toggle').textContent = d.open ? 'Close voting' : 'Reopen voting';
    $('special-note').textContent = `${d.votesPerSpecialBallot} votes each`;

    const body = $('special');
    body.textContent = '';
    d.special.forEach((s, i) => {
      const url = `${location.origin}/?k=${s.code}`;
      const tr = document.createElement('tr');

      const tdQr = document.createElement('td');
      const box = document.createElement('div');
      box.className = 'mini-qr';
      const img = qrImg(url);
      if (img) box.append(img);
      tdQr.append(box);

      const tdLink = document.createElement('td');
      const strong = document.createElement('strong');
      strong.textContent = `Special ballot ${i + 1}`;
      const link = document.createElement('div');
      link.className = 'linkbox';
      link.textContent = url;
      const copy = document.createElement('button');
      copy.className = 'btn small ghost';
      copy.style.marginTop = '6px';
      copy.textContent = 'Copy link';
      copy.onclick = async () => {
        try { await navigator.clipboard.writeText(url); toast('Link copied'); }
        catch { toast('Copy failed — select the link manually'); }
      };
      tdLink.append(strong, link, copy);

      const tdStatus = document.createElement('td');
      const pill = document.createElement('span');
      pill.className = 'pill' + (s.claimed ? ' ok' : '');
      pill.textContent = s.claimed ? `Claimed · ${s.used}/${d.votesPerSpecialBallot} used` : 'Not opened yet';
      tdStatus.append(pill);

      const tdAct = document.createElement('td');
      if (s.claimed) {
        const rel = document.createElement('button');
        rel.className = 'btn small ghost';
        rel.textContent = 'Release';
        rel.title = 'Let the link be claimed again, e.g. if the person switches phone';
        rel.onclick = async () => {
          if (!confirm('Release this link so it can be opened on a different device?')) return;
          render(await api('/api/admin/release', { code: s.code }));
          toast('Link released');
        };
        tdAct.append(rel);
      }
      tr.append(tdQr, tdLink, tdStatus, tdAct);
      body.append(tr);
    });
  }

  // Reload when the page files change, so an open admin tab gets updates.
  let build = null;

  async function refresh() {
    try {
      const d = await api('/api/admin/info');
      if (d.build && build && d.build !== build) return location.reload();
      build = build || d.build;
      render(d);
      const local = /^(localhost|127\.|\[::1\])/.test(location.hostname);
      $('err').hidden = !local;
      $('err').textContent = local
        ? 'You opened this page on localhost, so the links below only work on this computer. Open the admin page through the public (trycloudflare.com) address before sharing links or QR codes.'
        : '';
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
    if (!confirm('Reset ALL votes? This cannot be undone.')) return;
    if (prompt('Type RESET to confirm') !== 'RESET') return;
    render(await api('/api/admin/reset', {}));
    toast('All votes cleared');
  };

  refresh();
  setInterval(refresh, 5000);
})();
