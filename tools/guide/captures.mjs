#!/usr/bin/env node
/*
 * Captures d'écran du guide utilisateur (docs/guide-utilisateur.md).
 *
 * Joue chaque fiche dans un vrai navigateur avec le compte de
 * démonstration du rôle, puis enregistre les images en WebP dans
 * ressources/guide/captures/ (hors de public/ : AideController les sert
 * avec les droits de la fiche).
 *
 * À lancer sur une base remplie par `php bin/console app:demo` (données de
 * démonstration seulement), jamais sur la base de l'imprimerie : le
 * parcours crée un client, un devis, une commande, un paiement, une
 * facture, et valide une remise de livraison.
 *
 * Variables :
 *   BASE_URL       adresse de l'ERP local (défaut http://127.0.0.1:8000)
 *   PLAYWRIGHT     module Playwright (défaut « playwright »)
 *   CHROMIUM       exécutable Chromium (défaut : celui de Playwright)
 *   DEMO_MDP       mot de passe des comptes demo.* (défaut demo1234)
 *   POINTAGE_LIEN  chemin de l'affiche de pointage (/pointer/<code>/<secret>)
 *                  pour la capture « pointer-photo » ; sans lui, elle est sautée
 *   SEULES         noms de captures à refaire, séparés par des virgules
 *   COPIE          dossier où copier aussi les images (relecture)
 *   QUALITE        qualité WebP entre 0 et 1 (défaut 0.7)
 *
 * Le serveur doit faire confiance à 127.0.0.1 comme relais
 * (SYMFONY_TRUSTED_PROXIES=127.0.0.1) : le poste de machine de « Ma file »
 * est reconnu par son adresse, envoyée en X-Forwarded-For.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const SORTIE = path.join(RACINE, 'ressources/guide/captures');
const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const MDP = process.env.DEMO_MDP || 'demo1234';
const QUALITE = Number(process.env.QUALITE || 0.7);
const SEULES = (process.env.SEULES || '').split(',').map((s) => s.trim()).filter(Boolean);
const COPIE = process.env.COPIE || '';
const POSTE_MACHINE = '10.90.0.12'; // « Solvant 1,80 m » des données de démonstration
const BUREAU = { width: 1440, height: 900 };
const TELEPHONE = { width: 390, height: 844 };

const { chromium } = await import(process.env.PLAYWRIGHT || 'playwright');
const navigateur = await chromium.launch({
    executablePath: process.env.CHROMIUM || undefined,
    // Caméra simulée : pointage et entretien demandent une photo.
    args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
});
fs.mkdirSync(SORTIE, { recursive: true });
if (COPIE) fs.mkdirSync(COPIE, { recursive: true });

const faites = [];
const erreurs = [];

/* ---------- Outils ---------- */

const sessions = new Map();

/** Page connectée avec un compte de démonstration (une par compte, taille et poste). */
async function session(compte, { mobile = false, ip = null } = {}) {
    const cle = `${compte}|${mobile}|${ip}`;
    if (sessions.has(cle)) return sessions.get(cle);
    const contexte = await navigateur.newContext({
        viewport: mobile ? TELEPHONE : BUREAU,
        deviceScaleFactor: 1,
        isMobile: mobile,
        hasTouch: mobile,
        locale: 'fr-FR',
        timezoneId: 'Africa/Bamako',
        permissions: ['camera', 'geolocation'],
        geolocation: { latitude: 12.6392, longitude: -8.0029 },
        extraHTTPHeaders: ip ? { 'X-Forwarded-For': ip } : {},
    });
    const page = await contexte.newPage();
    page.on('dialog', (d) => d.accept());
    if (compte) {
        await page.goto(`${BASE}/login`);
        await page.fill('#inputUsername', compte);
        await page.fill('#inputPassword', MDP);
        await Promise.all([page.waitForLoadState('networkidle'), page.getByRole('button', { name: 'Se connecter' }).click()]);
        if (page.url().includes('/login')) throw new Error(`Connexion refusée pour ${compte}`);
    }
    sessions.set(cle, page);
    return page;
}

/**
 * Attend la page prête : préchargeur parti, barre de débogage et bandeau
 * des alertes critiques du poste (synchronisation…) cachés, pas d'animation.
 */
