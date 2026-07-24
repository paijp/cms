<?php
require __DIR__ . '/../api/lib.php';
header('Cache-Control: no-store');
$token = (string)($_GET['token'] ?? '');
if ($token !== '' && cms_token_valid($token)) {
    // トークンURLでのアクセス: セッションCookieを発行し、トークンをURLから消す
    cms_session_start_new();
    header('Location: /cms/');
    exit;
}
if (!cms_session_valid()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ja"><meta charset="UTF-8"><title>403 Forbidden</title><body style="font-family:sans-serif;text-align:center;padding:4rem;color:#555"><h1>403</h1><p>このページは管理者専用です。管理者用のトークン付きURLでアクセスしてください。</p></body></html>';
    exit;
}
$cfg = cms_config();
$siteName = htmlspecialchars($cfg['site_name'], ENT_QUOTES);
$assetVer = @filemtime(__DIR__ . '/../assets/cms-render.js') ?: 0;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $siteName ?> CMS 管理画面</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Hiragino Sans', 'Meiryo', sans-serif; color: #1a1a1a; background: #f0f2f5; min-height: 100vh; }
#app { display: flex; flex-direction: column; min-height: 100vh; }

/* Header */
header {
  background: #1a2a3a; color: #fff;
  padding: 0 2rem; height: 58px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 100;
  box-shadow: 0 2px 8px rgba(0,0,0,.3);
}
.logo { font-size: 1rem; font-weight: 700; letter-spacing: .03em; }
.logo-sub { font-size: .75rem; color: rgba(255,255,255,.5); margin-left: .5rem; }
.header-actions { display: flex; gap: .6rem; align-items: center; }
.btn { cursor: pointer; font-family: inherit; border: none; border-radius: 5px; font-weight: 600; transition: background .15s, color .15s; }
.btn-sm { padding: .35rem .9rem; font-size: .82rem; }
.btn-new  { background: #27ae60; color: #fff; }
.btn-new:hover  { background: #1e8449; }
.btn-publish { background: #f0a500; color: #1a2a3a; }
.btn-publish:hover { background: #d99400; }
.btn-back { background: rgba(255,255,255,.15); color: #fff; }
.btn-back:hover { background: rgba(255,255,255,.25); }
.btn-view { background: rgba(78,168,222,.25); color: #9dd4f5; font-size: .78rem; }
.btn-view:hover { background: rgba(78,168,222,.4); }

/* Genre Nav */
nav.genre-nav {
  background: #fff; border-bottom: 1px solid #dde2ea;
  display: flex; overflow-x: auto; padding: 0 2rem;
}
nav.genre-nav button {
  padding: .85rem 1.3rem; border: none; background: none; cursor: pointer;
  font-size: .9rem; color: #555; white-space: nowrap;
  border-bottom: 3px solid transparent; margin-bottom: -1px;
  transition: color .15s, border-color .15s;
}
nav.genre-nav button.active { color: #1a2a3a; font-weight: 700; border-bottom-color: #f0a500; }
nav.genre-nav button:hover:not(.active) { color: #1a2a3a; }

/* Main */
main { flex: 1; padding: 2rem; max-width: 960px; margin: 0 auto; width: 100%; }

/* Article List */
.article-list { display: flex; flex-direction: column; gap: .85rem; }
.article-card {
  background: #fff; border-radius: 8px; border: 1px solid #dde2ea;
  padding: 1.1rem 1.4rem; display: flex; align-items: center; gap: 1rem;
  cursor: pointer; transition: box-shadow .15s;
}
.article-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,.1); }
.card-body { flex: 1; min-width: 0; }
.card-meta { font-size: .75rem; color: #888; margin-bottom: .35rem; }
.card-title { font-size: 1rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-preview { font-size: .85rem; color: #555; margin-top: .35rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-actions { display: flex; gap: .5rem; flex-shrink: 0; align-items: center; }
.btn-edit-card { padding: .3rem .8rem; font-size: .8rem; background: #eaf4fd; color: #1c6fa8; border: 1px solid #b8d9f0; border-radius: 4px; cursor: pointer; }
.btn-edit-card:hover { background: #cde6f8; }
.btn-move { padding: .25rem .55rem; font-size: .85rem; background: #f4f6f8; color: #555; border: 1px solid #cdd3da; border-radius: 4px; cursor: pointer; }
.btn-move:hover { background: #e2e8ee; color: #1a2a3a; }
.btn-move:disabled { opacity: .35; cursor: default; }

/* Editor */
.editor { background: #fff; border-radius: 8px; border: 1px solid #dde2ea; padding: 2rem 2.5rem; }
.editor h2 { font-size: 1.15rem; font-weight: 700; margin-bottom: 1.5rem; color: #1a2a3a; }
.form-row { margin-bottom: 1.2rem; }
.form-row label { display: block; font-size: .83rem; font-weight: 600; color: #444; margin-bottom: .38rem; }
.form-row input, .form-row select, .form-row textarea {
  width: 100%; padding: .58rem .82rem;
  border: 1px solid #cdd3da; border-radius: 5px;
  font-size: .93rem; font-family: inherit; color: #1a1a1a;
}
.form-row input:focus, .form-row select:focus, .form-row textarea:focus {
  outline: none; border-color: #4ea8de; box-shadow: 0 0 0 3px rgba(78,168,222,.15);
}

/* Block Editor */
.blocks-editor { margin-top: 1.5rem; }
.blocks-editor-label { font-size: .83rem; font-weight: 600; color: #444; margin-bottom: .7rem; }
.block-item {
  border: 1px solid #dde2ea; border-radius: 6px;
  padding: .9rem 1rem; margin-bottom: .75rem; background: #fafbfc;
}
.block-item-header { display: flex; align-items: center; gap: .55rem; margin-bottom: .65rem; }
.block-type-badge { font-size: .73rem; font-weight: 700; padding: .18rem .55rem; border-radius: 99px; background: #fef3d0; color: #8a5c00; }
.block-item-actions { margin-left: auto; display: flex; gap: .35rem; }
.btn-icon { border: none; background: none; font-size: .95rem; color: #999; padding: .18rem .32rem; border-radius: 4px; cursor: pointer; transition: background .1s, color .1s; }
.btn-icon:hover { background: #eee; color: #c0392b; }
.btn-icon.move:hover { color: #1a2a3a; }
.block-item textarea, .block-item input[type=text], .block-item select {
  width: 100%; padding: .48rem .7rem;
  border: 1px solid #cdd3da; border-radius: 4px;
  font-size: .88rem; font-family: inherit; resize: vertical;
}
.block-item textarea:focus, .block-item input[type=text]:focus { outline: none; border-color: #4ea8de; }
.add-block-row { display: flex; gap: .55rem; flex-wrap: wrap; margin-top: .2rem; }
.btn-add-block {
  padding: .42rem .95rem; border-radius: 5px;
  border: 1px dashed #f0a500; background: #fffbf0;
  color: #7a5500; font-size: .83rem; font-weight: 600; cursor: pointer; transition: background .15s;
}
.btn-add-block:hover { background: #fdefc0; }
.img-preview { max-width: 100%; max-height: 180px; border-radius: 4px; margin-top: .4rem; display: block; }
.bold-opts { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .45rem; align-items: center; }
.bold-opts-label { font-size: .78rem; color: #666; font-weight: 600; }
.block-item .bold-opts input[type=text] { width: 9.5rem; font-size: .8rem; padding: .3rem .5rem; }
.table-opts { display: flex; gap: .4rem; margin-top: .45rem; align-items: center; }
.table-opts-label { font-size: .78rem; color: #666; font-weight: 600; }
.block-item .table-opts select { width: auto; font-size: .8rem; padding: .3rem .5rem; }
.md-preview { border: 1px dashed #cdd3da; border-radius: 4px; background: #fff; padding: .5rem .7rem; margin-top: .45rem; font-size: .88rem; line-height: 1.7; overflow-x: auto; }
.md-preview:empty { display: none; }

/* Editor Actions */
.editor-actions { display: flex; gap: .7rem; margin-top: 1.7rem; flex-wrap: wrap; }
.btn-save { padding: .58rem 1.5rem; background: #1a2a3a; color: #fff; border: none; border-radius: 5px; font-size: .93rem; font-weight: 600; cursor: pointer; }
.btn-save:hover { background: #0e1c2b; }
.btn-save:disabled { background: #888; cursor: not-allowed; }
.btn-delete { padding: .58rem 1.2rem; background: none; color: #c0392b; border: 1px solid #c0392b; border-radius: 5px; font-size: .88rem; font-weight: 600; cursor: pointer; }
.btn-delete:hover { background: #c0392b; color: #fff; }
.btn-cancel { padding: .58rem 1.2rem; background: none; color: #555; border: 1px solid #ccc; border-radius: 5px; font-size: .88rem; cursor: pointer; }
.btn-cancel:hover { background: #f0f0f0; }

/* Publish modal */
#modalOverlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 500;
  display: none; align-items: center; justify-content: center; padding: 2rem;
}
#modalOverlay.show { display: flex; }
.modal {
  background: #fff; border-radius: 8px; max-width: 800px; width: 100%;
  max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,.3);
}
.modal-header { padding: 1rem 1.5rem; font-weight: 700; color: #1a2a3a; border-bottom: 1px solid #dde2ea; }
.modal-body { padding: 1rem 1.5rem; overflow: auto; flex: 1; }
.modal-body pre { font-size: .78rem; line-height: 1.5; white-space: pre-wrap; word-break: break-all; font-family: ui-monospace, 'SFMono-Regular', Consolas, monospace; }
.modal-body pre .d-add { color: #1e8449; }
.modal-body pre .d-del { color: #c0392b; }
.modal-body pre .d-hdr { color: #1c6fa8; font-weight: 700; }
.modal-footer { padding: .9rem 1.5rem; border-top: 1px solid #dde2ea; display: flex; gap: .7rem; justify-content: flex-end; }

/* Misc */
.empty { text-align: center; color: #aaa; padding: 3rem 0; }
.loading { text-align: center; color: #888; padding: 2rem 0; }
#toast {
  position: fixed; bottom: 1.5rem; right: 1.5rem;
  background: #1a2a3a; color: #fff;
  padding: .55rem .55rem .55rem 1.2rem; border-radius: 24px; font-size: .88rem;
  box-shadow: 0 4px 16px rgba(0,0,0,.25); opacity: 0; pointer-events: none;
  transition: opacity .25s; z-index: 999;
  display: flex; align-items: center; gap: .7rem;
}
#toast.show { opacity: 1; pointer-events: auto; }
#toast-undo {
  background: #f0a500; color: #1a2a3a; border: none;
  border-radius: 18px; padding: .35rem 1rem;
  font-size: .82rem; font-weight: 700; cursor: pointer;
}
#toast-undo:hover { background: #d99400; }
footer { background: #1a2a3a; color: rgba(255,255,255,.45); text-align: center; font-size: .78rem; padding: 1.1rem; margin-top: auto; }
</style>
</head>
<body>
<div id="app">
  <header>
    <div>
      <span class="logo"><?= $siteName ?></span>
      <span class="logo-sub">CMS 管理画面</span>
    </div>
    <div class="header-actions" id="headerActions"></div>
  </header>
  <nav class="genre-nav" id="genreNav"></nav>
  <main id="main"></main>
  <footer><?= $siteName ?> CMS — 管理者専用ページ</footer>
</div>
<div id="toast"><span id="toast-msg"></span><button id="toast-undo" style="display:none">元に戻す</button></div>
<div id="modalOverlay">
  <div class="modal">
    <div class="modal-header">公開前の確認 — 下書きと公開版の差分</div>
    <div class="modal-body"><pre id="diffText"></pre></div>
    <div class="modal-footer">
      <button class="btn btn-cancel" id="btnModalClose" style="padding:.5rem 1.2rem">閉じる</button>
      <button class="btn btn-publish" id="btnModalPublish" style="padding:.5rem 1.5rem;border-radius:5px">公開する</button>
    </div>
  </div>
</div>
<div id="imgPickerOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;width:90%;max-width:800px;max-height:85vh;display:flex;flex-direction:column;">
    <div style="padding:1rem 1.5rem;font-weight:700;color:#1a2a3a;border-bottom:1px solid #dde2ea;">アップロード済み画像から選択</div>
    <div id="imgPickerBody" style="padding:1rem;overflow:auto;flex:1;"></div>
    <div style="padding:.9rem 1.5rem;border-top:1px solid #dde2ea;display:flex;justify-content:flex-end;">
      <button class="btn btn-cancel" id="btnImgPickerClose" style="padding:.5rem 1.2rem">閉じる</button>
    </div>
  </div>
</div>

<script src="/assets/cms-render.js?v=<?= $assetVer ?>"></script>
<script>
const API = '/api/articles.php';
const VIEW_BASE = '/';
const GENRES = <?= json_encode($cfg['genres'], JSON_UNESCAPED_UNICODE) ?>;
const BLOCK_TYPES = [
  { type: 'heading',   label: '見出し' },
  { type: 'text',      label: '文章' },
  { type: 'table',     label: '表' },
  { type: 'image',     label: '画像' },
  { type: 'link_from', label: '他ジャンルからリンク' },
  { type: 'permalink', label: '固定リンク' },
];
const TABLE_STYLE_OPTIONS = [
  { value: 'plain',       label: '標準' },
  { value: 'header-dark', label: '見出し濃色' },
  { value: 'striped',     label: 'しましま' },
  { value: 'form',        label: 'フォーム型（左列がラベル）' },
  { value: 'borderless',  label: '罫線なし' },
];
const TABLE_SAMPLE_MD = `|見出し1|見出し2|見出し3|
|A|B|C|
|D|E|F|`;

let state = { view: 'list', genre: GENRES[0].key, articles: [], current: null, editing: null, loading: false };

function readUrl() {
  const p = new URLSearchParams(location.search);
  const g = p.get('g'); if (g && GENRES.some(x => x.key === g)) state.genre = g;
  return { edit: p.get('edit') };
}
function pushUrl(replace) {
  const p = new URLSearchParams();
  p.set('g', state.genre);
  if (state.view === 'editor' && state.editing) p.set('edit', state.editing.id);
  const url = '?' + p.toString();
  const st = { view: state.view, g: state.genre, edit: state.editing?.id || null };
  if (replace) history.replaceState(st, '', url);
  else history.pushState(st, '', url);
}
window.addEventListener('popstate', async (ev) => {
  const st = ev.state || {};
  if (st.g && st.g !== state.genre) state.genre = st.g;
  if (st.view === 'editor' && st.edit) {
    const full = await api('GET', { id: st.edit });
    if (full) { state.editing = full; state.view = 'editor'; render(); }
  } else {
    await gotoList(true);
  }
});

let toastTimer = null, toastUndoFn = null;
function showToast(msg, undoFn) {
  clearTimeout(toastTimer);
  const t = document.getElementById('toast');
  document.getElementById('toast-msg').textContent = msg;
  toastUndoFn = undoFn || null;
  document.getElementById('toast-undo').style.display = undoFn ? '' : 'none';
  t.classList.add('show');
  toastTimer = setTimeout(() => { t.classList.remove('show'); toastUndoFn = null; },
                          undoFn ? 6000 : 2200);
}
document.getElementById('toast-undo').addEventListener('click', () => {
  clearTimeout(toastTimer);
  document.getElementById('toast').classList.remove('show');
  const fn = toastUndoFn; toastUndoFn = null;
  if (fn) fn();
});
async function api(method, params = {}, body = null) {
  const qs = new URLSearchParams(params).toString();
  const url = qs ? `${API}?${qs}` : API;
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (body) opts.body = JSON.stringify(body);
  return (await fetch(url, opts)).json();
}
function genId(genre) { return genre + '-' + Date.now(); }

function render() { renderGenreNav(); renderHeader(); renderMain(); }

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
  const el = document.getElementById('headerActions');
  const viewLink = `<a href="${VIEW_BASE}" target="_blank"><button class="btn btn-sm btn-view">プレビュー ↗</button></a>`;
  if (state.view === 'list') {
    el.innerHTML = `${viewLink} <button class="btn btn-sm btn-view" id="btnAccessLog" style="padding:.35rem .9rem">📊 アクセスログ</button> <a href="${API}?export=1" class="btn btn-sm btn-view" style="text-decoration:none;padding:.35rem .9rem;display:inline-block">⤓ エクスポート</a> <button class="btn btn-sm btn-publish" id="btnPublish">公開</button> <button class="btn btn-sm btn-new" id="btnNew">＋ 新規記事</button>`;
    document.getElementById('btnNew').onclick = () => gotoEditor(null);
    document.getElementById('btnPublish').onclick = openPublishModal;
    document.getElementById('btnAccessLog').onclick = openAccessLog;
  } else {
    el.innerHTML = `${viewLink} <button class="btn btn-sm btn-back" id="btnBack">← 一覧へ</button>`;
    document.getElementById('btnBack').onclick = () => gotoList();
  }
}

function renderMain() {
  const main = document.getElementById('main');
  if (state.loading) { main.innerHTML = '<div class="loading">読み込み中...</div>'; return; }
  if (state.view === 'list')   renderList(main);
  if (state.view === 'editor') renderEditor(main);
}

/* ---- 公開（差分確認 → 反映） ---- */
function diffToHTML(text) {
  return text.split('\n').map(l => {
    const e = esc(l);
    if (l.startsWith('+++') || l.startsWith('---') || l.startsWith('diff ') || l.startsWith('@@')) return `<span class="d-hdr">${e}</span>`;
    if (l.startsWith('+')) return `<span class="d-add">${e}</span>`;
    if (l.startsWith('-')) return `<span class="d-del">${e}</span>`;
    return e;
  }).join('\n');
}
async function openPublishModal() {
  const r = await api('GET', { diff: 1 });
  const overlay = document.getElementById('modalOverlay');
  const pre = document.getElementById('diffText');
  const hasChanges = (r.diff || '').trim() !== '';
  pre.innerHTML = hasChanges ? diffToHTML(r.diff) : '変更はありません。下書きと公開版は同じ内容です。';
  document.getElementById('btnModalPublish').style.display = hasChanges ? '' : 'none';
  overlay.classList.add('show');
}
document.getElementById('btnModalClose').onclick = () => document.getElementById('modalOverlay').classList.remove('show');

async function openAccessLog() {
  const overlay = document.getElementById('modalOverlay');
  document.querySelector('.modal-header').textContent = 'アクセスログ（過去30日）';
  document.getElementById('diffText').textContent = '読み込み中...';
  document.getElementById('btnModalPublish').style.display = 'none';
  overlay.classList.add('show');
  const res = await fetch(API + '?access_log=1&days=30');
  const text = await res.text();
  document.getElementById('diffText').textContent = text || '（該当データなし）';
}
document.getElementById('btnModalPublish').onclick = async () => {
  const r = await api('POST', { publish: 1 });
  document.getElementById('modalOverlay').classList.remove('show');
  showToast(`公開しました（更新 ${r.updated} 件 / 削除 ${r.removed} 件）`);
};

/* ---- List ---- */
async function gotoList(noPush) {
  state.view = 'list'; state.current = null; state.editing = null; state.loading = true; render();
  state.articles = await api('GET', { genre: state.genre, include_hidden: 1 });
  state.loading = false; render();
  if (!noPush) pushUrl(false);
}

async function duplicateArticle(id) {
  const r = await api('POST', { duplicate: 1, id });
  if (r && r.id) {
    showToast('複製しました');
    await gotoList(true);
  } else {
    showToast('複製に失敗しました');
  }
}

async function moveArticle(idx, dir) {
  const j = dir === 'up' ? idx - 1 : idx + 1;
  if (j < 0 || j >= state.articles.length) return;
  [state.articles[idx], state.articles[j]] = [state.articles[j], state.articles[idx]];
  await api('POST', { reorder: 1 }, { ids: state.articles.map(a => a.id) });
  render();
}

function renderList(main) {
  const genreLabel = GENRES.find(g => g.key === state.genre)?.label || '';
  if (!state.articles.length) { main.innerHTML = '<div class="empty">記事がありません。「＋ 新規記事」から作成してください。</div>'; return; }
  main.innerHTML = '<div class="article-list" id="articleList"></div>';
  const list = document.getElementById('articleList');
  state.articles.forEach((a, idx) => {
    const card = document.createElement('div');
    card.className = 'article-card';
    const b = a.blocks?.[0];
    let preview = '';
    if (b?.type === 'heading') preview = b.content || '';
    else if (b?.type === 'text') { const t = (b.content||'').replace(/\n/g,' '); preview = t.length>80?t.slice(0,80)+'…':t; }
    else if (b?.type === 'image') preview = '📷 画像';
    else if (b?.type === 'table') preview = '📋 表';
    card.innerHTML = `
      <div class="card-body">
        <div class="card-meta">${esc(genreLabel)} ・ ${fmtDate(a.created_at)} 更新: ${fmtDate(a.updated_at)}</div>
        <div class="card-title"><span style="color:#888;font-size:.78rem;font-weight:500;margin-right:.4rem">#${a.short_id ?? '?'}</span>${a.hidden?'<span style="background:#e0e5eb;color:#555;font-size:.7rem;font-weight:700;padding:.1rem .45rem;border-radius:99px;margin-right:.4rem;vertical-align:middle">非表示</span>':''}${esc(a.title)}</div>
        ${preview ? `<div class="card-preview">${esc(preview)}</div>` : ''}
      </div>
      <div class="card-actions">
        <button class="btn-move" data-dir="up" title="上へ" ${idx===0?'disabled':''}>↑</button>
        <button class="btn-move" data-dir="down" title="下へ" ${idx===state.articles.length-1?'disabled':''}>↓</button>
        <button class="btn-edit-card btn-dup" title="複製">⧉ 複製</button>
        <button class="btn-edit-card">✎ 編集</button>
      </div>`;
    card.querySelectorAll('.btn-move').forEach(btn => {
      btn.onclick = (e) => { e.stopPropagation(); moveArticle(idx, btn.dataset.dir); };
    });
    card.querySelector('.btn-dup').onclick = (e) => { e.stopPropagation(); duplicateArticle(a.id); };
    card.querySelector('.btn-edit-card:not(.btn-dup)').onclick = async (e) => {
      e.stopPropagation();
      const full = await api('GET', { id: a.id, include_hidden: 1 });
      gotoEditor(full);
    };
    card.onclick = async () => {
      const full = await api('GET', { id: a.id, include_hidden: 1 });
      gotoEditor(full);
    };
    list.appendChild(card);
  });
}

/* ---- Editor ---- */
function gotoEditor(article, noPush) {
  state.editing = article
    ? JSON.parse(JSON.stringify(article))
    : { id: genId(state.genre), genre: state.genre, title: '', blocks: [] };
  state.view = 'editor'; render();
  if (!noPush) pushUrl(false);
}

function renderEditor(main) {
  const e = state.editing;
  const isNew = !e.created_at;
  main.innerHTML = `
    <div class="editor">
      <h2>${isNew ? '新規記事の作成' : '記事を編集（下書きに保存されます）'}</h2>
      <div class="form-row">
        <label>ジャンル</label>
        <select id="eGenre">${GENRES.map(g=>`<option value="${g.key}"${g.key===e.genre?' selected':''}>${esc(g.label)}</option>`).join('')}</select>
      </div>
      <div class="form-row">
        <label>タイトル</label>
        <input type="text" id="eTitle" value="${esc(e.title)}" placeholder="記事のタイトルを入力">
      </div>
      <div class="form-row" style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">
        <label style="margin:0">記事番号 (short_id)</label>
        <input type="number" id="eShortId" min="1" style="width:6rem" value="${e.short_id ?? ''}" placeholder="自動採番">
        <span id="eShortIdStatus" style="font-size:.8rem"></span>
      </div>
      <div class="form-row" style="display:flex;align-items:center;gap:.5rem">
        <input type="checkbox" id="eHidden" ${e.hidden?'checked':''} style="width:auto">
        <label for="eHidden" style="margin:0;cursor:pointer">非表示（管理画面には表示されますが、公開ページには表示されません）</label>
      </div>
      <div class="blocks-editor">
        <div class="blocks-editor-label">ブロック</div>
        <div id="blocksContainer"></div>
        <div class="add-block-row">
          ${BLOCK_TYPES.map(bt=>`<button class="btn-add-block" data-btype="${bt.type}">＋ ${bt.label}</button>`).join('')}
        </div>
      </div>
      <div class="editor-actions">
        <button class="btn-save" id="btnSave">下書き保存</button>
        ${!isNew ? `<button class="btn-delete" id="btnDelete">削除する</button>` : ''}
        <button class="btn-cancel" id="btnCancel">キャンセル</button>
      </div>
    </div>`;

  renderBlocksEditor();
  document.getElementById('eGenre').onchange = ev => { e.genre = ev.target.value; };
  document.getElementById('eTitle').oninput  = ev => { e.title = ev.target.value; };
  document.getElementById('eHidden').onchange = ev => { e.hidden = ev.target.checked; };
  const sidInp = document.getElementById('eShortId');
  const sidStatus = document.getElementById('eShortIdStatus');
  let sidTimer = null;
  const runSidCheck = async () => {
    const n = parseInt(sidInp.value, 10);
    if (!n || n < 1) { sidStatus.innerHTML = '<span style="color:#888">保存時に自動採番されます</span>'; return; }
    const r = await api('GET', { short_id_check: n, genre: e.genre, exclude: e.id });
    const ms = r.matches || [];
    if (ms.length === 0) sidStatus.innerHTML = `<span style="color:#1e8449">✓ #${n} は使用可能</span>`;
    else sidStatus.innerHTML = '<span style="color:#c0392b">✗ ' + ms.map(m => `#${n} は「${esc(m.title)}」で使用中`).join(', ') + '</span>';
  };
  sidInp.oninput = ev => {
    const v = parseInt(ev.target.value, 10);
    e.short_id = (v && v >= 1) ? v : undefined;
    clearTimeout(sidTimer); sidTimer = setTimeout(runSidCheck, 300);
  };
  runSidCheck();
  document.getElementById('btnSave').onclick   = saveArticle;
  document.getElementById('btnCancel').onclick = () => gotoList();
  const btnDel = document.getElementById('btnDelete');
  if (btnDel) btnDel.onclick = deleteArticle;
  document.querySelectorAll('.btn-add-block').forEach(b =>
    b.addEventListener('click', () => addBlock(b.dataset.btype))
  );
}

function renderBlocksEditor() {
  const container = document.getElementById('blocksContainer');
  if (!container) return;
  container.innerHTML = '';
  (state.editing.blocks||[]).forEach((block, idx) =>
    container.appendChild(buildBlockEditor(block, idx))
  );
}

function buildBlockEditor(block, idx) {
  const e = state.editing;
  const typeLabel = BLOCK_TYPES.find(b => b.type === block.type)?.label || block.type;
  const div = document.createElement('div');
  div.className = 'block-item';
  div.innerHTML = `
    <div class="block-item-header">
      <span class="block-type-badge">${typeLabel}</span>
      <div class="block-item-actions">
        <button class="btn-icon move" data-dir="up" data-idx="${idx}" title="上へ">↑</button>
        <button class="btn-icon move" data-dir="down" data-idx="${idx}" title="下へ">↓</button>
        <button class="btn-icon" data-del="${idx}" title="削除">✕</button>
      </div>
    </div>
    <div id="bce-${idx}"></div>`;

  const bce = div.querySelector(`#bce-${idx}`);
  if (block.type === 'heading') {
    const inp = document.createElement('input');
    inp.type = 'text'; inp.placeholder = '見出しテキストを入力'; inp.value = block.content||'';
    inp.oninput = ev => { e.blocks[idx].content = ev.target.value; };
    bce.appendChild(inp);
  } else if (block.type === 'text') {
    const ta = document.createElement('textarea');
    ta.rows = 5; ta.placeholder = '本文を入力（md書式: **強調** / - 箇条書き / 1. 番号付き / 空行=空行）'; ta.value = block.content||'';
    bce.appendChild(ta);
    const opts = document.createElement('div');
    opts.className = 'bold-opts';
    opts.innerHTML = `
      <span class="bold-opts-label">強調（**太字**）の表示:</span>
      <input type="text" data-k="bold_color" placeholder="文字色 (#c00)" value="${esc(block.bold_color||'')}">
      <input type="text" data-k="bold_bg_color" placeholder="背景色 (#ff9)" value="${esc(block.bold_bg_color||'')}">
      <input type="text" data-k="bold_ul_thick" placeholder="下線太さ (2px)" value="${esc(block.bold_ul_thick||'')}">
      <input type="text" data-k="bold_ul_color" placeholder="下線色 (#c00)" value="${esc(block.bold_ul_color||'')}">`;
    bce.appendChild(opts);
    const pv = document.createElement('div');
    pv.className = 'md-preview';
    bce.appendChild(pv);
    const upd = () => { pv.innerHTML = mdText(e.blocks[idx].content||'', boldStyleOf(e.blocks[idx])); };
    ta.oninput = ev => { e.blocks[idx].content = ev.target.value; upd(); };
    opts.querySelectorAll('input').forEach(inp => {
      inp.oninput = ev => {
        const v = ev.target.value.trim();
        if (v) e.blocks[idx][inp.dataset.k] = v; else delete e.blocks[idx][inp.dataset.k];
        upd();
      };
    });
    upd();
  } else if (block.type === 'table') {
    const ta = document.createElement('textarea');
    ta.rows = 5;
    ta.placeholder = '|見出し1|見出し2|\n|A|B|\n※セル前後の空白で寄せ指定（| x|=右, | x |=中央）、< で左と結合、^ で上と結合、&br; で改行';
    ta.value = block.markdown||'';
    bce.appendChild(ta);
    const opts = document.createElement('div');
    opts.className = 'table-opts';
    opts.innerHTML = `
      <span class="table-opts-label">表のスタイル:</span>
      <select>${TABLE_STYLE_OPTIONS.map(o=>`<option value="${o.value}"${o.value===(block.style||'plain')?' selected':''}>${o.label}</option>`).join('')}</select>`;
    bce.appendChild(opts);
    const pv = document.createElement('div');
    pv.className = 'md-preview';
    bce.appendChild(pv);
    const upd = () => { pv.innerHTML = tableHTML(e.blocks[idx].markdown||'', e.blocks[idx].style||'plain'); };
    ta.oninput = ev => { e.blocks[idx].markdown = ev.target.value; upd(); };
    opts.querySelector('select').onchange = ev => { e.blocks[idx].style = ev.target.value; upd(); };
    upd();
  } else if (block.type === 'permalink') {
    const note = document.createElement('div');
    note.style.cssText = 'font-size:.78rem;color:#666;margin-bottom:.5rem;padding:.4rem .6rem;background:#fffbf0;border:1px dashed #f0a500;border-radius:4px';
    note.innerHTML = 'このブロックは記事には表示されません。ここで指定した文字列で <strong>/?topic=&lt;文字列&gt;</strong> の短いURLでこの記事を開けるようになります。';
    bce.appendChild(note);
    const inp = document.createElement('input');
    inp.type = 'text';
    inp.placeholder = '固定リンク文字列 (半角英数字・アンダースコア・ハイフン、1〜64字)';
    inp.value = block.slug || '';
    bce.appendChild(inp);
    const status = document.createElement('div');
    status.style.cssText = 'font-size:.8rem;margin-top:.4rem;';
    bce.appendChild(status);
    let checkTimer = null;
    const runCheck = async () => {
      const slug = (e.blocks[idx].slug || '').trim();
      if (!slug) { status.innerHTML = ''; return; }
      if (!/^[a-zA-Z0-9_\-]{1,64}$/.test(slug)) {
        status.innerHTML = '<span style="color:#c0392b">✗ 使用できない文字が含まれています</span>';
        return;
      }
      status.innerHTML = '<span style="color:#888">確認中…</span>';
      const r = await api('GET', { permalink_check: slug, exclude: e.id, include_hidden: 1 });
      const ms = r.matches || [];
      if (ms.length === 0) {
        status.innerHTML = `<span style="color:#1e8449">✓ 使用可能 · 公開URL: <code>/?topic=${esc(slug)}</code></span>`;
      } else {
        status.innerHTML = '<span style="color:#c0392b">✗ 既に使用中:</span> ' + ms.map(m =>
          `<span style="color:#c0392b">「${esc(m.title || m.id)}」</span>`
        ).join(', ');
      }
    };
    inp.oninput = ev => {
      e.blocks[idx].slug = ev.target.value.trim();
      clearTimeout(checkTimer);
      checkTimer = setTimeout(runCheck, 300);
    };
    runCheck();
  } else if (block.type === 'link_from') {
    const note = document.createElement('div');
    note.style.cssText = 'font-size:.78rem;color:#666;margin-bottom:.5rem;padding:.4rem .6rem;background:#fffbf0;border:1px dashed #f0a500;border-radius:4px';
    const updNote = () => {
      const tg = e.blocks[idx].target_genre;
      const tl = GENRES.find(g => g.key === tg)?.label;
      note.innerHTML = tl
        ? `このブロックはこの記事のページには表示されず、<strong>${esc(tl)}</strong>の一覧に「タイトル + 文章 + 詳しくは… リンク」として表示されます。`
        : 'リンク先ジャンルを選択してください。';
    };
    bce.appendChild(note);
    const sel = document.createElement('select');
    sel.innerHTML = '<option value="">(リンク先ジャンルを選択)</option>' +
      GENRES.filter(g => g.key !== e.genre)
            .map(g => `<option value="${esc(g.key)}"${g.key===block.target_genre?' selected':''}>${esc(g.label)}</option>`).join('');
    sel.onchange = ev => { e.blocks[idx].target_genre = ev.target.value; updNote(); };
    bce.appendChild(sel);
    const titleInp = document.createElement('input');
    titleInp.type = 'text'; titleInp.placeholder = 'タイトル'; titleInp.value = block.title||'';
    titleInp.style.marginTop = '.4rem';
    titleInp.oninput = ev => { e.blocks[idx].title = ev.target.value; };
    bce.appendChild(titleInp);
    const ta = document.createElement('textarea');
    ta.rows = 3; ta.placeholder = '文章（md書式対応）'; ta.value = block.text||'';
    ta.style.marginTop = '.4rem';
    bce.appendChild(ta);
    const bopts = document.createElement('div');
    bopts.className = 'bold-opts';
    bopts.innerHTML = `
      <span class="bold-opts-label">強調（**太字**）の表示:</span>
      <input type="text" data-k="bold_color" placeholder="文字色 (#c00)" value="${esc(block.bold_color||'')}">
      <input type="text" data-k="bold_bg_color" placeholder="背景色 (#ff9)" value="${esc(block.bold_bg_color||'')}">
      <input type="text" data-k="bold_ul_thick" placeholder="下線太さ (2px)" value="${esc(block.bold_ul_thick||'')}">
      <input type="text" data-k="bold_ul_color" placeholder="下線色 (#c00)" value="${esc(block.bold_ul_color||'')}">`;
    bce.appendChild(bopts);
    const pv = document.createElement('div');
    pv.className = 'md-preview';
    bce.appendChild(pv);
    const updPv = () => { pv.innerHTML = mdText(e.blocks[idx].text||'', boldStyleOf(e.blocks[idx])); };
    ta.oninput = ev => { e.blocks[idx].text = ev.target.value; updPv(); };
    bopts.querySelectorAll('input').forEach(inp => {
      inp.oninput = ev => {
        const v = ev.target.value.trim();
        if (v) e.blocks[idx][inp.dataset.k] = v; else delete e.blocks[idx][inp.dataset.k];
        updPv();
      };
    });
    updPv();
    updNote();
  } else if (block.type === 'image') {
    const urlRow = document.createElement('div');
    urlRow.style.cssText = 'display:flex;gap:.35rem;align-items:stretch;';
    const urlInp = document.createElement('input');
    urlInp.type = 'text'; urlInp.placeholder = '画像URL'; urlInp.value = block.src||'';
    urlInp.style.flex = '1';
    urlInp.oninput = ev => { e.blocks[idx].src = ev.target.value; refreshImgPreview(bce, idx); };
    const upBtn = document.createElement('button');
    upBtn.type = 'button'; upBtn.textContent = '📤 アップロード';
    upBtn.style.cssText = 'padding:.3rem .7rem;border:1px solid #ccc;background:#f8f8f8;border-radius:4px;cursor:pointer;font-size:.83rem;white-space:nowrap;';
    upBtn.onclick = () => uploadImageForBlock(idx, bce);
    const pickBtn = document.createElement('button');
    pickBtn.type = 'button'; pickBtn.textContent = '📁 選択';
    pickBtn.style.cssText = upBtn.style.cssText;
    pickBtn.onclick = () => openImagePicker(idx, bce);
    urlRow.appendChild(urlInp); urlRow.appendChild(upBtn); urlRow.appendChild(pickBtn);
    const capInp = document.createElement('input');
    capInp.type = 'text'; capInp.placeholder = 'キャプション（省略可）'; capInp.value = block.caption||'';
    capInp.style.marginTop = '.4rem';
    capInp.oninput = ev => { e.blocks[idx].caption = ev.target.value; };
    const decSel = document.createElement('select');
    decSel.style.marginTop = '.4rem';
    decSel.innerHTML = `<option value="">装飾なし</option><option value="phone-frame">スマホ枠</option>`;
    decSel.value = block.decoration || '';
    decSel.onchange = ev => {
      const v = ev.target.value;
      if (v) e.blocks[idx].decoration = v; else delete e.blocks[idx].decoration;
    };
    bce.appendChild(urlRow); bce.appendChild(capInp); bce.appendChild(decSel);
    if (block.src) {
      const img = document.createElement('img');
      img.src = block.src; img.className = 'img-preview';
      bce.appendChild(img);
    }
  }

  div.querySelectorAll('.btn-icon[data-dir]').forEach(b => {
    b.onclick = () => {
      const i = parseInt(b.dataset.idx), d = b.dataset.dir;
      if (d==='up' && i>0) [e.blocks[i-1],e.blocks[i]]=[e.blocks[i],e.blocks[i-1]];
      else if (d==='down' && i<e.blocks.length-1) [e.blocks[i],e.blocks[i+1]]=[e.blocks[i+1],e.blocks[i]];
      renderBlocksEditor();
    };
  });
  div.querySelector('.btn-icon[data-del]').onclick = () => {
    const removed = e.blocks[idx];
    const typeLabel = BLOCK_TYPES.find(bt => bt.type === removed?.type)?.label || 'ブロック';
    e.blocks.splice(idx, 1);
    renderBlocksEditor();
    showToast(`${typeLabel}を削除しました`, () => {
      e.blocks.splice(idx, 0, removed);
      renderBlocksEditor();
      showToast('削除を取り消しました');
    });
  };
  return div;
}

function refreshImgPreview(bce, idx) {
  const src = state.editing.blocks[idx].src;
  let img = bce.querySelector('.img-preview');
  if (src) {
    if (!img) { img = document.createElement('img'); img.className = 'img-preview'; bce.appendChild(img); }
    img.src = src;
  } else if (img) { img.remove(); }
}

/* ---- 画像アップロード / ピッカー ---- */
const UPLOAD_API = '/api/uploads.php';

function setImageBlockSrc(idx, bce, url) {
  state.editing.blocks[idx].src = url;
  const urlInp = bce.querySelector('input[type=text]');
  if (urlInp) urlInp.value = url;
  refreshImgPreview(bce, idx);
}

function uploadImageForBlock(idx, bce) {
  const fi = document.createElement('input');
  fi.type = 'file';
  fi.accept = 'image/jpeg,image/png,image/gif,image/webp';
  fi.onchange = async () => {
    const f = fi.files?.[0];
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    try {
      const res = await fetch(UPLOAD_API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await res.json();
      if (!res.ok) { showToast('アップロード失敗: ' + (j.error||res.status)); return; }
      setImageBlockSrc(idx, bce, j.url);
      showToast('アップロードしました');
    } catch (err) {
      showToast('アップロード失敗: ' + err.message);
    }
  };
  fi.click();
}

async function openImagePicker(idx, bce) {
  const overlay = document.getElementById('imgPickerOverlay');
  const body = document.getElementById('imgPickerBody');
  body.innerHTML = '<div style="color:#888;text-align:center;padding:2rem;">読み込み中…</div>';
  overlay.style.display = 'flex';
  let files = [];
  try {
    const res = await fetch(UPLOAD_API, { credentials: 'same-origin' });
    const j = await res.json();
    files = j.files || [];
  } catch (err) {
    body.innerHTML = '<div style="color:#c33;">読み込みに失敗しました</div>';
    return;
  }
  if (!files.length) {
    body.innerHTML = '<div style="color:#888;text-align:center;padding:2rem;">アップロード済み画像はありません</div>';
    return;
  }
  body.innerHTML = '<div id="imgPickerGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.7rem;"></div>';
  const grid = document.getElementById('imgPickerGrid');
  files.forEach(f => {
    const tile = document.createElement('div');
    tile.style.cssText = 'position:relative;border:1px solid #ddd;border-radius:4px;overflow:hidden;background:#fafafa;';
    const kb = Math.round(f.size/1024);
    const dim = (f.width && f.height) ? `${f.width}×${f.height}` : '';
    tile.innerHTML = `
      <img src="${f.url}" style="width:100%;height:110px;object-fit:cover;display:block;cursor:pointer;">
      <div style="font-size:.7rem;color:#666;padding:.3rem .4rem;display:flex;justify-content:space-between;">
        <span>${dim}</span><span>${kb}KB</span>
      </div>
      <button type="button" title="削除" style="position:absolute;top:.2rem;right:.2rem;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:99px;width:22px;height:22px;cursor:pointer;font-size:.85rem;line-height:1;">×</button>
    `;
    tile.querySelector('img').onclick = () => {
      setImageBlockSrc(idx, bce, f.url);
      overlay.style.display = 'none';
    };
    tile.querySelector('button').onclick = async () => {
      if (!confirm('この画像を削除しますか？（記事から参照されていても削除されます）')) return;
      const res = await fetch(UPLOAD_API + '?filename=' + encodeURIComponent(f.filename), {
        method: 'DELETE', credentials: 'same-origin'
      });
      if (res.ok) { tile.remove(); showToast('削除しました'); }
      else { showToast('削除失敗'); }
    };
    grid.appendChild(tile);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnImgPickerClose');
  if (btn) btn.onclick = () => { document.getElementById('imgPickerOverlay').style.display = 'none'; };
});

function addBlock(type) {
  const block = { type };
  if (type==='heading') block.content='';
  if (type==='text')    block.content='';
  if (type==='table')   { block.markdown=TABLE_SAMPLE_MD; block.style='plain'; }
  if (type==='image')   { block.src=''; block.caption=''; }
  if (type==='link_from') { block.target_genre=''; block.title=''; block.text=''; }
  if (type==='permalink') { block.slug=''; }
  state.editing.blocks.push(block);
  renderBlocksEditor();
  document.getElementById('blocksContainer')?.lastChild?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function saveArticle() {
  const e = state.editing;
  if (!e.title.trim()) { showToast('タイトルを入力してください'); return; }
  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.textContent = '保存中...';
  try {
    await api('POST', {}, e);
    showToast('下書きに保存しました（公開するには「公開」を押してください）');
    gotoList();
  } catch {
    showToast('保存に失敗しました');
    btn.disabled = false; btn.textContent = '下書き保存';
  }
}

async function deleteArticle() {
  if (!confirm('この記事を下書きから削除しますか？（公開版からは次回の「公開」で消えます）')) return;
  await api('DELETE', { id: state.editing.id });
  showToast('削除しました');
  gotoList();
}

/* 初期表示: URLパラメータから復元 */
(async () => {
  const { edit } = readUrl();
  if (edit) {
    const full = await api('GET', { id: edit });
    if (full && full.id) { gotoEditor(full, true); pushUrl(true); return; }
  }
  await gotoList(true);
  pushUrl(true);
})();
</script>
</body>
</html>
