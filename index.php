<?php
require __DIR__ . '/api/lib.php';
$cfg = cms_config();
$isAdmin = cms_session_valid();
$siteName = htmlspecialchars($cfg['site_name'], ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $siteName ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Hiragino Sans', 'Meiryo', sans-serif; color: #1a1a1a; background: #f7f8fa; min-height: 100vh; }
#app { display: flex; flex-direction: column; min-height: 100vh; }

/* Header */
header {
  background: #1c3557; color: #fff;
  padding: 0 2rem; height: 60px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 100;
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
}
.logo { font-size: 1.1rem; font-weight: 700; letter-spacing: .03em; cursor: pointer; }
.btn-sm {
  padding: .35rem .9rem; border-radius: 4px; font-size: .82rem;
  font-weight: 600; border: none; cursor: pointer; transition: background .15s;
}
.btn-back { background: rgba(255,255,255,.15); color: #fff; }
.btn-back:hover { background: rgba(255,255,255,.25); }

/* Preview banner (管理者ログイン中) */
.preview-banner { background: #f0a500; color: #1a2a3a; text-align: center; font-size: .8rem; font-weight: 600; padding: .35rem; }

/* Genre Nav */
nav.genre-nav {
  background: #fff; border-bottom: 1px solid #dde2ea;
  display: flex; overflow-x: auto; padding: 0 2rem;
}
nav.genre-nav button {
  padding: .9rem 1.4rem; border: none; background: none;
  font-size: .92rem; color: #555; white-space: nowrap;
  border-bottom: 3px solid transparent; margin-bottom: -1px;
  cursor: pointer; transition: color .15s, border-color .15s;
}
nav.genre-nav button.active { color: #1c3557; font-weight: 700; border-bottom-color: #4ea8de; }
nav.genre-nav button:hover:not(.active) { color: #1c3557; }

/* Main */
main { flex: 1; padding: 2rem; max-width: 900px; margin: 0 auto; width: 100%; }

/* Article List */
.article-list { display: flex; flex-direction: column; gap: 1rem; }
.article-card {
  background: #fff; border-radius: 8px; border: 1px solid #dde2ea;
  padding: 1.25rem 1.5rem; cursor: pointer;
  transition: box-shadow .15s, transform .1s;
}
.article-card:hover { box-shadow: 0 4px 16px rgba(28,53,87,.12); transform: translateY(-2px); }
.card-meta { font-size: .78rem; color: #888; margin-bottom: .5rem; }
.card-title { font-size: 1.05rem; font-weight: 700; margin-bottom: .7rem; }
.card-preview { font-size: .9rem; color: #444; line-height: 1.7; }

/* Blocks */
.block { width: 100%; margin-bottom: 1.25rem; }
.block:last-child { margin-bottom: 0; }
.block-heading h2 { font-size: 1.35rem; font-weight: 700; color: #1c3557; padding-bottom: .5rem; border-bottom: 3px solid #4ea8de; }
.block-text .md-body { font-size: .95rem; line-height: 1.85; color: #333; }
.block-table { font-size: .95rem; overflow-x: auto; }
.block-image img { width: 100%; max-height: 480px; object-fit: cover; border-radius: 6px; display: block; }
.block-image .img-caption { font-size: .8rem; color: #888; margin-top: .35rem; text-align: center; }

/* Detail */
.article-detail { background: #fff; border-radius: 8px; border: 1px solid #dde2ea; padding: 2rem 2.5rem; }
.detail-meta { font-size: .8rem; color: #888; margin-bottom: 1.5rem; }

/* Misc */
.empty { text-align: center; color: #aaa; padding: 3rem 0; }
.loading { text-align: center; color: #888; padding: 2rem 0; }
footer { background: #1c3557; color: rgba(255,255,255,.6); text-align: center; font-size: .8rem; padding: 1.25rem; margin-top: auto; }
</style>
</head>
<body>
<div id="app">
  <header>
    <div class="logo" id="logoBtn"><?= $siteName ?></div>
    <div id="headerActions"></div>
  </header>
<?php if ($isAdmin): ?>
  <div class="preview-banner">プレビュー版（下書き）を表示中 — 公開するには管理画面の「公開」を押してください</div>
<?php endif; ?>
  <nav class="genre-nav" id="genreNav"></nav>
  <main id="main"></main>
  <footer><?= htmlspecialchars($cfg['copyright'], ENT_QUOTES) ?></footer>
</div>
<script src="/assets/cms-render.js"></script>
<script>
const API = '/api/articles.php';
const GENRES = <?= json_encode($cfg['genres'], JSON_UNESCAPED_UNICODE) ?>;

let state = { view: 'list', genre: GENRES[0].key, articles: [], current: null, loading: false };

function readUrl() {
  const p = new URLSearchParams(location.search);
  const g = p.get('g');
  if (g && GENRES.some(x => x.key === g)) state.genre = g;
  return { id: p.get('id') };
}
function pushUrl(replace) {
  const p = new URLSearchParams();
  p.set('g', state.genre);
  if (state.view === 'detail' && state.current) p.set('id', state.current.id);
  const url = '?' + p.toString();
  const st = { view: state.view, g: state.genre, id: state.current?.id || null };
  if (replace) history.replaceState(st, '', url);
  else history.pushState(st, '', url);
}
window.addEventListener('popstate', async (ev) => {
  const st = ev.state || {};
  if (st.g && st.g !== state.genre) state.genre = st.g;
  if (st.view === 'detail' && st.id) {
    state.loading = true; state.view = 'detail'; render();
    state.current = await apiFetch({ id: st.id });
    state.loading = false; render();
  } else {
    await gotoList(true);
  }
});

async function apiFetch(params = {}) {
  const qs = new URLSearchParams(params).toString();
  const res = await fetch(qs ? `${API}?${qs}` : API);
  return res.json();
}

function render() {
  renderGenreNav();
  renderHeader();
  renderMain();
}

function renderGenreNav() {
  const nav = document.getElementById('genreNav');
  nav.innerHTML = GENRES.map(g =>
    `<button class="${g.key===state.genre?'active':''}" data-g="${g.key}">${esc(g.label)}</button>`
  ).join('');
  nav.querySelectorAll('button').forEach(b =>
    b.addEventListener('click', () => { state.genre = b.dataset.g; gotoList(); })
  );
}

function renderHeader() {
  document.getElementById('logoBtn').onclick = () => gotoList();
  const el = document.getElementById('headerActions');
  el.innerHTML = state.view === 'detail'
    ? `<button class="btn-sm btn-back" id="btnBack">← 一覧へ</button>`
    : '';
  if (state.view === 'detail')
    document.getElementById('btnBack').onclick = () => gotoList();
}

function renderMain() {
  const main = document.getElementById('main');
  if (state.loading) { main.innerHTML = '<div class="loading">読み込み中...</div>'; return; }
  if (state.view === 'list')   renderList(main);
  if (state.view === 'detail') renderDetail(main);
}

/* List */
async function gotoList(noPush) {
  state.view = 'list'; state.current = null; state.loading = true; render();
  state.articles = await apiFetch({ genre: state.genre });
  state.loading = false; render();
  if (!noPush) pushUrl(false);
}

function renderList(main) {
  if (!state.articles.length) { main.innerHTML = '<div class="empty">記事がありません。</div>'; return; }
  const genreLabel = GENRES.find(g => g.key === state.genre)?.label || '';
  main.innerHTML = '<div class="article-list" id="articleList"></div>';
  const list = document.getElementById('articleList');
  state.articles.forEach(a => {
    const card = document.createElement('div');
    card.className = 'article-card';
    const b = a.blocks?.[0];
    let preview = '';
    if (b?.type === 'heading') preview = `<strong>${esc(b.content||'')}</strong>`;
    else if (b?.type === 'text') { const t = (b.content||'').replace(/\n/g,' '); preview = esc(t.length>120?t.slice(0,120)+'…':t); }
    else if (b?.type === 'image') preview = `<em style="color:#888">📷 画像</em>`;
    else if (b?.type === 'table') preview = `<em style="color:#888">📋 表</em>`;
    card.innerHTML = `<div class="card-meta">${esc(genreLabel)} ・ ${fmtDate(a.created_at)}</div>
      <div class="card-title">${esc(a.title)}</div>
      <div class="card-preview">${preview}</div>`;
    card.onclick = () => gotoDetail(a);
    list.appendChild(card);
  });
}

/* Detail */
async function gotoDetail(article, noPush) {
  state.view = 'detail'; state.loading = true; render();
  state.current = await apiFetch({ id: article.id });
  state.loading = false; render();
  if (!noPush) pushUrl(false);
}

function renderDetail(main) {
  const a = state.current;
  if (!a) { main.innerHTML = '<div class="empty">記事が見つかりません。</div>'; return; }
  const genreLabel = GENRES.find(g => g.key === a.genre)?.label || '';
  main.innerHTML = `<div class="article-detail">
    <div class="detail-meta">${esc(genreLabel)} ・ 投稿日: ${fmtDate(a.created_at)} ・ 更新日: ${fmtDate(a.updated_at)}</div>
    <h1 style="font-size:1.5rem;font-weight:700;color:#1c3557;margin-bottom:1.25rem;">${esc(a.title||'')}</h1>
    <div id="blockOutput"></div></div>`;
  const out = document.getElementById('blockOutput');
  (a.blocks||[]).forEach(b => out.appendChild(makeBlock(b)));
}

function makeBlock(block) {
  const div = document.createElement('div');
  div.className = 'block';
  if (block.type === 'heading') {
    div.classList.add('block-heading');
    div.innerHTML = `<h2>${esc(block.content||'')}</h2>`;
  } else if (block.type === 'text') {
    div.classList.add('block-text');
    div.innerHTML = `<div class="md-body">${mdText(block.content||'', boldStyleOf(block))}</div>`;
  } else if (block.type === 'table') {
    div.classList.add('block-table');
    div.innerHTML = tableHTML(block.markdown||'', block.style||'plain');
  } else if (block.type === 'image') {
    div.classList.add('block-image');
    div.innerHTML = block.src
      ? `<img src="${esc(block.src)}" alt="${esc(block.caption||'')}">
         ${block.caption ? `<div class="img-caption">${esc(block.caption)}</div>` : ''}`
      : `<div style="background:#eee;padding:2rem;text-align:center;color:#aaa;border-radius:6px;">画像なし</div>`;
  }
  return div;
}

/* 初期表示: URLパラメータから復元 */
(async () => {
  const { id } = readUrl();
  if (id) {
    state.view = 'detail'; state.loading = true; render();
    state.current = await apiFetch({ id });
    state.loading = false; render();
    pushUrl(true);
  } else {
    await gotoList(true);
    pushUrl(true);
  }
})();
</script>
</body>
</html>