async function pret(page) {
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForFunction(() => !document.getElementById('preloader'), null, { timeout: 10000 }).catch(() => {});
    await page.addStyleTag({ content: '.sf-toolbar,.sf-toolbarreset,.sf-minitoolbar,[data-si-banniere]{display:none!important} *,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}' }).catch(() => {});
    // Aperçus en cadre (facture) : sans la barre de débogage non plus.
    for (const cadre of page.frames().slice(1)) {
        await cadre.addStyleTag({ content: '.sf-toolbar,.sf-toolbarreset,.sf-minitoolbar{display:none!important}' }).catch(() => {});
    }
    await page.waitForTimeout(400);
}

async function aller(page, chemin) {
    const reponse = await page.goto(BASE + chemin);
    await pret(page);
    if (!reponse || reponse.status() >= 400) throw new Error(`${chemin} : réponse ${reponse?.status()}`);

    return reponse;
}

/** Clique et attend la page suivante. */
async function suivre(page, cible) {
    await Promise.all([page.waitForNavigation(), typeof cible === 'string' ? page.click(cible) : cible.click()]);
    await pret(page);
}

let convertisseur = null;

/** PNG → WebP dans le navigateur (canvas), sans outil en plus. */
async function enWebp(png) {
    if (!convertisseur) convertisseur = await (await navigateur.newContext()).newPage();
    const b64 = await convertisseur.evaluate(async ({ donnees, qualite }) => {
        const image = new Image();
        image.src = `data:image/png;base64,${donnees}`;
        await image.decode();
        const toile = document.createElement('canvas');
        toile.width = image.naturalWidth;
        toile.height = image.naturalHeight;
        toile.getContext('2d').drawImage(image, 0, 0);

        return toile.toDataURL('image/webp', qualite).split(',')[1];
    }, { donnees: png.toString('base64'), qualite: QUALITE });

    return Buffer.from(b64, 'base64');
}

/** Enregistre la capture <nom>.webp ; clip : zone à garder (sinon l'écran visible). */
async function capture(page, nom, { clip = null } = {}) {
    if (SEULES.length && !SEULES.includes(nom)) return;
    const png = await page.screenshot(clip ? { clip } : {});
    const webp = await enWebp(png);
    fs.writeFileSync(path.join(SORTIE, `${nom}.webp`), webp);
    if (COPIE) fs.writeFileSync(path.join(COPIE, `${nom}.webp`), webp);
    faites.push(`${nom} (${Math.round(webp.length / 1024)} Ko)`);
}

/** Une scène joue un écran ou un parcours ; une erreur est notée et la suite continue. */
async function scene(noms, jouer) {
    if (SEULES.length && !noms.some((n) => SEULES.includes(n))) return;
    try {
        await jouer();
    } catch (e) {
        erreurs.push(`${noms.join(', ')} : ${e.message.split('\n')[0]}`);
    }
}

/** Code de la fiche dans l'adresse (/commandes/ADWlzbNUR, docs/ids-chiffres.md). */
function codeDe(page, dossier) {
    return page.url().match(new RegExp(`/${dossier}/([0-9A-Za-z]+)`))[1];
}

/** Lien d'une ligne de tableau qui contient un texte (null s'il n'y en a pas). */
async function lienDe(page, ligne, lien = 'a[href]') {
    const cible = page.locator('tr', { hasText: ligne }).locator(lien).first();

    return (await cible.count()) ? cible.getAttribute('href') : null;
}

/** Zone de l'écran visible au-dessus d'un élément (pour laisser une liste hors de l'image). */
async function auDessusDe(page, selecteur) {
    const boite = await page.locator(selecteur).first().boundingBox();
    const hauteur = Math.min(BUREAU.height, Math.max(200, Math.floor(boite ? boite.y - 8 : BUREAU.height)));

    return { x: 0, y: 0, width: BUREAU.width, height: hauteur };
}

/* ---------- Premiers pas : le parcours J7 ---------- */

const essai = { prenom: 'Fatoumata', nom: 'Traoré Guide', telephone: `6${String(Date.now()).slice(-7)}` };
const ids = {};

await scene(['connexion'], async () => {
    const page = await session(null, { mobile: true });
    await aller(page, '/login');
    await capture(page, 'connexion');
});

