#!/usr/bin/env node
/*
 * Version PDF du guide technique (docs/technique/guide.json).
 *
 * Construit une page HTML d'impression (couverture, sommaire, chapitres,
 * modules, tables et relations, routes par module, contrôleurs, commandes,
 * droits, sécurité, synchronisation, puis la grille d'audit à remplir à la
 * main) et l'imprime en A4 avec Chromium (Playwright).
 *
 * Usage :
 *   node tools/guide-technique/pdf.mjs [--entree=docs/technique/guide.json] [--sortie=/mnt/project-files/technique]
 *
 * Variables :
 *   PW        chemin du module Playwright (défaut : « playwright », puis `npm root -g`/playwright)
 *   CHROMIUM  exécutable Chromium (défaut : celui de Playwright, puis /opt/pw-browsers/chromium)
 *
 * Écrit <sortie>/guide-technique.pdf et <sortie>/guide-technique-AAAA-MM-JJ.pdf
 * (date de génération du guide). Si pdftotext est installé, le sommaire porte
 * les numéros de page (deux passes) ; sinon il n'en a pas.
 */
import { createRequire } from 'node:module';
import { execSync, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE = path.resolve(ICI, '..', '..');
const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = a.match(/^--([^=]+)=(.*)$/); return m ? [m[1], m[2]] : [a.replace(/^--/, ''), true]; }));
const ENTREE = path.resolve(RACINE, args.entree || 'docs/technique/guide.json');
const SORTIE = path.resolve(args.sortie || '/mnt/project-files/technique');

function chargerPlaywright() {
  const essais = [process.env.PW, 'playwright'];
  try { essais.push(path.join(execSync('npm root -g', { encoding: 'utf8' }).trim(), 'playwright')); } catch (e) { /* npm absent */ }
  for (const m of essais.filter(Boolean)) { try { return require(m); } catch (e) { /* suivant */ } }
  throw new Error('Playwright introuvable : installer playwright ou donner son chemin dans PW.');
}

/* ---------- Outils ---------- */
const G = JSON.parse(fs.readFileSync(ENTREE, 'utf8'));
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const short = c => String(c || '').split('\\').pop();
const nf = new Intl.NumberFormat('fr-FR');
const n = x => nf.format(x || 0);
const mod = new Map(G.modules.map(m => [m.cle, m]));
const modLib = k => mod.get(k)?.libelle || k || '';
const dateGen = new Date(G.genere_le.date);
const jour = G.genere_le.date.slice(0, 10);
const dateFr = dateGen.toLocaleString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' }) + ' (UTC)';
const tags = l => (l || []).map(x => `<span class="tag">${esc(x)}</span>`).join(' ');
const ctrlMap = new Map(G.controleurs.map(c => [c.classe, c]));

