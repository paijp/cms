/* CMS 共通レンダラ（公開ページ・管理画面プレビューで共用） */
(function (global) {
'use strict';

function esc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.getFullYear()}/${String(d.getMonth()+1).padStart(2,'0')}/${String(d.getDate()).padStart(2,'0')}`;
}

/* ---- Markdown (pdf-cms互換の簡易md書式) ---- */
function boldStyleOf(b) {
  const parts = [];
  if (b.bold_color)    parts.push('color:' + b.bold_color);
  if (b.bold_bg_color) parts.push('background:' + b.bold_bg_color);
  const t = b.bold_ul_thick;
  if (t && t !== '0') {
    parts.push('text-decoration:underline', 'text-decoration-thickness:' + t);
    if (b.bold_ul_color) parts.push('text-decoration-color:' + b.bold_ul_color);
  }
  return parts.join(';');
}
function mdInline(t, bs) {
  t = esc(t);
  const sa = bs ? ` style="${esc(bs)}"` : '';
  t = t.replace(/\*\*([^*\n]+?)\*\*/g, (m, g) => `<strong${sa}>${g}</strong>`);
  // http/https の裸URLを自動リンク化。行末の句読点や閉じ括弧は含めない
  return t.replace(/https?:\/\/[^\s<>"'（）]+/g, (m) => {
    const trail = m.match(/[.,!?;:)]+$/);
    const url = trail ? m.slice(0, -trail[0].length) : m;
    const rest = trail ? trail[0] : '';
    return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>${rest}`;
  });
}
function mdText(text, bs) {
  const lines = String(text || '').split('\n');
  const out = [], stack = [];
  const closeTo = d => { while (stack.length > d) out.push('</' + stack.pop() + '>'); };
  for (const raw of lines) {
    const mUl = raw.match(/^(\s*)([-*])\s+(.*)$/);
    const mOl = raw.match(/^(\s*)(\d+)\.\s+(.*)$/);
    if (mUl || mOl) {
      const m = mUl || mOl;
      const depth = Math.floor(m[1].length / 2) + 1;
      const typ = mUl ? 'ul' : 'ol';
      if (stack.length >= depth) {
        closeTo(depth);
        if (stack.length && stack[stack.length - 1] !== typ) closeTo(depth - 1);
      }
      while (stack.length < depth) { stack.push(typ); out.push(`<${typ} style="margin:0 0 0 1.4em;padding:0">`); }
      out.push('<li>' + mdInline(m[3], bs) + '</li>');
    } else if (raw.trim() === '') {
      closeTo(0); out.push('<br>');
    } else {
      closeTo(0); out.push('<div>' + mdInline(raw, bs) + '</div>');
    }
  }
  closeTo(0);
  return out.join('');
}

/* ---- 表 (pdf-cms互換のmd表記法) ----
   行 = 「|」で始まり「|」で終わる行。1行目がヘッダ、2行目の |---|---| は無視。
   セル内の前後空白で寄せを指定: |txt|=左 | txt|=右 | txt |=中央
   セル内容 '<' は左のセルと結合、'^' は上のセルと結合。'&br;' は改行 */
const TABLE_STYLES = {
  'plain':       { thBg:'#f0f0f0', thColor:'#000', border:'1px solid #888', stripe:null,      firstLabel:false },
  'header-dark': { thBg:'#333',    thColor:'#fff', border:'1px solid #888', stripe:null,      firstLabel:false },
  'striped':     { thBg:'#333',    thColor:'#fff', border:'1px solid #888', stripe:'#f7f7f7', firstLabel:false },
  'form':        { thBg:'#f0f0f0', thColor:'#000', border:'1px solid #555', stripe:null,      firstLabel:true },
  'borderless':  { thBg:null,      thColor:null,   border:'none',           stripe:null,      firstLabel:false },
};