await scene(['client-nouveau', 'devis-saisie', 'devis-accepte', 'caisse-encaissement', 'caisse-recu', 'commande-fiche', 'facture-fiche', 'acces-espace'], async () => {
    const page = await session('demo.aminata');

    // Nouveau client, puis « Enregistrer et créer un devis ».
    await aller(page, '/clients/nouveau');
    await page.click('label:has-text("Particulier")');
    await page.fill('input[name="c[prenom]"]', essai.prenom);
    await page.fill('input[name="c[nom]"]', essai.nom);
    await page.fill('input[name="c[telephone]"]', essai.telephone);
    await page.locator('input[name="c[telephone]"]').press('Tab');
    await page.waitForTimeout(600);
    await capture(page, 'client-nouveau');
    await suivre(page, page.getByRole('button', { name: 'Enregistrer et créer un devis' }));

    // Devis : une bâche de 300 × 100 cm avec œillets.
    await page.getByText('+ Ajouter une ligne').first().click();
    await page.waitForTimeout(600);
    await page.click('#select2-devis_devisDetails_0_produit-container');
    await page.fill('.select2-container--open .select2-search__field', 'Bâche');
    await page.waitForTimeout(600);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(1200);
    await page.fill('[name="devis[devisDetails][0][largeur]"]', '300');
    await page.fill('[name="devis[devisDetails][0][longueur]"]', '100');
    await page.locator('[name="devis[devisDetails][0][longueur]"]').press('Tab');
    await page.getByText('Œillets tous les 50 cm').click();
    await page.waitForTimeout(1000);
    await page.locator('[name="devis[devisDetails][0][largeur]"]').scrollIntoViewIfNeeded();
    await capture(page, 'devis-saisie');
    await suivre(page, page.getByRole('button', { name: 'Enregistrer le devis' }));
    ids.devis = codeDe(page, 'devis');

    // Accepté, puis « Valider et créer la commande ».
    await suivre(page, page.getByRole('link', { name: 'Modifier' }).first());
    const statut = await page.evaluate(() => [...document.querySelectorAll('select')].find((s) => [...s.options].some((o) => o.text.trim() === 'Accepté'))?.name);
    await page.selectOption(`select[name="${statut}"]`, { label: 'Accepté' });
    await suivre(page, page.getByRole('button', { name: 'Enregistrer les modifications' }));
    await capture(page, 'devis-accepte');
    await suivre(page, page.getByRole('button', { name: 'Valider et créer la commande' }));
    ids.commande = codeDe(page, 'commandes');

    // Caisse : le client, sa commande, 5 000 F en espèces.
    await aller(page, '/caisse');
    const valeur = await page.evaluate((nom) => [...document.querySelectorAll('#caisse-client option')].find((o) => o.text.includes(nom))?.value, `${essai.prenom} ${essai.nom}`);
    await Promise.all([page.waitForNavigation(), page.selectOption('#caisse-client', valeur)]);
    await pret(page);
    await suivre(page, '.si-caisse-table a');
    await page.fill('.si-caisse-montant input', '5000');
    await page.click('.si-caisse-mode[data-mode="especes"]');
    await page.waitForTimeout(400);
    await capture(page, 'caisse-encaissement');
    const recu = page.context().waitForEvent('page', { timeout: 8000 }).catch(() => null);
    await suivre(page, page.getByRole('button', { name: /Encaisser et imprimer le reçu/ }));
    (await recu)?.close();
    if (!(await page.getByText(/Paiement de .* enregistré/).count())) throw new Error('pas de message « Paiement … enregistré »');
    await capture(page, 'caisse-recu');

    // Facture depuis la commande : le brouillon, puis « Valider et numéroter ».
    await aller(page, `/commandes/${ids.commande}`);
    await page.getByRole('button', { name: 'Autres actions' }).click();
    await suivre(page, page.getByRole('button', { name: 'Facturer' }));
    ids.facture = codeDe(page, 'factures');
    await page.getByRole('button', { name: 'Valider et numéroter' }).click();
    await suivre(page, page.locator('.si-confirmation__ok'));

    // Accès à l'espace client, code masqué sur l'image.
    await aller(page, `/clients?q=${essai.telephone}`);
    await suivre(page, page.getByRole('link', { name: new RegExp(essai.nom) }).first());
    await suivre(page, page.getByRole('button', { name: 'Envoyer l\'accès à l\'espace client' }));
    await page.evaluate(() => {
        const message = document.getElementById('message-acces');
        message.textContent = message.textContent.replace(/(Code d'accès : ).*/, '$1••• •••');
        // Avertissement du poste de développement (adresse du site non réglée) : absent à l'imprimerie.
        document.querySelectorAll('.si-acces-espace .alert-warning').forEach((e) => e.remove());
    });
    await capture(page, 'acces-espace');
});

/* ---------- Le graphiste voit les ventes sans les paiements ---------- */

await scene(['clients-liste', 'client-fiche', 'commande-fiche', 'facture-fiche', 'ventes', 'commande-en-ligne'], async () => {
    const page = await session('demo.seydou');
    await aller(page, '/clients');
    await capture(page, 'clients-liste');
    await aller(page, '/clients?q=Salon%20Espoir');
    await suivre(page, page.getByRole('link', { name: 'Salon Espoir' }).first());
    await capture(page, 'client-fiche');
    if (ids.commande) {
        await aller(page, `/commandes/${ids.commande}`);
        await capture(page, 'commande-fiche');
    }
    // La facture validée du parcours, sinon une facture validée de la démonstration.
    let facture = ids.facture ? `/factures/${ids.facture}` : null;
    if (!facture && (!SEULES.length || SEULES.includes('facture-fiche'))) {
        await aller(page, '/factures/?type=facture');
        facture = await lienDe(page, /F-D\d+[\s\S]*Validée · échéance/, 'td:nth-child(2) a');
    }
    if (facture) {
        await aller(page, facture);
        await capture(page, 'facture-fiche');
    }
    await aller(page, '/ventes');
    await capture(page, 'ventes');

    // Une commande passée dans l'espace client, encore à confirmer.
    await aller(page, '/ventes');
    const enLigne = await page.evaluate(() => [...document.querySelectorAll('a[href*="/commandes/"]')].filter((a) => /CMD-DC/.test(a.innerText)).map((a) => a.getAttribute('href')));
    let trouvee = false;
    for (const lien of enLigne) {
        await aller(page, lien);
        if (await page.getByText('Commande passée par le client dans son espace').count()) {
            trouvee = true;
            break;
        }
    }
    if (!trouvee) throw new Error('aucune commande du site à confirmer');
    await capture(page, 'commande-en-ligne');
});

await scene(['prepresse', 'prepresse-controle', 'pao-tableau', 'pao-poste', 'montage', 'demandes-conception', 'creations'], async () => {
    const page = await session('demo.seydou');
    await aller(page, '/pre-presse/');
    await capture(page, 'prepresse');
    await aller(page, '/pre-presse/?onglet=a_controler');
    await suivre(page, page.locator('.pq-actions a', { hasText: 'Contrôler' }).first());
    await page.getByText('Contrôle de l\'infographiste').scrollIntoViewIfNeeded();
    await capture(page, 'prepresse-controle');
    await aller(page, '/atelier/pao');
    await capture(page, 'pao-tableau');
    await aller(page, '/atelier/pao/poste');
    await capture(page, 'pao-poste');
    await aller(page, '/atelier/montage');
    await page.getByText('Exemple carte de visite').click();
    await page.waitForTimeout(1500);
    await capture(page, 'montage');
    await aller(page, '/atelier/demandes-conception');
    await capture(page, 'demandes-conception');
    await aller(page, '/ventes/creations-atelier');
    await capture(page, 'creations');
});

// Prépresse (docs/prepresse.md) : dépôt groupé, tableau, relances, lignes retirées.
await scene(['prepresse-depot', 'prepresse-tableau', 'prepresse-relances', 'prepresse-retirees'], async () => {
    const page = await session('demo.seydou');

    // Dépôt : trois images sans contenu, dont deux avec un format dans le nom. Fermé sans enregistrer : rien n'est rattaché.
    if (!SEULES.length || SEULES.includes('prepresse-depot')) {
        await aller(page, '/pre-presse/?onglet=sans_fichier');
        const commande = page.locator('[data-depot-url]', { has: page.locator('.pq-actions [data-pq-deposer]') });
        const choisie = (await commande.filter({ hasText: 'formats' }).count()) ? commande.filter({ hasText: 'formats' }).first() : commande.first();
        await choisie.locator('.pq-actions [data-pq-deposer]').click();
        await page.locator('#pq-depot').waitFor();
        const formats = await choisie.evaluate((e) => [...e.querySelectorAll('button[data-pq-ligne]')].map((b) => (b.getAttribute('aria-label') || '').replace(/^.*format\s+/, '')));
        const nom = (f, i) => `visuel-${i + 1}_${(f || '').replace(/\s*×\s*/g, 'x').replace(/[^0-9,x.a-z]/gi, '') || 'a3'}.png`;
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        const fichiers = [...formats.slice(0, 2).map((f, i) => ({ name: nom(f, i), mimeType: 'image/png', buffer: png })), { name: 'photo-client.png', mimeType: 'image/png', buffer: png }];
        await page.locator('#pq-depot [data-pq-fichiers]').setInputFiles(fichiers);
        await page.locator('#pq-depot [data-pq-depot-table]:not([hidden])').waitFor({ timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(2500);
        await capture(page, 'prepresse-depot');
        await page.locator('#pq-depot .pq-fermer').click();
        await page.waitForTimeout(1500);
    }

    await aller(page, '/pre-presse/tableau');
    await capture(page, 'prepresse-tableau');
    await aller(page, '/pre-presse/relances');
    await capture(page, 'prepresse-relances');

    // Une ligne retirée puis remise : la page « Retirées » n'est pas vide sur l'image.
    if (!SEULES.length || SEULES.includes('prepresse-retirees')) {
        await aller(page, '/pre-presse/?onglet=sans_fichier');
        await page.locator('.pq-actions button', { hasText: 'Retirer' }).last().click();
        await suivre(page, page.locator('.si-confirmation__ok'));
        await aller(page, '/pre-presse/retirees');
        await capture(page, 'prepresse-retirees');
        await suivre(page, page.getByRole('button', { name: 'Remettre dans la liste' }).first());
    }
});

/* ---------- Caisse et commercial ---------- */

await scene(['aide-index', 'commande-nouvelle', 'mes-actions', 'caisse-cloture', 'caisse-virement', 'depense', 'encaissements'], async () => {
    const page = await session('demo.aminata');
    await aller(page, '/aide');
    await capture(page, 'aide-index');
    await aller(page, '/commandes/new');
    await capture(page, 'commande-nouvelle');

    await aller(page, '/caisse/actions');
    await capture(page, 'mes-actions');

    await aller(page, '/caisse');
    await page.getByRole('button', { name: 'Clôturer la caisse' }).click();
    await page.waitForTimeout(800);
    await page.locator('#modale-cloture input[type="number"], #modale-cloture input[inputmode]').first().fill('25000');
    await capture(page, 'caisse-cloture');

    await aller(page, '/caisse');
    await suivre(page, page.getByRole('link', { name: 'Virement' }));
    await page.fill('[name="mouvement_tresorerie[montant]"]', '50000');
    await page.selectOption('[name="mouvement_tresorerie[compteSource]"]', { label: 'Caisse imprimerie — Caisse' });
    await page.selectOption('[name="mouvement_tresorerie[compteDestination]"]', { label: 'Banque BDM — Banque' });
    await page.fill('[name="mouvement_tresorerie[libelle]"]', 'Versement de la caisse à la banque');
    await page.locator('[name="mouvement_tresorerie[compteSource]"]').scrollIntoViewIfNeeded();
    await capture(page, 'caisse-virement');

    await aller(page, '/finances/depenses');
    await page.locator('#saisir-depense').scrollIntoViewIfNeeded().catch(() => {});
    await page.getByText('Saisir une dépense').first().scrollIntoViewIfNeeded();
    await capture(page, 'depense');

    await aller(page, '/finances/encaissements');
    await capture(page, 'encaissements');
});

// Facturation (FD01 à FD07) avec la caisse : liste, nouveau document, paiement, avoir, documents liés ; la case « Hors imprimerie ».
await scene(['factures-liste', 'facture-nouveau-document', 'facture-paiement', 'avoir-saisie', 'documents-lies', 'hors-imprimerie'], async () => {
    const page = await session('demo.aminata');
    await aller(page, '/factures/');
    await capture(page, 'factures-liste');
    await page.getByRole('button', { name: '+ Nouveau document' }).click();
    await page.waitForTimeout(500);
    await capture(page, 'facture-nouveau-document');

    // Une facture validée qui attend un paiement : celle du parcours, sinon une de la liste. Rien n'est enregistré.
    let facture = ids.facture ? `/factures/${ids.facture}` : null;
    if (!facture) {
        await aller(page, '/factures/?type=facture');
        facture = await lienDe(page, /F-D\d+[\s\S]*Validée · échéance/, 'td:nth-child(2) a');
    }
    if (!facture) throw new Error('aucune facture validée à encaisser');
    await aller(page, facture);
    await page.getByRole('button', { name: 'Enregistrer un paiement' }).click();
    await page.locator('#fd-paiement .fd-mode', { hasText: 'Orange Money' }).click();
    await page.fill('#fd-paiement [name$="[reference]"]', 'MP261020.1015.B12345');
    await page.waitForTimeout(500);
    await capture(page, 'facture-paiement');

    await aller(page, `${facture}/avoir`);
    await page.locator('input[name="motif"][value="erreur_prix"], label:has-text("Erreur de prix") input').first().check().catch(() => {});
    const quantite = page.locator('input.fd-qte:not([disabled])').first();
    await quantite.fill('1');
    await quantite.dispatchEvent('input');
    await quantite.dispatchEvent('change');
    await page.waitForTimeout(500);
    await capture(page, 'avoir-saisie');

    await aller(page, facture);
    await suivre(page, page.getByRole('link', { name: 'Voir tous les documents liés' }));
    await capture(page, 'documents-lies');

    await aller(page, '/devis/new');
    const caseHors = page.locator('input[name="devis[horsImprimerie]"]');
    await caseHors.check();
    await caseHors.scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, -160));
    await page.waitForTimeout(300);
    await capture(page, 'hors-imprimerie');
});

/* ---------- Direction ---------- */

await scene(['tableau-de-bord', 'a-valider', 'utilisateurs', 'utilisateur-nouveau', 'utilisateur-droits', 'parametres', 'finances', 'sauvegardes', 'import'], async () => {
    const page = await session('demo.kadiatou');
    await aller(page, '/');
    await capture(page, 'tableau-de-bord');
    // Sans la liste « Validées récemment » (elle montre les salaires).
    await aller(page, '/finances/a-valider');
    await capture(page, 'a-valider', { clip: await auDessusDe(page, 'text=Validées récemment') });
    await aller(page, '/reglages/utilisateurs');
    await capture(page, 'utilisateurs');
    await aller(page, '/reglages/utilisateurs/nouveau');
    await capture(page, 'utilisateur-nouveau');
    await aller(page, '/reglages/utilisateurs');
    await suivre(page, page.locator('tr', { hasText: 'Seydou Diarra' }).getByRole('link', { name: 'Droits' }));
    await page.getByText('Légende').first().scrollIntoViewIfNeeded();
    await capture(page, 'utilisateur-droits');
    await aller(page, '/reglages/parametres');
    await capture(page, 'parametres');
    await aller(page, '/finances');
    await capture(page, 'finances');
    await aller(page, '/reglages/securite/sauvegardes');
    await capture(page, 'sauvegardes');
    await aller(page, '/reglages/systeme/import');
    await capture(page, 'import');
});

// Statistiques par activité (docs/hors-imprimerie.md) et achats (docs/achats.md) : rien n'est enregistré.
await scene(['statistiques-activite', 'fournisseurs', 'fournisseur-fiche', 'commande-achat', 'reception-achat', 'reception-reclamation'], async () => {
    const page = await session('demo.kadiatou');
    await aller(page, '/statistiques?activite=tout');
    await page.locator('th', { hasText: 'Hors imprimerie' }).first().scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, 200));
    await page.waitForTimeout(300);
    await capture(page, 'statistiques-activite');

    await aller(page, '/fournisseurs');
    await capture(page, 'fournisseurs');
    const alerte = await page.locator('a[href*="/achats/new?article="]', { hasText: /sous le minimum|maintenant/ }).first().getAttribute('href');
    await suivre(page, page.getByRole('link', { name: 'Dakar Signs' }).first());
    await capture(page, 'fournisseur-fiche');
    await aller(page, alerte);
    await capture(page, 'commande-achat');

    // Réception d'une commande en route : un rouleau écrasé, non validée.
    await aller(page, '/achats/receptions');
    const recevoir = page.locator('a', { hasText: 'Réceptionner' });
    if (await recevoir.count()) {
        await suivre(page, recevoir.first());
        const bon = page.locator('[data-ac-r-bon]').first();
        await bon.fill(String(Math.max(0, Number(await bon.inputValue()) - 1)));
        await bon.dispatchEvent('input');
        await page.locator('[data-ac-r-motif]').first().fill('écrasé');
        await page.waitForTimeout(500);
        await capture(page, 'reception-achat');
    } else if (!SEULES.length || SEULES.includes('reception-achat')) {
        erreurs.push('reception-achat : aucune commande d\'achat à réceptionner (relancer app:demo)');
    }

    // Une réception avec réclamation ouverte : le paiement du solde est bloqué.
    await aller(page, '/achats/receptions');
    const bloquee = await lienDe(page, 'paiement bloqué', 'a:has-text("Voir")');
    if (!bloquee) throw new Error('aucune réception avec paiement bloqué');
    await aller(page, bloquee);
    await page.getByText('Réclamation fournisseur créée').first().scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, -120));
    await page.waitForTimeout(300);
    await capture(page, 'reception-reclamation');
});