/* Markdown minimal : titres, paragraphes, listes, tableaux, code, gras, italique, liens. */
function inline(t) {
  const codes = [];
  let s = esc(t).replace(/`([^`]+)`/g, (_, c) => { codes.push(c); return `\u0000${codes.length - 1}\u0000`; });
  s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/(^|[\s(])\*([^*\s][^*]*)\*/g, '$1<i>$2</i>')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1 <span class="dim">($2)</span>');
  return s.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${codes[i]}</code>`);
}
function markdown(md) {
  const L = md.replace(/\r/g, '').split('\n');
  const out = [];
  let i = 0;
  const cells = l => l.trim().replace(/^\|/, '').replace(/\|$/, '').split(/(?<!\\)\|/).map(c => c.trim().replace(/\\\|/g, '|'));
  while (i < L.length) {
    const l = L[i];
    if (!l.trim()) { i++; continue; }
    let m;
    if ((m = l.match(/^(#{1,4})\s+(.*)$/))) { const lv = Math.min(4, m[1].length + 1); out.push(`<h${lv} class="md-h">${inline(m[2])}</h${lv}>`); i++; continue; }
    if (l.startsWith('```')) { const buf = []; i++; while (i < L.length && !L[i].startsWith('```')) buf.push(L[i++]); i++; out.push(`<pre>${esc(buf.join('\n'))}</pre>`); continue; }
    if (/^\s*\|/.test(l) && i + 1 < L.length && /^\s*\|?\s*:?-{2,}/.test(L[i + 1])) {
      const head = cells(l); i += 2; const rows = [];
      while (i < L.length && /^\s*\|/.test(L[i])) rows.push(cells(L[i++]));
      out.push(`<table class="md-t"><thead><tr>${head.map(c => `<th>${inline(c)}</th>`).join('')}</tr></thead><tbody>${rows.map(r => `<tr>${r.map(c => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table>`);
      continue;
    }
    if (/^\s*([-*]|\d+\.)\s+/.test(l)) {
      const ordered = /^\s*\d+\./.test(l); const items = [];
      while (i < L.length && (/^\s*([-*]|\d+\.)\s+/.test(L[i]) || (/^\s{2,}\S/.test(L[i]) && items.length))) {
        if (/^\s*([-*]|\d+\.)\s+/.test(L[i])) items.push(L[i].replace(/^\s*([-*]|\d+\.)\s+/, '')); else items[items.length - 1] += ' ' + L[i].trim();
        i++;
      }
      out.push(`<${ordered ? 'ol' : 'ul'}>${items.map(x => `<li>${inline(x)}</li>`).join('')}</${ordered ? 'ol' : 'ul'}>`);
      continue;
    }
    if (l.startsWith('>')) { const buf = []; while (i < L.length && L[i].startsWith('>')) buf.push(L[i++].replace(/^>\s?/, '')); out.push(`<blockquote>${inline(buf.join(' '))}</blockquote>`); continue; }
    const buf = [];
    while (i < L.length && L[i].trim() && !/^(#{1,4}\s|```|\s*\||\s*([-*]|\d+\.)\s+|>)/.test(L[i])) buf.push(L[i++]);
    if (!buf.length) { buf.push(L[i++]); }
    out.push(`<p>${inline(buf.join(' '))}</p>`);
  }
  return out.join('\n');
}

/* ---------- Sections ---------- */
const toc = [];
let secNo = 0;
function section(titre, corps, { niveau = 1, id } = {}) {
  const num = niveau === 1 ? `${++secNo}` : '';
  const ident = id || 'S' + toc.length;
  toc.push({ id: ident, titre: niveau === 1 ? `${num}. ${titre}` : titre, niveau });
  const tag = niveau === 1 ? 'h1' : 'h2';
  return `<section class="${niveau === 1 ? 'sec' : 'sub'}"><${tag} id="${ident}">${niveau === 1 ? `${num}. ` : ''}${esc(titre)}</${tag}>${corps}</section>`;
}

function couverture() {
  const s = G.stats;
  const t = (v, l) => `<div class="ct"><b>${n(v)}</b><span>${esc(l)}</span></div>`;
  return `<div class="cover">
    <div class="mark"><svg width="64" height="64" viewBox="0 0 26 26" fill="none" stroke="#0a6a86" stroke-width="1.2"><circle cx="13" cy="13" r="7.5"/><circle cx="13" cy="13" r="3.2"/><path d="M13 1v24M1 13h24"/></svg></div>
    <p class="eyebrow">Succès Imprim · application Symfony</p>
    <h1 class="ctitle">Guide technique</h1>
    <p class="clead">Architecture, tables et relations, routes et leurs droits, parcours étape par étape, tâches planifiées, sécurité et synchronisation, tirés du code. Avec la grille d'audit à remplir.</p>
    <table class="kv"><tr><th>Généré le</th><td>${esc(dateFr)}</td></tr><tr><th>Commit</th><td class="mono">${esc(G.genere_le.commit)}</td></tr><tr><th>Branche</th><td class="mono">${esc(G.genere_le.branche)}</td></tr></table>
    <div class="ctiles">${t(s.entites, 'tables')}${t(s.routes, 'routes')}${t(s.routes_modifiant_des_donnees, 'routes qui modifient')}${t(s.routes_publiques, 'routes publiques')}${t(s.routes_sans_csrf_signalees, 'sans jeton CSRF')}${t(s.controleurs, 'contrôleurs')}${t(s.services, 'services')}${t(s.commandes, 'commandes')}${t(s.commandes_planifiees, 'planifiées')}${t(s.migrations, 'migrations')}${t(s.tests_unitaires, 'tests unitaires')}${t(s.droits, 'droits')}${t(s.voteurs, 'voteurs')}${t(s.types_synchronises, 'types synchronisés')}${t(s.tuiles_parametres, 'tuiles de réglages')}${t(s.audit, "points d'audit")}</div>
  </div>`;
}

function chapitres() {
  return G.chapitres.map((c, i) => {
    const md = c.markdown.replace(/^#\s+.*\n/, '');
    return section(c.titre, `<div class="md">${markdown(md)}</div>`, { id: 'chap-' + c.id });
  }).join('');
}

function modules() {
  const rows = G.modules.map(m => `<tr><td><b>${esc(m.libelle)}</b><div class="dim">${m.sorte === 'menu' ? 'menu' : 'technique'}</div></td><td class="num">${n(m.entites.length)}</td><td class="num">${n(m.routes.length)}</td><td class="num">${n(m.controleurs.length)}</td><td class="num">${n(m.services.length)}</td><td class="num">${n(m.commandes.length)}</td><td class="num">${n(G.audit.filter(a => a.module === m.cle).length)}</td></tr>`).join('');
  const det = G.modules.map(m => `<div class="avoid"><h3>${esc(m.libelle)}</h3>
    <p><span class="lbl">Tables</span> ${m.entites.map(e => `<span class="mono">${esc(short(e))}</span>`).join(', ') || '—'}</p>
    <p><span class="lbl">Contrôleurs</span> ${m.controleurs.map(e => `<span class="mono">${esc(short(e))}</span>`).join(', ') || '—'}</p>
    <p><span class="lbl">Commandes</span> ${m.commandes.map(e => `<span class="mono">${esc(e)}</span>`).join(', ') || '—'}</p></div>`).join('');
  return section('Modules', `<table class="t"><thead><tr><th>Module</th><th class="num">Tables</th><th class="num">Routes</th><th class="num">Contrôleurs</th><th class="num">Services</th><th class="num">Commandes</th><th class="num">Points d'audit</th></tr></thead><tbody>${rows}</tbody></table>${det}`);
}

function entites() {
  const entrants = new Map();
  for (const e of G.entites) for (const r of e.relations || []) { if (!entrants.has(r.cible)) entrants.set(r.cible, []); entrants.get(r.cible).push(`${short(e.classe)}.${r.nom}`); }
  const parMod = [...G.entites].sort((a, b) => G.modules.findIndex(m => m.cle === a.module) - G.modules.findIndex(m => m.cle === b.module) || short(a.classe).localeCompare(short(b.classe)));
  const corps = parMod.map(e => {
    const ch = e.champs.map(c => `<tr><td class="mono">${esc(c.nom)}${c.identifiant ? ' (id)' : ''}</td><td class="mono">${esc(c.colonne)}</td><td class="mono">${esc(c.type)}${c.longueur ? `(${c.longueur})` : c.precision != null ? `(${c.precision},${c.echelle ?? 0})` : ''}</td><td>${c.nullable ? 'oui' : 'non'}</td><td>${c.unique ? 'oui' : ''}</td><td class="mono">${esc(c.defaut_base ?? c.defaut_php ?? '')}</td></tr>`).join('');
    const rel = (e.relations || []).map(r => `<tr><td class="mono">${esc(r.nom)}</td><td>${esc(r.type)}</td><td class="mono">${esc(short(r.cible))}</td><td>${(r.colonnes_jointure || []).map(j => `<span class="mono">${esc(j.colonne)}</span>${j.onDelete ? ' ON DELETE ' + esc(j.onDelete) : ''}`).join(', ')}${r.table_jointure ? `<span class="mono">${esc(typeof r.table_jointure === 'string' ? r.table_jointure : (r.table_jointure.nom || JSON.stringify(r.table_jointure)))}</span>` : ''}${r.mappedBy ? 'mappedBy ' + esc(r.mappedBy) : ''}</td><td>${esc((r.cascade || []).join(', '))}${r.orphanRemoval ? ' orphanRemoval' : ''}</td></tr>`).join('');
    const inc = entrants.get(e.classe) || [];
    return `<div class="ent"><h3><span class="mono">${esc(short(e.classe))}</span> <span class="dim">· table <span class="mono">${esc(e.table)}</span> · ${esc(modLib(e.module))}</span></h3>
      ${e.role ? `<p>${esc(e.role)}</p>` : ''}<p class="dim mono small">${esc(e.fichier)}${e.synchronisee ? ` · synchronisée (${esc(e.synchronisee.sens)}, ${n(e.synchronisee.champs)} champs)` : ''}</p>
      <table class="t small"><thead><tr><th>Propriété</th><th>Colonne</th><th>Type</th><th>Nullable</th><th>Unique</th><th>Défaut</th></tr></thead><tbody>${ch}</tbody></table>
      ${rel ? `<table class="t small"><thead><tr><th>Relation</th><th>Type</th><th>Cible</th><th>Jointure</th><th>Cascade</th></tr></thead><tbody>${rel}</tbody></table>` : ''}
      ${inc.length ? `<p class="small"><span class="lbl">Pointée par</span> ${inc.map(x => `<span class="mono">${esc(x)}</span>`).join(', ')}</p>` : ''}
      ${(e.index || []).length ? `<p class="small"><span class="lbl">Index</span> ${e.index.map(x => `<span class="mono">${esc(x.nom)}</span> (${esc(x.colonnes.join(', '))})`).join(' ; ')}</p>` : ''}
      ${(e.contraintes_uniques || []).length ? `<p class="small"><span class="lbl">Uniques</span> ${e.contraintes_uniques.map(x => `<span class="mono">${esc(x.nom)}</span> (${esc(x.colonnes.join(', '))})`).join(' ; ')}</p>` : ''}
    </div>`;
  }).join('');
  return section('Tables et relations', `<p>${n(G.entites.length)} entités Doctrine, rangées par module. Pour chaque table : colonnes, relations sortantes (avec la règle ON DELETE), relations entrantes, index et contraintes uniques.</p>${corps}`);
}

function roles(r) {
  const c = ctrlMap.get(String(r.controleur).split('::')[0]);
  return [...new Set([...(r.acces?.access_control?.roles || []), ...(c?.is_granted_classe || []), ...(r.acces?.is_granted || [])])];
}
function routes() {
  const corps = G.modules.map(m => {
    const rs = G.routes.filter(r => r.module === m.cle);
    if (!rs.length) return '';
    return `<h2>${esc(m.libelle)} <span class="dim">(${n(rs.length)})</span></h2><table class="t xs"><thead><tr><th>Route et adresse</th><th>Méth.</th><th>Contrôleur</th><th>Accès</th><th>CSRF</th><th>Modifie</th><th>Public</th><th>Sensibles</th></tr></thead><tbody>${rs.map(r => `<tr><td><span class="mono b">${esc(r.nom)}</span><br><span class="mono dim">${esc(r.chemin)}</span></td><td>${esc((r.methodes.length ? r.methodes : ['tous']).join(','))}</td><td class="mono">${esc(short(String(r.controleur).split('::')[0]))}${String(r.controleur).includes('::') ? '::' + esc(String(r.controleur).split('::')[1]) : ''}</td><td>${esc(roles(r).join(', '))}<br><span class="dim">${esc(r.acces?.pare_feu || '')}</span></td><td>${r.sans_csrf ? '<b class="warn">sans jeton</b>' : r.csrf ? 'jeton' : r.csrf_detail ? '<span class="dim">sans objet</span>' : '—'}</td><td>${r.modifie_donnees ? 'oui' : ''}</td><td>${r.public ? '<b>public</b>' : ''}</td><td>${esc((r.sensibles || []).join(', ').replace(/_/g, ' '))}</td></tr>`).join('')}</tbody></table>`;
  }).join('');
  return section('Routes', `<p>${n(G.routes.length)} routes, par module. « Accès » réunit les rôles de la règle access_control, de IsGranted sur la classe et sur l'action, puis le pare-feu.</p>${corps}`);
}

function controleurs() {
  const rows = [...G.controleurs].sort((a, b) => short(a.classe).localeCompare(short(b.classe))).map(c => `<tr><td><span class="mono b">${esc(short(c.classe))}</span><br><span class="dim">${esc(c.resume || '')}</span></td><td>${esc(modLib(c.module))}</td><td class="num">${n(c.routes.length)}</td><td>${esc((c.is_granted_classe || []).join(', '))}</td><td class="num">${n(c.csrf?.avec_jeton)} / ${n(c.csrf?.routes_modifiant)}</td><td class="mono">${esc((c.entites_chargees || []).map(short).join(', '))}</td></tr>`).join('');
  return section('Contrôleurs', `<table class="t xs"><thead><tr><th>Contrôleur</th><th>Module</th><th class="num">Routes</th><th>IsGranted (classe)</th><th class="num">Jeton CSRF</th><th>Tables chargées</th></tr></thead><tbody>${rows}</tbody></table>`);
}

function commandes() {
  const rows = G.commandes.map(c => `<tr><td><span class="mono b">${esc(c.nom)}</span><br><span class="dim">${esc(c.description || '')}</span></td><td>${esc(modLib(c.module))}</td><td>${(c.planification || []).map(p => `<b>${esc(p.frequence)}</b><br><span class="dim">${esc(p.ou)}</span>`).join('<br>') || '<span class="dim">à la demande</span>'}</td><td>${[...(c.arguments || []).map(a => a.nom), ...(c.options || []).map(o => o.nom)].map(x => `<span class="mono">${esc(x)}</span>`).join(' ')}</td></tr>`).join('');
  return section('Commandes et tâches planifiées', `<p>${n(G.commandes.length)} commandes, dont ${n(G.stats.commandes_planifiees)} planifiées.</p><table class="t xs"><thead><tr><th>Commande</th><th>Module</th><th>Planification</th><th>Arguments et options</th></tr></thead><tbody>${rows}</tbody></table>`);
}

function droits() {
  const d = G.droits;
  const init = d.roles_par_defaut?.initiaux || [];
  const cat = (d.groupes || []).map(g => `<h2>${esc(g)}</h2><table class="t small"><thead><tr><th>Code</th><th>Libellé</th><th>Ce qu'il permet</th></tr></thead><tbody>${d.catalogue.filter(x => x.groupe === g).map(x => `<tr><td class="mono">${esc(x.code)}</td><td>${esc(x.libelle)}</td><td>${esc(x.description || '')}</td></tr>`).join('')}</tbody></table>`).join('');
  const mx = `<h2>Rôles par défaut</h2><p class="small">${esc(d.roles_par_defaut?.note || '')}</p><table class="t xs mx"><thead><tr><th>Droit</th>${init.map(r => `<th class="rot">${esc(r.nom)}</th>`).join('')}</tr></thead><tbody>${d.catalogue.map(x => `<tr><td class="mono">${esc(x.code)}</td>${init.map(r => `<td class="c">${r.code === 'ROLE_ADMIN' || r.droits.includes(x.code) ? '●' : ''}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  const mr = `<h2>Modules et rôles</h2><table class="t xs"><thead><tr><th>Module</th><th>Droits pour modifier</th><th>Lecture</th></tr></thead><tbody>${d.modules_roles.map(m => `<tr><td><b>${esc(m.libelle)}</b><br><span class="dim">${esc(m.detail || '')}</span></td><td class="mono">${esc((m.droits || []).join(', '))}</td><td class="mono">${esc(m.lecture || '')}</td></tr>`).join('')}</tbody></table>`;
  const vt = `<h2>Voteurs</h2><table class="t small"><thead><tr><th>Voteur</th><th>Attributs</th><th>Résumé</th></tr></thead><tbody>${d.voteurs.map(v => `<tr><td class="mono">${esc(short(v.classe))}</td><td class="mono">${esc((v.attributs || []).join(', '))}</td><td>${esc(v.resume || '')}</td></tr>`).join('')}</tbody></table>`;
  const hi = `<h2>Hiérarchie des rôles</h2>${Object.entries(d.role_hierarchy || {}).map(([k, v]) => `<p class="small"><span class="mono b">${esc(k)}</span> → ${v.map(x => `<span class="mono">${esc(x)}</span>`).join(', ')}</p>`).join('')}`;
  return section('Rôles et droits', cat + mx + mr + vt + hi);
}

function securite() {
  const S = G.securite;
  const kv = o => Object.entries(o || {}).map(([k, v]) => `<tr><th class="mono">${esc(k)}</th><td class="mono">${esc(typeof v === 'string' ? v : JSON.stringify(v))}</td></tr>`).join('');
  return section('Sécurité', `
    <h2>Routes qui modifient sans jeton CSRF (${n(S.routes_modifiant_sans_csrf.length)})</h2>
    <table class="t small"><thead><tr><th>Route</th><th>Adresse</th><th>Méthodes</th><th>Contrôleur</th></tr></thead><tbody>${S.routes_modifiant_sans_csrf.map(r => `<tr><td class="mono">${esc(r.nom)}</td><td class="mono">${esc(r.chemin)}</td><td>${esc(r.methodes.join(','))}</td><td class="mono">${esc(short(r.controleur))}</td></tr>`).join('')}</tbody></table>
    <h2>Pare-feux</h2><table class="t small"><thead><tr><th>Nom</th><th>Motif</th><th>Sécurité</th><th>Sans état</th><th>Authentificateur</th><th>Limite</th></tr></thead><tbody>${S.pare_feux.map(p => `<tr><td class="mono">${esc(p.nom)}</td><td class="mono">${esc(p.motif || '(le reste)')}</td><td>${p.securite ? 'oui' : '<b class="warn">non</b>'}</td><td>${p.sans_etat ? 'oui' : 'non'}</td><td class="mono">${esc(short(p.authentificateur || ''))}</td><td>${p.limite_connexions ? `${n(p.limite_connexions.max_attempts)} / ${esc(p.limite_connexions.interval)}` : ''}</td></tr>`).join('')}</tbody></table>
    <h2>access_control (la première règle qui correspond gagne)</h2><table class="t xs"><thead><tr><th class="num">N°</th><th>Chemin</th><th>Rôles</th><th>Méthodes</th></tr></thead><tbody>${S.access_control.map((r, i) => `<tr><td class="num">${i + 1}</td><td class="mono">${esc(r.chemin)}</td><td class="mono">${esc(r.roles.join(', '))}</td><td>${esc((r.methodes || []).join(', '))}</td></tr>`).join('')}</tbody></table>
    ${Object.entries(S.en_tetes || {}).map(([k, v]) => `<h2>En-têtes : ${esc(k.replace(/_/g, ' '))}</h2><table class="t xs kvt">${typeof v === 'object' ? kv(v) : `<tr><td>${esc(v)}</td></tr>`}</table>`).join('')}
    <h2>Cookie de session, mots de passe, CSRF</h2><table class="t xs kvt">${kv(S.cookie_session)}${kv(S.mots_de_passe)}<tr><th>CSRF sans état</th><td class="mono">${esc((S.csrf_configuration?.stateless_token_ids || []).join(', '))}</td></tr></table>
    <h2>Limiteurs de débit</h2><table class="t small"><thead><tr><th>Nom</th><th class="num">Limite</th><th>Intervalle</th><th>Utilisé par</th></tr></thead><tbody>${S.limiteurs.map(l => `<tr><td class="mono">${esc(l.nom)}</td><td class="num">${n(l.limite)}</td><td>${esc(l.intervalle)}</td><td>${esc((l.utilise_par || []).join(', '))}</td></tr>`).join('')}</tbody></table>
    <h2>Mécanismes</h2><table class="t xs"><thead><tr><th>Mécanisme</th><th>Ce qu'il fait</th></tr></thead><tbody>${S.mecanismes.map(m => `<tr><td><b>${esc(m.titre)}</b><br><span class="mono dim">${esc(m.fichier)}</span></td><td>${esc(m.resume)}</td></tr>`).join('')}</tbody></table>
    <h2>Séparation ERP local / site en ligne</h2>${(S.separation_en_ligne_local?.souscripteurs || []).map(s => `<p class="small"><span class="mono b">${esc(short(s.classe))}</span> : ${esc(s.resume)}</p>`).join('')}
    <h2>Écouteurs Doctrine</h2>${(S.ecouteurs_doctrine || []).map(s => `<p class="small"><span class="mono b">${esc(short(s.classe))}</span> (${esc((s.evenements || []).join(', '))}) : ${esc(s.resume)}</p>`).join('')}`);
}

function synchro() {
  const Y = G.synchro;
  return section('Synchronisation', `<p>Source : <span class="mono">${esc(Y.source)}</span>.</p>${Object.entries(Y.sens || {}).map(([k, v]) => `<p><b>${esc(k)}</b> : ${esc(v)}</p>`).join('')}
    <table class="t xs"><thead><tr><th>Type</th><th>Entité</th><th>Sens</th><th>Champs envoyés</th></tr></thead><tbody>${Y.types.map(t => `<tr><td class="mono">${esc(t.type)}</td><td class="mono">${esc(short(t.classe))}</td><td>${esc(t.sens)}</td><td class="mono">${esc((t.champs || []).join(', '))}</td></tr>`).join('')}</tbody></table>`);
}

function reglages() {
  const P = G.parametres;
  return section('Réglages', Object.entries(P.familles).map(([k, f]) => `<h2>${esc(f.libelle)}</h2><table class="t xs"><thead><tr><th style="width:38%">Tuile, rôles et route</th><th>Réglages</th></tr></thead><tbody>${P.tuiles.filter(t => t.famille === k).map(t => `<tr><td><b>${esc(t.libelle)}</b>${t.publique ? ' <span class="dim">(envoyée au site)</span>' : ''}<br><span class="mono">${esc((t.roles || []).join(', '))}</span><br><span class="mono dim">${esc(t.route || '')}</span></td><td>${(t.champs || []).map(c => esc(c.libelle)).join(' ; ')}</td></tr>`).join('')}</tbody></table>`).join(''));
}

function grille() {
  const TYPES = { controleur: 'Contrôleurs', entite: 'Tables', route: 'Routes', securite: 'Sécurité', commande: 'Commandes' };
  const ordreT = Object.keys(TYPES);
  const items = [...G.audit].sort((a, b) => G.modules.findIndex(m => m.cle === a.module) - G.modules.findIndex(m => m.cle === b.module) || ordreT.indexOf(a.type) - ordreT.indexOf(b.type));
  let cle = '', html = '';
  for (const a of items) {
    const k = a.module + '|' + a.type;
    if (k !== cle) { cle = k; html += `<h2 class="ag">${esc(modLib(a.module))} · ${esc(TYPES[a.type] || a.type)} <span class="dim">(${n(items.filter(x => x.module + '|' + x.type === k).length)})</span></h2>`; }
    html += `<div class="ai"><div class="ai-h"><b>${esc(a.titre)}</b> <span class="mono dim">${esc(a.id)}</span></div><p>${esc(a.quoi_verifier)}</p><p class="mono dim">${esc(a.fichier)}</p>
      <div class="boxes"><span><i></i>Confirmé</span><span><i></i>À corriger</span><span><i></i>Question</span><span class="date">Date : ……………… Initiales : ………</span></div><div class="line">Commentaire :</div><div class="line"></div></div>`;
  }
  return section("Grille d'audit", `<p>Un point par contrôleur, table, route sensible, tâche planifiée et mécanisme de sécurité (${n(G.audit.length)} points). Cocher une case et noter un commentaire. L'identifiant (en gris) est stable d'une génération à l'autre : il sert à reporter la réponse dans la page en ligne.</p>${html}`);
}

/* ---------- Page ---------- */
const CSS = `
@page { size: A4; margin: 16mm 14mm 18mm; }
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; font: 9.6pt/1.45 "IBM Plex Sans", "Carlito", "Liberation Sans", "DejaVu Sans", Arial, sans-serif; color: #13212a; }
.mono, code { font-family: "IBM Plex Mono", "DejaVu Sans Mono", "Liberation Mono", monospace; font-size: .86em; overflow-wrap: anywhere; }
code { background: #eef3f5; padding: 0 .25em; border-radius: 2px; }
h1, h2, h3 { font-family: "IBM Plex Sans Condensed", "Liberation Sans Narrow", "Carlito", "Liberation Sans", sans-serif; line-height: 1.2; break-after: avoid; }
h1 { font-size: 20pt; margin: 0 0 10pt; color: #0a6a86; border-bottom: 2pt solid #0a6a86; padding-bottom: 4pt; }
h2 { font-size: 13pt; margin: 14pt 0 6pt; }
h3 { font-size: 11pt; margin: 12pt 0 4pt; }
h4 { font-size: 10pt; margin: 10pt 0 4pt; }
p { margin: 0 0 6pt; }
.sec { break-before: page; }
.dim { color: #566871; }
.small { font-size: 8.6pt; }
.b { font-weight: 600; }
.warn { color: #b02a20; }
.lbl { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .06em; color: #566871; font-weight: 600; margin-right: 4pt; }
table { border-collapse: collapse; width: 100%; margin: 0 0 8pt; }
.t th, .t td, .md-t th, .md-t td { border-bottom: .5pt solid #c8d3d8; padding: 2.5pt 4pt; text-align: left; vertical-align: top; }
.t th, .md-t th { background: #e9eef0; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .04em; color: #3d4f58; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
.t.small { font-size: 8.4pt; } .t.xs { font-size: 7.6pt; } .md-t { font-size: 8pt; }
.num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
.kvt th { width: 30%; background: none; text-transform: none; font-size: 7.6pt; letter-spacing: 0; }
.mx td.c { text-align: center; color: #0a6a86; }
.mx th.rot { font-size: 6.5pt; text-transform: none; letter-spacing: 0; text-align: center; }
.ent { break-inside: auto; border-top: 1pt solid #13212a; margin-top: 10pt; padding-top: 2pt; }
.ent h3 { margin-top: 4pt; }
.avoid { break-inside: avoid; }
ul, ol { margin: 0 0 6pt; padding-left: 16pt; }
blockquote { margin: 0 0 6pt; padding-left: 8pt; border-left: 2pt solid #b7c5cb; color: #566871; }
pre { background: #eef3f5; padding: 6pt; font-size: 8pt; white-space: pre-wrap; }
.cover { min-height: 250mm; display: flex; flex-direction: column; gap: 10pt; padding-top: 30mm; }
.cover .eyebrow { font-size: 9pt; text-transform: uppercase; letter-spacing: .1em; color: #566871; font-weight: 600; margin: 0; }
.ctitle { font-size: 40pt; border: 0; color: #13212a; margin: 0; }
.clead { font-size: 12pt; max-width: 130mm; color: #3d4f58; }
.cover .kv { width: auto; margin: 8pt 0; } .cover .kv th { text-align: left; padding: 2pt 14pt 2pt 0; color: #566871; font-weight: 400; } .cover .kv td { padding: 2pt 0; }
.ctiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6pt; margin-top: 10pt; }
.ct { border: .75pt solid #c8d3d8; border-radius: 3pt; padding: 6pt 8pt; display: grid; }
.ct b { font-family: "IBM Plex Sans Condensed", "Carlito", sans-serif; font-size: 17pt; font-variant-numeric: tabular-nums; }
.ct span { font-size: 8pt; color: #566871; }
.toc { break-before: page; }
.toc ol { list-style: none; padding: 0; }
.toc li { display: flex; gap: 6pt; align-items: baseline; padding: 2.5pt 0; }
.toc li.n2 { padding-left: 14pt; font-size: 9pt; color: #3d4f58; }
.toc li .fill { flex: 1; border-bottom: .75pt dotted #b7c5cb; }
.toc li .pg { font-variant-numeric: tabular-nums; min-width: 22pt; text-align: right; }
.ag { border-bottom: 1.5pt solid #13212a; padding-bottom: 2pt; }
.ai { break-inside: avoid; border-bottom: .5pt solid #c8d3d8; padding: 5pt 0 6pt; font-size: 8.6pt; }
.ai p { margin: 2pt 0; }
.ai-h b { font-size: 9.4pt; }
.boxes { display: flex; gap: 14pt; align-items: center; margin-top: 4pt; font-size: 9pt; }
.boxes span { display: inline-flex; align-items: center; gap: 4pt; }
.boxes i { display: inline-block; width: 9pt; height: 9pt; border: 1pt solid #13212a; border-radius: 1.5pt; }
.boxes .date { margin-left: auto; color: #566871; font-size: 8pt; }
.line { border-bottom: .6pt solid #9aa9b0; height: 15pt; color: #566871; font-size: 8pt; padding-top: 3pt; }
`;

function tocHtml(pages) {
  return `<div class="toc"><h1>Sommaire</h1><ol>${toc.map(t => `<li class="n${t.niveau}"><span>${esc(t.titre)}</span><span class="fill"></span><span class="pg">${pages?.[t.id] ?? ''}</span></li>`).join('')}</ol></div>`;
}

function build() {
  toc.length = 0; secNo = 0;
  const corps = chapitres() + modules() + entites() + routes() + controleurs() + commandes() + droits() + securite() + synchro() + reglages() + grille();
  return (pages) => `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Guide technique Succès Imprim</title><style>${CSS}</style></head><body>${couverture()}${tocHtml(pages)}${corps}</body></html>`;
}

const footer = `<div style="width:100%;font:7.5pt 'IBM Plex Sans','Carlito','Liberation Sans',sans-serif;color:#566871;padding:0 14mm;display:flex;justify-content:space-between"><span>Guide technique Succès Imprim · ${esc(jour)} · ${esc(G.genere_le.commit.slice(0, 8))}</span><span>page <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>`;

async function imprimer(browser, html, fichier) {
  const page = await browser.newPage();
  await page.setContent(html, { waitUntil: 'load' });
  await page.pdf({ path: fichier, format: 'A4', printBackground: true, displayHeaderFooter: true, headerTemplate: '<div></div>', footerTemplate: footer, margin: { top: '16mm', bottom: '18mm', left: '14mm', right: '14mm' } });
  await page.close();
}

function numerosDePage(fichier, titres) {
  let texte;
  try { texte = execFileSync('pdftotext', ['-layout', fichier, '-'], { encoding: 'utf8', maxBuffer: 512 * 1024 * 1024 }); } catch (e) { return null; }
  const pages = texte.split('\f').map(p => p.replace(/\s+/g, ' '));
  const debut = pages.findIndex((p, i) => i > 0 && p.includes('Sommaire'));
  const res = {}; let from = debut + 1;
  for (const t of titres) {
    const cible = t.titre.replace(/\s+/g, ' ');
    for (let i = from; i < pages.length; i++) if (pages[i].includes(cible)) { res[t.id] = i + 1; from = i; break; }
  }
  return res;
}

(async () => {
  const { chromium } = chargerPlaywright();
  let browser;
  try { browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {}); }
  catch (e) { browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); }
  fs.mkdirSync(SORTIE, { recursive: true });
  const page = build();
  const tmp = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'guide-pdf-')), 'passe1.pdf');
  await imprimer(browser, page(null), tmp);
  const nums = numerosDePage(tmp, toc.filter(t => t.niveau === 1));
  const fichier = path.join(SORTIE, 'guide-technique.pdf');
  if (nums) await imprimer(browser, page(nums), fichier); else fs.copyFileSync(tmp, fichier);
  await browser.close();
  const date = path.join(SORTIE, `guide-technique-${jour}.pdf`);
  fs.copyFileSync(fichier, date);
  let pages = '?';
  try { pages = execFileSync('pdfinfo', [fichier], { encoding: 'utf8' }).match(/Pages:\s+(\d+)/)[1]; } catch (e) { const b = fs.readFileSync(fichier, 'latin1'); pages = (b.match(/\/Type\s*\/Page[^s]/g) || []).length; }
  const mo = (fs.statSync(fichier).size / 1048576).toFixed(1);
  console.log(`PDF écrit : ${fichier}\nCopie datée : ${date}\nPages : ${pages} · ${mo} Mo · sommaire ${nums ? 'avec' : 'sans'} numéros de page`);
})().catch(e => { console.error(e); process.exit(1); });
