/* Admin panel enhancements. Everything still works as plain forms; this adds comfort. */
(() => {
  const { $, el, toast } = window.VSC;
  const A = window.VSA;
  const qsa = (s, r = document) => [...r.querySelectorAll(s)];

  async function post(url, body, isForm = false) {
    const res = await fetch(url, {
      method: 'POST',
      headers: isForm ? { 'X-CSRF': A.csrf } : { 'X-CSRF': A.csrf, 'Content-Type': 'application/json' },
      body: isForm ? body : JSON.stringify(body),
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function copy(text, msg = 'Copied') {
    try { await navigator.clipboard.writeText(text); toast(msg); }
    catch { prompt('Copy this:', text); }
  }

  // ---------- confirmations on risky buttons ----------
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-confirm]');
    if (!b) return;
    if (!confirm(b.dataset.confirm)) { e.preventDefault(); return; }
    const word = b.dataset.confirmType;
    if (word && (prompt(`Type ${word} to confirm`) || '').trim().toUpperCase() !== word) { e.preventDefault(); toast('Cancelled'); }
  }, true);

  // ---------- share helpers ----------
  document.addEventListener('click', e => {
    const c = e.target.closest('[data-copy]');
    if (c) copy(c.dataset.copy, 'Link copied');
    const w = e.target.closest('[data-whatsapp]');
    if (w) copy(`🗳️ *${w.dataset.title}*\n\nVote here 👇\n${w.dataset.url}\n\n✅ Completely anonymous\n✅ No login needed`, 'WhatsApp message copied — paste it into the chat');
    const wc = e.target.closest('[data-whatsapp-code]');
    if (wc) {
      const n = Number(wc.dataset.votes);
      copy(`Hi! You have a *special ballot${n > 1 ? ` with ${n} votes` : ''}* for: ${wc.dataset.title} 🗳️\n\nVote here 👇\n${wc.dataset.url}\n\n⚠️ This link is only for you. It works on the first phone that opens it, so please don't forward it.`, 'WhatsApp message copied');
    }
    const q = e.target.closest('[data-qr-download]');
    if (q && window.qrcode) {
      const qr = qrcode(0, 'M'); qr.addData(q.dataset.qrDownload); qr.make();
      const a = el('a'); a.href = qr.createDataURL(12, 4); a.download = `qr-${q.dataset.name || 'vote'}.gif`; a.click();
      toast('QR code downloaded');
    }
  });
  if (window.qrcode) qsa('[data-qr]').forEach(n => {
    const qr = qrcode(0, 'M'); qr.addData(n.dataset.qr); qr.make();
    n.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true });
  });

  // ---------- media upload (logo, favicon, option images) ----------
  async function upload(file) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('_csrf', A.csrf);
    return post(A.upload, fd, true);
  }
  qsa('[data-media]').forEach(box => {
    const input = box.querySelector('input[type=hidden]');
    const thumb = box.querySelector('.thumb');
    box.querySelector('input[type=file]').addEventListener('change', async e => {
      const f = e.target.files[0];
      if (!f) return;
      try {
        const m = await upload(f);
        input.value = m.id;
        thumb.innerHTML = '';
        const img = el('img'); img.src = m.url; img.alt = ''; thumb.append(img);
        markDirty();
        toast('Uploaded — remember to save');
      } catch (err) { toast(err.message); }
      e.target.value = '';
    });
    box.querySelector('[data-media-clear]')?.addEventListener('click', () => {
      input.value = '';
      thumb.innerHTML = '<small>Default</small>';
      markDirty();
    });
  });

  // ---------- poll editor ----------
  const form = $('poll-form');
  let dirty = false;
  function markDirty() { dirty = true; }
  if (form) {
    form.addEventListener('input', markDirty);
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    // Show fields relevant to the chosen type.
    const typeSel = form.querySelector('[data-type-select]');
    const syncType = () => {
      qsa('[data-show-for]', form).forEach(f => { f.hidden = !f.dataset.showFor.split(' ').includes(typeSel.value); });
      const lbl = form.querySelector('[data-picks-label]');
      if (lbl) lbl.textContent = typeSel.value === 'ranked' ? 'How many to rank' : 'Maximum picks';
    };
    typeSel.addEventListener('change', syncType);
    syncType();
    const slug = form.querySelector('[name=slug]');
    slug.addEventListener('input', () => {
      slug.value = slug.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-');
      form.querySelector('[data-slug-preview]').textContent = slug.value;
    });

    const rows = $('opt-rows');
    const tpl = $('opt-template');
    const addRow = (label = '') => {
      const node = tpl.content.firstElementChild.cloneNode(true);
      node.querySelector('[name="opt_label[]"]').value = label;
      rows.append(node);
      bindRow(node);
      markDirty();
      return node;
    };
    $('add-option').addEventListener('click', () => addRow().querySelector('[name="opt_label[]"]').focus());
    $('bulk-add').addEventListener('click', () => { $('bulk-box').hidden = !$('bulk-box').hidden; $('bulk-text').focus(); });
    $('bulk-apply').addEventListener('click', () => {
      const lines = $('bulk-text').value.split(/\r?\n/)
        .map(s => s.replace(/^\s*(?:[-*•]+\s*|\d+\s*[.)\-:]\s*|\d+\s+)/u, '').trim()).filter(Boolean);
      lines.forEach(l => addRow(l));
      $('bulk-text').value = '';
      $('bulk-box').hidden = true;
      toast(`Added ${lines.length} option${lines.length === 1 ? '' : 's'} — remember to save`);
    });

    function refreshInitials(row) {
      const type = row.querySelector('[name="opt_icon_type[]"]').value;
      if (type !== 'initials') return;
      const label = row.querySelector('[name="opt_label[]"]').value;
      const parts = label.replace(/^(dr|mr|mrs|ms|miss|prof|dato|datuk|tan sri)\.?\s+/i, '').trim().split(/\s+/).filter(Boolean);
      const txt = !parts.length ? '?' : parts.length === 1 ? parts[0].slice(0, 2).toUpperCase() : (parts[0][0] + parts[1][0]).toUpperCase();
      row.querySelector('.icon-pick').innerHTML = `<span class="avatar">${txt.replace(/[<>&]/g, '')}</span>`;
    }

    const EMOJI = ['⭐', '🏆', '🥇', '💡', '🚀', '🎯', '❤️', '🔥', '👏', '🙌', '💪', '🧠', '📈', '🤝', '🌱', '⚡', '🎉', '✅', '🐾', '🐶', '🐱', '🍀', '🌟', '👑'];
    function openIconPicker(row) {
      qsa('.icon-popover').forEach(p => p.remove());
      const typeIn = row.querySelector('[name="opt_icon_type[]"]');
      const valIn = row.querySelector('[name="opt_icon_value[]"]');
      const pop = el('div', 'icon-popover');
      const set = (type, value, html) => {
        typeIn.value = type; valIn.value = value;
        row.querySelector('.icon-pick').innerHTML = html;
        if (type === 'initials') refreshInitials(row);
        pop.remove(); markDirty();
      };
      const btns = el('div');
      btns.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap';
      const b1 = el('button', 'btn tiny ghost', 'Aa  Initials'); b1.type = 'button'; b1.onclick = () => set('initials', '', '');
      const b2 = el('button', 'btn tiny ghost', 'No icon'); b2.type = 'button';
      b2.onclick = () => set('none', '', '<span class="avatar" style="background:var(--surface);color:var(--muted)">—</span>');
      const up = el('label', 'btn tiny ghost', '🖼 Upload photo…');
      const file = el('input'); file.type = 'file'; file.accept = 'image/*'; file.hidden = true;
      file.onchange = async () => {
        if (!file.files[0]) return;
        try { const m = await upload(file.files[0]); set('image', String(m.id), `<span class="avatar image"><img src="${m.url}" alt=""></span>`); }
        catch (err) { toast(err.message); }
      };
      up.append(file);
      btns.append(b1, up, b2);
      const grid = el('div', 'emoji-grid');
      EMOJI.forEach(em => { const b = el('button', null, em); b.type = 'button'; b.onclick = () => set('emoji', em, `<span class="avatar emoji">${em}</span>`); grid.append(b); });
      const custom = el('input', 'input');
      custom.placeholder = 'Or type / paste any emoji and press Enter';
      custom.style.fontSize = '15px';
      custom.onkeydown = ev => {
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        const v = custom.value.trim().slice(0, 16);
        if (v) set('emoji', v, `<span class="avatar emoji">${v.replace(/[<>&]/g, '')}</span>`);
      };
      pop.append(btns, grid, custom);
      row.querySelector('.inputs').append(pop);
    }

    function bindRow(row) {
      row.querySelector('[data-icon-pick]').addEventListener('click', () => openIconPicker(row));
      row.querySelector('[name="opt_label[]"]').addEventListener('input', () => refreshInitials(row));
      row.querySelector('[data-hide-toggle]').addEventListener('click', e => {
        const h = row.querySelector('[name="opt_hidden[]"]');
        h.value = h.value === '1' ? '0' : '1';
        row.classList.toggle('hidden-opt', h.value === '1');
        e.target.textContent = h.value === '1' ? 'Show' : 'Hide';
        markDirty();
      });
      row.querySelector('[data-remove]').addEventListener('click', () => {
        const votes = row.querySelector('.votes');
        if (votes && !confirm('This option already has votes. It will be hidden (not deleted) so results stay correct. Continue?')) return;
        row.remove();
        markDirty();
      });
      row.addEventListener('dragstart', e => { row.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
      row.addEventListener('dragend', () => { row.classList.remove('dragging'); markDirty(); });
    }
    qsa('.opt-row', rows).forEach(bindRow);
    rows.addEventListener('dragover', e => {
      e.preventDefault();
      const dragging = rows.querySelector('.dragging');
      if (!dragging) return;
      const after = qsa('.opt-row:not(.dragging)', rows).find(r => e.clientY < r.getBoundingClientRect().top + r.offsetHeight / 2);
      rows.insertBefore(dragging, after || null);
    });
    document.addEventListener('click', e => {
      if (!e.target.closest('.icon-popover') && !e.target.closest('[data-icon-pick]')) qsa('.icon-popover').forEach(p => p.remove());
    });
  }

  // ---------- previews ----------
  function fitScreen() {
    qsa('.screen-frame').forEach(f => {
      const ifr = f.querySelector('iframe');
      ifr.style.transform = `scale(${f.clientWidth / 1280})`;
    });
  }
  fitScreen();
  addEventListener('resize', fitScreen);
  $('reload-preview')?.addEventListener('click', () => qsa('.preview iframe').forEach(f => { f.src = f.src; }));

  // ---------- results page: live numbers + moderation ----------
  const live = document.querySelector('[data-live-results]');
  if (live) {
    const id = live.dataset.liveResults;
    let lastComments = Number(document.querySelector('[data-stat="comments"]').textContent);
    const tick = async () => {
      if (document.hidden) return;
      const r = await fetch(`${A.base}admin/api/live/${id}`, { credentials: 'same-origin' }).then(x => x.json()).catch(() => null);
      if (!r || !r.results) return;
      window.VSC.tween(document.querySelector('[data-stat="responses"]'), r.results.responses);
      const n = r.comments.reduce((s, g) => s + g.items.length, 0);
      window.VSC.tween(document.querySelector('[data-stat="comments"]'), n);
      const body = document.querySelector('#results-table tbody');
      const ranked = r.results.unit === 'pts';
      body.innerHTML = '';
      for (const row of r.results.rows) {
        const tr = el('tr');
        const cells = [[row.rank, 'num'], [row.label], [row.score, 'num', true], ...(ranked ? row.ranks.map(x => [x, 'num']) : [[row.pct + '%', 'num']])];
        for (const [v, cls, bold] of cells) { const td = el('td', cls || null); if (bold) td.append(el('b', null, String(v))); else td.textContent = v; tr.append(td); }
        body.append(tr);
      }
      if (n !== lastComments) { lastComments = n; toast('New comments arrived — refresh to see them', 4000); }
    };
    setInterval(tick, 4000);
  }
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-moderate]');
    if (!b) return;
    const hide = b.dataset.hidden !== '1';
    try {
      await post(`${A.base}admin/api/comment`, { id: Number(b.dataset.moderate), hidden: hide });
      b.dataset.hidden = hide ? '1' : '0';
      b.textContent = hide ? 'Show' : 'Hide';
      b.closest('.comment-item').classList.toggle('is-hidden', hide);
      toast(hide ? 'Hidden from the projector' : 'Visible on the projector again');
    } catch (err) { toast(err.message); }
  });

  // ---------- presentation builder ----------
  const pf = $('present-form');
  if (pf) {
    const build = () => {
      const slugs = qsa('input[type=checkbox]:checked', pf).map(c => c.value);
      const url = `${pf.dataset.presentBase}?p=${slugs.join(',')}&t=${$('present-every').value || 20}`;
      $('present-url').textContent = slugs.length ? url : 'Tick at least one voting page.';
      $('present-open').href = slugs.length ? url : '#';
      return slugs.length ? url : '';
    };
    pf.addEventListener('input', build);
    $('present-copy').addEventListener('click', () => { const u = build(); if (u) copy(u, 'Presentation link copied'); });
    build();
  }

  // Live accent preview on the settings page.
  document.querySelector('[data-accent-live]')?.addEventListener('input', e => document.documentElement.style.setProperty('--accent', e.target.value));
})();