// Coûts et marges (SU04, docs/achats.md) : le tableau du mois, puis la cascade et « À retenir ». Rien n'est enregistré.
await scene(['couts-marges', 'couts-marges-cascade'], async () => {
    const page = await session('demo.kadiatou');
    await aller(page, '/achats/couts-marges');
    await capture(page, 'couts-marges');
    await page.locator('#cascade').scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, -80));
    await page.waitForTimeout(300);
    await capture(page, 'couts-marges-cascade');
});

// Crédits SMS et e-mail : solde saisi à la main (pas de clés Orange en démonstration), image coupée avant « Derniers envois ».
await scene(['credits-messages'], async () => {
    const page = await session('demo.kadiatou');
    const dans = (jours) => new Date(Date.now() + jours * 86400000).toISOString().slice(0, 10);
    await aller(page, '/communications/credits');
    if (await page.locator('#cm-solde').count()) {
        await page.fill('#cm-solde', '1250');
        await page.fill('#cm-fin-sms', dans(45));
        await suivre(page, page.getByRole('button', { name: 'Enregistrer le solde' }));
    }
    await page.fill('#cm-fin-email', dans(200));
    await suivre(page, page.getByRole('button', { name: 'Enregistrer la date' }));
    // Sans le message « enregistré » du haut de page.
    await page.locator('.alert').evaluateAll((alertes) => alertes.forEach((a) => a.remove()));
    await capture(page, 'credits-messages', { clip: await auDessusDe(page, 'text=Derniers envois') });
});