function parseMdTable(md) {
  const lines = String(md||'').split('\n').map(l => l.trim())
    .filter(l => l.length >= 2 && l.startsWith('|') && l.endsWith('|'));
  if (!lines.length) return null;
  const splitRow = l => l.slice(1, -1).split('|');
  const cellAlign = inner => {
    const lead = /^[ \t]/.test(inner), trail = /[ \t]$/.test(inner);
    if (lead && trail) return 'center';
    if (lead) return 'right';
    return 'left';
  };
  const parseRow = l => splitRow(l).map(c => [c.trim(), cellAlign(c)]);
  const headerCells = parseRow(lines[0]);
  const header = headerCells.map(x => x[0]), aligns = headerCells.map(x => x[1]);
  let rowsStart = 1;
  if (lines.length > 1 && /^\|(\s*:?-{2,}:?\s*\|)+$/.test(lines[1])) rowsStart = 2;
  const rowsParsed = lines.slice(rowsStart).map(parseRow);
  return {
    header, aligns,
    rows: rowsParsed.map(r => r.map(x => x[0])),
    rowAligns: rowsParsed.map(r => r.map(x => x[1])),
  };
}

function buildSpanGrid(rows, ncols) {
  const nrows = rows.length;
  const grid = rows.map(r => Array.from({length: ncols}, (_, i) => r[i] !== undefined ? r[i] : ''));
  const owner = Array.from({length: nrows}, (_, r) => Array.from({length: ncols}, (_, c) => r * ncols + c));
  for (let r = 0; r < nrows; r++) for (let c = 0; c < ncols; c++) {
    const t = grid[r][c].trim();
    if (t === '<' && c > 0) owner[r][c] = owner[r][c-1];
    else if (t === '^' && r > 0) owner[r][c] = owner[r-1][c];
  }
  return { grid, owner };
}
function cellSpan(owner, r, c, nrows, ncols) {
  const self = r * ncols + c;
  let colspan = 1;
  while (c + colspan < ncols && owner[r][c + colspan] === self) colspan++;
  let rowspan = 1;
  while (r + rowspan < nrows && owner[r + rowspan][c] === self) rowspan++;
  return [rowspan, colspan];
}
function fmtCell(text) {
  return esc(text).replace(/&amp;br;/g, '<br>');
}

function tableHTML(md, style) {
  const parsed = parseMdTable(md);
  if (!parsed) return '<div style="color:#999;font-style:italic;">[空の表]</div>';
  let { header, aligns, rows, rowAligns } = parsed;
  const ncols = Math.max(header.length, ...rows.map(r => r.length), 1);
  const st = TABLE_STYLES[style] || TABLE_STYLES.plain;
  const cellCss = `border:${st.border};padding:5px 8px;vertical-align:middle;`;
  const mkCell = (tag, text, align, rs, cs, extra) => {
    let attrs = '';
    if (rs > 1) attrs += ` rowspan="${rs}"`;
    if (cs > 1) attrs += ` colspan="${cs}"`;
    return `<${tag}${attrs} style="${cellCss}text-align:${align};${extra||''}">${fmtCell(text)}</${tag}>`;
  };
  const out = ['<table style="width:100%;border-collapse:collapse;">'];
  const headerAsBody = style === 'form';
  if (!headerAsBody) {
    const hg = buildSpanGrid([header], ncols);
    out.push('<thead><tr>');
    for (let c = 0; c < ncols; c++) {
      if (hg.owner[0][c] !== c) continue;
      const [rs, cs] = cellSpan(hg.owner, 0, c, 1, ncols);
      const thExtra = `background:${st.thBg || 'transparent'};color:${st.thColor || 'inherit'};font-weight:bold;`;
      out.push(mkCell('th', hg.grid[0][c], 'center', rs, cs, thExtra));
    }
    out.push('</tr></thead>');
  } else {
    rows = [header, ...rows];
    rowAligns = [aligns, ...rowAligns];
  }
  const bg = buildSpanGrid(rows, ncols);
  const nrows = rows.length;
  out.push('<tbody>');
  for (let r = 0; r < nrows; r++) {
    out.push('<tr>');
    for (let c = 0; c < ncols; c++) {
      if (bg.owner[r][c] !== r * ncols + c) continue;
      const [rs, cs] = cellSpan(bg.owner, r, c, nrows, ncols);
      const align = (rowAligns[r] && rowAligns[r][c]) || aligns[c] || 'left';
      let extra = '';
      if (st.stripe && r % 2 === 1) extra += `background:${st.stripe};`;
      if (st.firstLabel && c % 2 === 0) extra = 'background:#f0f0f0;font-weight:bold;white-space:nowrap;';
      out.push(mkCell('td', bg.grid[r][c], align, rs, cs, extra));
    }
    out.push('</tr>');
  }
  out.push('</tbody></table>');
  return out.join('');
}

Object.assign(global, { esc, fmtDate, boldStyleOf, mdInline, mdText, tableHTML });
})(window);
