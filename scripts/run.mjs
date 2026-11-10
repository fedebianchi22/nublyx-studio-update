// Orquestador de soporte automatizado Nublyx.
// Reemplaza al workflow de n8n: por cada sitio -> backup -> actualizar todo -> reporte por correo.
// Uso:  MODE=full|check SITES_JSON='[...]' node scripts/run.mjs   (o un sites.json local)
import fs from 'node:fs';
import nodemailer from 'nodemailer';

const MODE = process.env.MODE || 'full';
const BACKUP_TIMEOUT = 10 * 60_000;
const UPDATE_TIMEOUT = 15 * 60_000;

/* ───────────── Sitios ───────────── */
function loadSites() {
  const raw = process.env.SITES_JSON || (fs.existsSync('sites.json') ? fs.readFileSync('sites.json', 'utf8') : '');
  if (!raw) throw new Error('Falta SITES_JSON (secret) o sites.json local.');
  const list = JSON.parse(raw);
  return list.map((s, i) => {
    if (!s.url || !s.hub_token) throw new Error(`Sitio #${i + 1}: requiere "url" y "hub_token".`);
    return { name: s.name || s.url, url: s.url.replace(/\/+$/, ''), token: s.hub_token };
  });
}

/* ───────────── Llamadas al plugin ───────────── */
async function call(site, method, path, timeout) {
  const res = await fetch(`${site.url}/wp-json/nublyx-studio-update/v1${path}`, {
    method,
    headers: { 'X-Nublyx-Token': site.token, 'Content-Type': 'application/json', Accept: 'application/json' },
    body: method === 'POST' ? '{}' : undefined,
    signal: AbortSignal.timeout(timeout),
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { throw new Error(`Respuesta inválida (HTTP ${res.status}).`); }
  if (!res.ok && !data.status) throw new Error(data.message || `HTTP ${res.status}`);
  return data;
}

async function processSite(site) {
  const out = { site_name: site.name, site_url: site.url, backup: null, updates: null };

  if (MODE === 'check') {
    try {
      const st = await call(site, 'GET', '/status', 30_000);
      out.backup = { status: 'OK', size_mb: null, note: 'check' };
      out.updates = { plugins_updated: [], plugins_errors: [], themes_updated: [], themes_errors: [],
        core: { status: 'up_to_date', message: `Conexión OK · WP ${st.wp_version} · plugin ${st.plugin_version} · pendientes: ${st.pending_updates?.plugins} plugins / ${st.pending_updates?.themes} temas` } };
    } catch (e) {
      out.backup = { status: 'ERROR', error: e.message };
      out.updates = { error: 'Verificación fallida.' };
    }
    return out;
  }

  // 1) Backup (el plugin rota a máx. 3 ANTES de crear el nuevo)
  try {
    const b = await call(site, 'POST', '/backup', BACKUP_TIMEOUT);
    const ok = b.status === 'ok';
    out.backup = { status: ok ? 'OK' : 'ERROR', backup_name: b.backup_name, size_mb: b.size_mb, error: ok ? null : b.message || 'Error en backup' };
  } catch (e) {
    out.backup = { status: 'ERROR', error: e.message };
  }

  // 2) Actualizaciones: solo si el backup salió bien
  if (out.backup.status !== 'OK') {
    out.updates = { error: 'Actualización omitida: el backup falló.' };
    return out;
  }
  try {
    const u = await call(site, 'POST', '/update/all', UPDATE_TIMEOUT);
    const p = u.plugins || {}, t = u.themes || {};
    out.updates = {
      plugins_updated: p.updated || [], plugins_errors: p.errors || [], plugins_skipped: p.skipped || [],
      themes_updated: t.updated || [], themes_errors: t.errors || [], core: u.core || {},
    };
  } catch (e) {
    out.updates = { error: e.message };
  }
  return out;
}

/* ───────────── Reporte HTML ───────────── */
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const badge = (type, text) => `<span class="badge-${type}">${esc(text)}</span>`;
const row = (icon, name, type, label, detail, color) =>
  `<tr><td>${icon}</td><td>${esc(name)}</td><td>${badge(type, label)}</td><td${color ? ` style="color:${color}"` : ''}>${esc(detail)}</td></tr>`;
const errRow = (icon, item, nulledMsg) => {
  const nulled = item.reason === 'nulled_or_unlicensed';
  return row(icon, item.name, 'error', 'ERROR', nulled ? `⚠️ ${nulledMsg}` : item.error || 'Error', nulled ? '#b45309' : '#c62828');
};

const CSS = `*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Segoe UI',Arial,sans-serif;background:#f8f5ff;color:#1a1a2e;padding:24px}.container{max-width:780px;margin:0 auto}.header{background:linear-gradient(135deg,#290C49,#554095);color:white;padding:28px 32px;border-radius:12px 12px 0 0}.header h1{font-size:22px;font-weight:700;margin-bottom:4px}.header p{opacity:.8;font-size:14px}.global-summary{background:white;padding:18px 32px;display:flex;gap:32px;align-items:center;border-bottom:1px solid #eee}.stat .num{font-size:28px;font-weight:700}.stat .label{font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px}.num-sites{color:#554095}.num-ok{color:#2e7d32}.num-err{color:#c62828}.body{background:white;padding:24px 32px;border-radius:0 0 12px 12px}.site-card{border:1px solid #e0d9f5;border-radius:10px;margin-bottom:20px;overflow:hidden}.site-card.has-errors{border-color:#ffcdd2}.site-header{display:flex;align-items:center;gap:12px;padding:14px 16px;background:#faf7ff;border-bottom:1px solid #e0d9f5}.site-card.has-errors .site-header{background:#fff8f8;border-bottom-color:#ffcdd2}.site-icon{font-size:22px}.site-name{font-weight:700;font-size:15px;color:#290C49}.site-url{font-size:12px;color:#999}.site-summary{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap}table{width:100%;border-collapse:collapse;font-size:13px}th{background:#f0ebff;color:#554095;font-weight:600;padding:8px 12px;text-align:left}td{padding:8px 12px;border-bottom:1px solid #f5f0ff;vertical-align:middle}tr:last-child td{border-bottom:none}.badge-ok{background:#e8f5e9;color:#2e7d32;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap;display:inline-block}.badge-error{background:#ffebee;color:#c62828;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap;display:inline-block}.footer{text-align:center;color:#aaa;font-size:12px;margin-top:24px}.footer strong{color:#554095}`;

function buildReport(all) {
  const tz = 'America/Argentina/Cordoba';
  const now = new Date();
  const date = now.toLocaleDateString('es-AR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: tz });
  let totalErrors = 0, totalOk = 0, cards = '';

  for (const s of all) {
    const u = s.updates || {}, b = s.backup || {};
    const pOk = (u.plugins_updated || []).length, pErr = (u.plugins_errors || []).length;
    const tOk = (u.themes_updated || []).length, tErr = (u.themes_errors || []).length;
    const coreStatus = u.core?.status || 'unknown';
    const hasUpdErr = !!u.error;
    const siteErrors = pErr + tErr + (b.status === 'ERROR' ? 1 : 0) + (hasUpdErr ? 1 : 0) + (coreStatus === 'error' ? 1 : 0);
    totalErrors += siteErrors; totalOk += pOk + tOk;

    let rows = row('💾', 'Backup', b.status === 'OK' ? 'ok' : 'error', b.status || '—', b.status === 'OK' ? (b.size_mb != null ? `${b.size_mb} MB` : '—') : b.error || '—');
    if (!hasUpdErr) {
      const label = coreStatus === 'updated' ? 'Actualizado' : coreStatus === 'up_to_date' ? 'Al día' : coreStatus;
      rows += row('⚙️', 'WordPress Core', coreStatus === 'error' ? 'error' : 'ok', label, u.core?.new_version ? `v${u.core.new_version}` : u.core?.message || u.core?.error || '—');
    }
    rows += (u.plugins_updated || []).map((p) => row('🔌', p.name, 'ok', 'OK', `v${p.new_version}`)).join('');
    rows += (u.plugins_errors || []).map((p) => errRow('🔌', p, 'Plugin nulled / sin licencia — saltado')).join('');
    rows += (u.themes_updated || []).map((t) => row('🎨', t.name, 'ok', 'OK', `v${t.new_version}`)).join('');
    rows += (u.themes_errors || []).map((t) => errRow('🎨', t, 'Tema nulled / sin licencia — saltado')).join('');
    if (hasUpdErr) rows += `<tr><td>🔄</td><td colspan="3" style="color:#c62828">Error en actualización: ${esc(u.error)}</td></tr>`;

    cards += `<div class="site-card${siteErrors ? ' has-errors' : ''}"><div class="site-header"><span class="site-icon">${siteErrors ? '⚠️' : '✅'}</span><div><div class="site-name">${esc(s.site_name || s.site_url)}</div><div class="site-url">${esc(s.site_url)}</div></div><div class="site-summary"><span class="badge-ok">${pOk + tOk} actualizados</span>${siteErrors ? `<span class="badge-error">${siteErrors} error(es)</span>` : ''}</div></div><table><thead><tr><th></th><th>Componente</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }

  const status = totalErrors > 0 ? `⚠️ ${totalErrors} error(es)` : '✅ Todo OK';
  const subject = `[Nublyx] Reporte WP${MODE === 'check' ? ' (verificación)' : ''} — ${now.toLocaleDateString('es-AR', { timeZone: tz })} — ${status}`;
  const html = `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${CSS}</style></head><body><div class="container"><div class="header"><h1>🔄 Reporte de Mantenimiento WordPress</h1><p>Generado el ${esc(date)} · Nublyx Studio</p></div><div class="global-summary"><div class="stat"><div class="num num-sites">${all.length}</div><div class="label">Sitios</div></div><div class="stat"><div class="num num-ok">${totalOk}</div><div class="label">Actualizados</div></div><div class="stat"><div class="num num-err">${totalErrors}</div><div class="label">Con error</div></div></div><div class="body">${cards}</div><div class="footer">Sistema automático de mantenimiento WordPress · <strong>Nublyx Studio</strong> · No responder este email.</div></div></body></html>`;
  return { subject, html };
}

/* ───────────── Main ───────────── */
async function sendMail({ subject, html }) {
  const { SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, MAIL_FROM, MAIL_TO } = process.env;
  if (!SMTP_HOST || !MAIL_TO) {
    console.warn('SMTP_HOST / MAIL_TO no configurados: se omite el correo.');
    fs.writeFileSync('report.html', html);
    return;
  }
  const port = Number(SMTP_PORT || 465);
  const transport = nodemailer.createTransport({ host: SMTP_HOST, port, secure: port === 465, auth: { user: SMTP_USER, pass: SMTP_PASS } });
  await transport.sendMail({ from: MAIL_FROM || SMTP_USER, to: MAIL_TO, subject, html });
  console.log('Correo enviado.');
}

const sites = loadSites();
console.log(`Modo: ${MODE} · ${sites.length} sitio(s)`);
const results = [];
for (const site of sites) {
  console.log(`→ ${site.name}`);
  const r = await processSite(site); // secuencial, como n8n
  console.log(`  backup: ${r.backup?.status} · updates: ${r.updates?.error ? 'ERROR' : 'ok'}`);
  results.push(r);
}
await sendMail(buildReport(results));