// La corbeille (docs/corbeille.md) : ce que la démonstration ou le parcours ont supprimé.
await scene(['corbeille'], async () => {
    const page = await session('demo.kadiatou');
    await aller(page, '/corbeille');
    await capture(page, 'corbeille');
});

/* ---------- Production ---------- */

await scene(['production', 'planning-machines', 'mes-statistiques', 'ma-prime'], async () => {
    const page = await session('demo.oumar');
    await aller(page, '/atelier/production');
    await capture(page, 'production');
    await aller(page, '/atelier/planning');
    await capture(page, 'planning-machines');
    await aller(page, '/mes-statistiques');
    await capture(page, 'mes-statistiques');
    const telephone = await session('demo.oumar', { mobile: true });
    await aller(telephone, '/mon-espace/prime');
    await capture(telephone, 'ma-prime');
});

await scene(['ma-file', 'ma-file-travail'], async () => {
    const page = await session('demo.oumar', { ip: POSTE_MACHINE });
    await aller(page, '/atelier/ma-file');
    if (!(await page.getByText('Poste de').count())) throw new Error('poste de machine non reconnu (SYMFONY_TRUSTED_PROXIES ?)');
    await capture(page, 'ma-file');
    await suivre(page, page.locator('a[href*="/atelier/ma-file/"]').first());
    await capture(page, 'ma-file-travail');
});

/* ---------- Livreur et caisse au retour ---------- */

await scene(['tournee', 'remise', 'a-retirer'], async () => {
    const page = await session('demo.bakary', { mobile: true });
    await aller(page, '/livraisons/tournee');
    await capture(page, 'tournee');
    await suivre(page, page.getByRole('link', { name: 'Remise' }).first());
    // Signature tracée sur l'écran, encaissement en espèces.
    const toile = await page.locator('canvas').first().boundingBox();
    await page.mouse.move(toile.x + 30, toile.y + toile.height / 2);
    await page.mouse.down();
    for (let i = 1; i <= 12; i++) await page.mouse.move(toile.x + 30 + i * 20, toile.y + toile.height / 2 + (i % 2 ? -25 : 25));
    await page.mouse.up();
    await page.check('input[name="mode"][value="especes"]');
    await page.waitForTimeout(1500);
    await capture(page, 'remise');
    // La page attend la position GPS avant d'envoyer la remise.
    await page.getByRole('button', { name: 'Valider la remise' }).click();
    await page.waitForURL(/\/terminee$/, { timeout: 60000 });
    await pret(page);
    await aller(page, '/livraisons/a-retirer');
    await capture(page, 'a-retirer');
});

// Au retour du livreur, la caisse valide l'argent rapporté.
await scene(['livraisons-planning', 'livraison-encaissement'], async () => {
    const page = await session('demo.aminata');
    await aller(page, '/livraisons/planning');
    await capture(page, 'livraisons-planning');
    const bons = await page.evaluate(() => [...new Set([...document.querySelectorAll('a[href*="/livraisons/bl/"]')].map((a) => a.getAttribute('href')))]);
    for (const bon of bons) {
        await aller(page, bon);
        const valider = page.getByRole('button', { name: 'Valider l\'encaissement' });
        if (await valider.count()) {
            await page.locator('select[name="compte"]').selectOption({ label: 'Caisse imprimerie' });
            await valider.scrollIntoViewIfNeeded();
            await capture(page, 'livraison-encaissement');
            await suivre(page, valider);

            return;
        }
    }
    throw new Error('aucun encaissement de livreur à valider');
});

/* ---------- Mon espace ---------- */

await scene(['pointer', 'pointer-photo', 'mon-espace', 'absence'], async () => {
    const page = await session('demo.bakary', { mobile: true });
    await aller(page, '/pointer');
    await capture(page, 'pointer');
    if (process.env.POINTAGE_LIEN) {
        await aller(page, process.env.POINTAGE_LIEN);
        // Premier pointage depuis ce téléphone : la RH le valide d'abord.
        if (await page.getByText('attend la validation de la RH').count()) {
            const rh = await session('demo.kadiatou');
            await aller(rh, '/equipe/pointage/reglages');
            const autoriser = rh.locator('.si-pg-appareil', { hasText: 'Bakary' }).getByRole('button', { name: 'Autoriser' });
            while (await autoriser.count()) await suivre(rh, autoriser.first());
            await aller(page, process.env.POINTAGE_LIEN);
        }
        await page.getByText('Regardez la caméra').waitFor();
        await page.waitForTimeout(2000);
        await capture(page, 'pointer-photo');
        await page.locator('[data-si-declencher]').click();
        await page.waitForURL(/\/pointer\/fait\//, { timeout: 20000 });
        await pret(page);
    } else if (!SEULES.length || SEULES.includes('pointer-photo')) {
        erreurs.push('pointer-photo : POINTAGE_LIEN non donné, capture sautée');
    }
    await aller(page, '/mon-espace');
    await capture(page, 'mon-espace');
    await aller(page, '/mon-espace/demande/absence');
    await capture(page, 'absence');
});

/* ---------- Entretien ---------- */

await scene(['entretien', 'entretien-tache'], async () => {
    const page = await session('demo.awa', { mobile: true });
    await aller(page, '/entretien');
    await capture(page, 'entretien');
    // La première tâche de la journée (lien posé sur toute la carte).
    await aller(page, await page.locator('a[href*="/entretien/tache/"]').first().getAttribute('href'));
    await page.waitForTimeout(1000);
    await capture(page, 'entretien-tache');
});

await navigateur.close();

const total = fs.readdirSync(SORTIE).filter((f) => f.endsWith('.webp')).reduce((s, f) => s + fs.statSync(path.join(SORTIE, f)).size, 0);
console.log(`${faites.length} captures : ${faites.join(', ')}`);
console.log(`Dossier ${path.relative(RACINE, SORTIE)} : ${(total / 1024 / 1024).toFixed(2)} Mo`);
if (erreurs.length) {
    console.error(`\n${erreurs.length} problème(s) :\n- ${erreurs.join('\n- ')}`);
    process.exitCode = 1;
}
