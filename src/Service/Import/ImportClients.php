<?php

namespace App\Service\Import;

use App\Entity\Clients;
use App\Entity\ContactClient;
use App\Entity\ImportDonnees;
use App\Entity\ModificationClient;
use App\Service\Clients\EditionClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import des fiches clients (docs/import-donnees.md).
 *
 * Chaque ligne passe par EditionClient, comme une fiche saisie à la main :
 * même contrôle du téléphone, même découpage prénom / nom, même contact
 * principal pour une organisation, même journal de la fiche (« Import de
 * données »). Aucun message n'est envoyé : la création d'une fiche n'en
 * envoie pas, et l'import ne demande rien à la direction (c'est elle qui
 * importe).
 *
 * Doublons, dans l'ordre : code de la fiche, téléphone (8 chiffres, fiche,
 * second numéro ou contacts), nom ; puis dans le fichier lui-même. Une
 * fiche existante n'est jamais écrasée : l'option « compléter » remplit
 * seulement ses champs vides.
 */
final class ImportClients implements TypeImport
{
    /** Champs qu'une fiche existante peut recevoir s'ils sont vides : clé de colonne => [libellé du journal, catégorie, getter, setter]. */
    private const COMPLETABLES = [
        'email' => ['E-mail', 'identite', 'getEmail', 'setEmail'],
        'telephone2' => ['Téléphone 2', 'identite', 'getTelephone2', 'setTelephone2'],
        'commune' => ['Commune', 'identite', 'getCommune', 'setCommune'],
        'quartier' => ['Quartier', 'identite', 'getQuartier', 'setQuartier'],
        'repere' => ['Repère', 'identite', 'getRepere', 'setRepere'],
        'nif' => ['NIF', 'identite', 'getNif', 'setNif'],
        'rccm' => ['RCCM', 'identite', 'getRccm', 'setRccm'],
        'secteur' => ['Secteur', 'identite', 'getSecteur', 'setSecteur'],
        'delai' => ['Délai de paiement', 'conditions', 'getDelaiPaiement', 'setDelaiPaiement'],
        'notes' => ['Note interne', 'identite', 'getObservation', 'setObservation'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditionClient $edition,
        private readonly References $references,
    ) {
    }

    public function cle(): string
    {
        return 'clients';
    }

    public function libelle(): string
    {
        return 'Clients';
    }

    public function description(): string
    {
        return 'Fiches clients, particuliers et organisations : téléphone, grille de prix, adresse, conditions de paiement.';
    }

    public function droits(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function toutOuRien(): bool
    {
        return false;
    }

    public function avecDateOuverture(): bool
    {
        return false;
    }

    public function avecCompletion(): bool
    {
        return true;
    }

    public function colonnes(): array
    {
        return [
            ['cle' => 'type', 'libelle' => 'Type', 'alias' => ['type de client', 'type de compte', 'nature'], 'obligatoire' => false,
                'exemples' => ['Particulier', 'Entreprise'], 'aide' => 'Particulier, Entreprise, ONG ou Administration. Vide : Particulier.'],
            ['cle' => 'nom', 'libelle' => 'Nom ou raison sociale', 'alias' => ['nom', 'nom complet', 'client', 'nom du client', 'raison sociale', 'societe', 'entreprise', 'prenom et nom'], 'obligatoire' => true,
                'exemples' => ['Aminata Coulibaly', 'Hôtel du Fleuve SARL'], 'aide' => 'Particulier : prénom puis nom. Organisation : sa raison sociale.'],
            ['cle' => 'contact', 'libelle' => 'Contact', 'alias' => ['nom du contact', 'personne a contacter', 'interlocuteur', 'contact principal'], 'obligatoire' => false,
                'exemples' => ['', 'Moussa Diarra'], 'aide' => 'Organisation : la personne à appeler, qui devient le contact principal. Ignoré pour un particulier.'],
            ['cle' => 'telephone', 'libelle' => 'Téléphone', 'alias' => ['tel', 'telephone 1', 'portable', 'mobile', 'numero', 'numero de telephone', 'cellulaire'], 'obligatoire' => true,
                'exemples' => ['76 12 34 56', '+223 20 22 33 44'], 'aide' => '8 chiffres, avec ou sans +223, espaces ou points. Sert à repérer les doublons.'],
            ['cle' => 'telephone2', 'libelle' => 'Téléphone 2', 'alias' => ['tel 2', 'telephone secondaire', 'autre telephone', 'fixe'], 'obligatoire' => false,
                'exemples' => ['', '66 00 11 22'], 'aide' => 'Un second numéro, 8 chiffres.'],
            ['cle' => 'email', 'libelle' => 'E-mail', 'alias' => ['email', 'mail', 'courriel', 'adresse email', 'adresse mail'], 'obligatoire' => false,
                'exemples' => ['aminata.c@exemple.ml', 'compta@hotelfleuve.ml'], 'aide' => 'Une adresse valide, ou vide.'],
            ['cle' => 'grille', 'libelle' => 'Grille de prix', 'alias' => ['grille', 'grille tarifaire', 'tarif', 'type de prix', 'categorie de prix'], 'obligatoire' => false,
                'exemples' => ['Standard', 'Revendeur (B2B)'], 'aide' => 'Standard ou Revendeur (B2B), réservée aux gens du métier. Vide : Standard.'],
            ['cle' => 'commune', 'libelle' => 'Commune', 'alias' => ['ville', 'commune ville'], 'obligatoire' => false,
                'exemples' => ['Commune IV', 'Commune III'], 'aide' => 'Texte libre.'],
            ['cle' => 'quartier', 'libelle' => 'Quartier', 'alias' => [], 'obligatoire' => false,
                'exemples' => ['Lafiabougou', 'ACI 2000'], 'aide' => 'Texte libre.'],
            ['cle' => 'repere', 'libelle' => 'Adresse ou repère', 'alias' => ['adresse', 'repere', 'localisation', 'adresse de livraison'], 'obligatoire' => false,
                'exemples' => ['Près de la pharmacie du marché', 'Immeuble Kanaga, 2e étage'], 'aide' => 'Ce qui permet au livreur de trouver le client.'],
            ['cle' => 'nif', 'libelle' => 'NIF', 'alias' => ['numero fiscal', 'numero d identification fiscale'], 'obligatoire' => false,
                'exemples' => ['', '084112345K'], 'aide' => 'Organisations seulement.'],
            ['cle' => 'rccm', 'libelle' => 'RCCM', 'alias' => ['registre de commerce'], 'obligatoire' => false,
                'exemples' => ['', 'MA.BKO.2019.B.1234'], 'aide' => 'Organisations seulement.'],
            ['cle' => 'secteur', 'libelle' => 'Secteur', 'alias' => ['secteur d activite', 'activite', 'domaine'], 'obligatoire' => false,
                'exemples' => ['', 'Hôtellerie'], 'aide' => 'Texte libre.'],
            ['cle' => 'delai', 'libelle' => 'Délai de paiement', 'alias' => ['delai', 'conditions de paiement', 'paiement'], 'obligatoire' => false,
                'exemples' => ['Comptant', '30 jours'], 'aide' => 'Comptant, 15 jours, 30 jours, 30 jours fin de mois, 45 jours ou 60 jours.'],
            ['cle' => 'plafond', 'libelle' => 'Plafond de crédit', 'alias' => ['plafond', 'credit maximum', 'encours maximum', 'limite de credit'], 'obligatoire' => false,
                'exemples' => ['', '500 000'], 'aide' => 'En francs. Vide : 100 000 F, comme une fiche saisie à la main.'],
            ['cle' => 'notes', 'libelle' => 'Notes', 'alias' => ['note', 'observation', 'observations', 'commentaire', 'commentaires', 'remarque'], 'obligatoire' => false,
                'exemples' => ['', 'Commande des menus chaque trimestre'], 'aide' => 'Note interne, jamais montrée au client.'],
            ['cle' => 'code', 'libelle' => 'Code client', 'alias' => ['code', 'ancien code', 'reference client', 'numero client', 'ref'], 'obligatoire' => false,
                'exemples' => ['', 'C-0042'], 'aide' => 'Le code d\'une fiche existante (CLI-000123) la désigne. Un autre code, celui de l\'ancien logiciel, est gardé dans les notes.'],
        ];
    }

    public function notes(): array
    {
        return [
            'Une ligne par client. L\'ordre des colonnes est libre ; seules « Nom ou raison sociale » et « Téléphone » sont obligatoires.',
            'Un client déjà enregistré (même code, même téléphone, ou même nom) n\'est pas recréé : la ligne est écartée. Cochez « Compléter les fiches existantes » pour remplir seulement leurs champs vides ; rien n\'est jamais écrasé.',
            'Un client qui figure deux fois dans le fichier (même téléphone ou même nom) n\'est créé qu\'une fois.',
            'Aucun message n\'est envoyé aux clients importés : ni SMS, ni e-mail, ni WhatsApp. Leur accord pour les messages promotionnels n\'est pas enregistré.',
            'Les lignes en erreur sont écartées ; les autres sont enregistrées. Corrigez-les et renvoyez seulement celles-là.',
        ];
    }

    public function analyser(array $lignes, ContexteImport $contexte): void
    {
        $index = IndexClients::charger($this->em);
        foreach ($lignes as $ligne) {
            $this->analyserLigne($ligne, $contexte, $index);
        }
    }

    public function appliquer(array $lignes, ContexteImport $contexte, ImportDonnees $import): void
    {
        /** @var list<array{0: LigneImport, 1: Clients}> $crees */
        $crees = [];
        foreach ($lignes as $ligne) {
            if (LigneImport::CREER === $ligne->statut) {
                $client = new Clients();
                $r = $this->edition->appliquer($client, $ligne->donnees['saisie'], $contexte->par, true, $contexte->maintenant, 'import', false, false);
                if ([] !== $r['erreurs']) {
                    throw new \RuntimeException(sprintf('Ligne %d : %s', $ligne->numero, implode(' ', $r['erreurs'])));
                }
                if (null !== $ligne->donnees['telephone2']) {
                    $client->setTelephone2($ligne->donnees['telephone2']);
                }
                $crees[] = [$ligne, $client];
                if (0 === \count($crees) % 50) {
                    $this->em->flush();
                }
            } elseif (LigneImport::MAJ === $ligne->statut) {
                $client = $this->em->find(Clients::class, $ligne->donnees['id']);
                if (null === $client) {
                    throw new \RuntimeException(sprintf('Ligne %d : la fiche à compléter n\'existe plus.', $ligne->numero));
                }
                $apres = $this->completer($client, $ligne->donnees['completer'], $contexte);
                $import->noter(['ligne' => $ligne->numero, 'statut' => LigneImport::MAJ, 'id' => $client->getId(), 'code' => $client->getCode(), 'libelle' => $client->getNomComplet(), 'apres' => $apres, 'detail' => $ligne->detail]);
            } else {
                $import->noter(['ligne' => $ligne->numero, 'statut' => $ligne->statut, 'libelle' => $ligne->libelle, 'raison' => $ligne->raison]);
            }
        }
        $this->em->flush();

        // Le code CLI-000123 vient de l'identifiant, connu après l'enregistrement.
        foreach ($crees as [$ligne, $client]) {
            $this->edition->terminerCreation($client, null, $contexte->maintenant);
            $import->noter(['ligne' => $ligne->numero, 'statut' => LigneImport::CREER, 'id' => $client->getId(), 'code' => $client->getCode(), 'libelle' => $client->getNomComplet()]);
        }
        $this->em->flush();
    }

    public function blocagesAnnulation(ImportDonnees $import): array
    {
        [$crees, $completes] = self::fiches($import);
        $blocages = [];
        $references = $this->references->compter(Clients::class, $crees, [ContactClient::class, ModificationClient::class]);
        if ([] !== $references) {
            $blocages[] = sprintf('Des clients créés par cet import ont servi depuis : %s.', References::phrase($references));
        }
        $toutes = [...$crees, ...array_keys($completes)];
        if ([] !== $toutes) {
            $modifiees = (int) $this->em->createQuery('SELECT COUNT(DISTINCT IDENTITY(m.client)) FROM '.ModificationClient::class.' m WHERE m.client IN (:ids) AND m.le >= :le AND m.depuis <> :import')
                ->setParameter('ids', $toutes)->setParameter('le', $import->getLe())->setParameter('import', 'import')
                ->getSingleScalarResult();
            if ($modifiees > 0) {
                $blocages[] = sprintf('%d %s modifiée%s depuis l\'import.', $modifiees, $modifiees > 1 ? 'fiches importées ou complétées ont été' : 'fiche importée ou complétée a été', $modifiees > 1 ? 's' : '');
            }
        }

        return $blocages;
    }

    public function annuler(ImportDonnees $import, ContexteImport $contexte): void
    {
        [$crees, $completes] = self::fiches($import);
        foreach ($crees as $id) {
            $client = $this->em->find(Clients::class, $id);
            if (null === $client) {
                continue;
            }
            foreach ($this->em->getRepository(ContactClient::class)->findBy(['client' => $client]) as $k) {
                $this->em->remove($k);
            }
            foreach ($this->em->getRepository(ModificationClient::class)->findBy(['client' => $client]) as $m) {
                $this->em->remove($m);
            }
            $this->em->remove($client);
        }
        foreach ($completes as $id => $apres) {
            $client = $this->em->find(Clients::class, $id);
            if (null === $client) {
                continue;
            }
            foreach ($apres as $cle => $valeur) {
                [$libelle, $categorie, $getter, $setter] = self::COMPLETABLES[$cle];
                if ((string) $client->{$getter}() === (string) $valeur) {
                    $client->{$setter}(null);
                    $this->em->persist(new ModificationClient($client, $libelle, $categorie, self::lisible($cle, (string) $valeur), null, $contexte->maintenant, $contexte->par, 'import', $contexte->parNom));
                }
            }
        }
        $this->em->flush();
    }

    /**
     * Type de compte lu dans le fichier : null s'il est inconnu.
     */
    public static function typeCompte(string $brut): ?string
    {
        $c = Valeurs::cle($brut);
        if ('' === $c) {
            return Clients::TYPE_COMPTE_PARTICULIER;
        }
        foreach (Clients::TYPES_COMPTE_LABELS as $type => $libelle) {
            if ($c === Valeurs::cle($type) || $c === Valeurs::cle($libelle)) {
                return $type;
            }
        }

        return match ($c) {
            'societe', 'entreprises', 'sarl', 'sa', 'suarl', 'ets', 'etablissement', 'professionnel', 'pro', 'b2b' => Clients::TYPE_COMPTE_ENTREPRISE,
            'association', 'ong internationale', 'organisation' => Clients::TYPE_COMPTE_ONG,
            'etat', 'administration publique', 'service public', 'ministere', 'mairie' => Clients::TYPE_COMPTE_ADMINISTRATION,
            'part', 'personne', 'individu', 'particuliers', 'b2c' => Clients::TYPE_COMPTE_PARTICULIER,
            default => null,
        };
    }

    /** Grille de prix : « B2C », « B2B », ou null si inconnue. */
    public static function grille(string $brut): ?string
    {
        return match (Valeurs::cle($brut)) {
            '', 'standard', 'b2c', 'normal', 'normale', 'public', 'particulier', 'detail' => 'B2C',
            'b2b', 'revendeur', 'revendeur b2b', 'revendeurs', 'grossiste', 'professionnel', 'pro' => 'B2B',
            default => null,
        };
    }

    /**
     * Délai de paiement : clé de Clients::DELAIS_PAIEMENT, '' si vide, null si inconnu.
     */
    public static function delai(string $brut): ?string
    {
        $c = Valeurs::cle($brut);
        if ('' === $c) {
            return '';
        }
        foreach (Clients::DELAIS_PAIEMENT as $cle => $libelle) {
            if ($c === Valeurs::cle($libelle) || $c === Valeurs::cle($cle)) {
                return $cle;
            }
        }
        if (\in_array($c, ['cash', 'au comptant', 'immediat', '0', '0 jour', '0 jours'], true)) {
            return 'comptant';
        }
        if (preg_match('/^(\d{2})\s*(j|jours?)?(\s*(fdm|fin de mois))?$/', $c, $m)) {
            $cle = $m[1].'_jours'.(isset($m[4]) && '' !== $m[4] ? '_fdm' : '');

            return isset(Clients::DELAIS_PAIEMENT[$cle]) ? $cle : null;
        }

        return null;
    }

    private function analyserLigne(LigneImport $ligne, ContexteImport $contexte, IndexClients $index): void
    {
        $nom = Valeurs::texte($ligne->valeur('nom'), 200);
        $ligne->libelle = $nom ?? '';
        $type = self::typeCompte($ligne->valeur('type'));
        if (null === $type) {
            $ligne->erreur(sprintf('Type « %s » inconnu : Particulier, Entreprise, ONG ou Administration.', $ligne->valeur('type')));

            return;
        }
        $organisation = Clients::TYPE_COMPTE_PARTICULIER !== $type;
        if (null === $nom) {
            $ligne->erreur('Le nom est obligatoire.');

            return;
        }
        if (mb_strlen($nom) > 100) {
            $ligne->erreur('Le nom dépasse 100 caractères.');

            return;
        }

        $brut = $ligne->valeur('telephone');
        $telephone = Valeurs::telephone($brut);
        if ('' === $brut) {
            $ligne->erreur($organisation ? 'Le téléphone est obligatoire (celui du contact principal).' : 'Le téléphone est obligatoire.');

            return;
        }
        if (null === $telephone) {
            $ligne->erreur(sprintf('« %s » : le téléphone doit avoir 8 chiffres.', $brut));

            return;
        }
        $brut2 = $ligne->valeur('telephone2');
        $telephone2 = Valeurs::telephone($brut2);
        if ('' !== $brut2 && null === $telephone2) {
            $ligne->erreur(sprintf('« %s » : le téléphone 2 doit avoir 8 chiffres.', $brut2));

            return;
        }
        if ($telephone2 === $telephone) {
            $telephone2 = null;
        }
        $grille = self::grille($ligne->valeur('grille'));
        if (null === $grille) {
            $ligne->erreur(sprintf('Grille « %s » inconnue : Standard ou Revendeur (B2B).', $ligne->valeur('grille')));

            return;
        }
        $delai = self::delai($ligne->valeur('delai'));
        if (null === $delai) {
            $ligne->erreur(sprintf('Délai « %s » inconnu : %s.', $ligne->valeur('delai'), implode(', ', Clients::DELAIS_PAIEMENT)));

            return;
        }
        $plafond = null;
        if ('' !== $ligne->valeur('plafond')) {
            try {
                $plafond = Valeurs::montant($ligne->valeur('plafond'));
            } catch (\InvalidArgumentException $e) {
                $ligne->erreur('Plafond de crédit : '.$e->getMessage());

                return;
            }
        }
        $email = Valeurs::texte($ligne->valeur('email'));
        if (null !== $email && !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $ligne->erreur(sprintf('« %s » n\'est pas une adresse e-mail valide.', $email));

            return;
        }
        foreach (['nif' => 30, 'rccm' => 50, 'commune' => 80, 'quartier' => 80, 'secteur' => 80] as $cle => $max) {
            if (mb_strlen($ligne->valeur($cle)) > $max) {
                $ligne->erreur(sprintf('%s : %d caractères au plus.', ucfirst($cle), $max));

                return;
            }
        }

        // Ancien code : désigne une fiche (CLI-000123) ou vient d'un autre logiciel.
        $code = Valeurs::texte($ligne->valeur('code'), 50);
        $notes = Valeurs::texte($ligne->valeur('notes'), 2000);
        if (null !== $code && !$index->codeConnu($code)) {
            $notes = trim(($notes ?? '')."\nAncien code : ".$code);
        }

        $telephones = [$telephone, $telephone2];
        $existant = $index->chercher($code, $telephones, $nom);
        if (null !== $existant) {
            $this->doublon($ligne, $existant, $contexte, [
                'email' => $email, 'telephone2' => $telephone2,
                'commune' => Valeurs::texte($ligne->valeur('commune'), 80), 'quartier' => Valeurs::texte($ligne->valeur('quartier'), 80),
                'repere' => Valeurs::texte($ligne->valeur('repere')), 'secteur' => Valeurs::texte($ligne->valeur('secteur'), 80),
                'nif' => $organisation ? Valeurs::texte($ligne->valeur('nif'), 30) : null, 'rccm' => $organisation ? Valeurs::texte($ligne->valeur('rccm'), 50) : null,
                'delai' => '' !== $delai ? $delai : null, 'notes' => $notes,
            ]);

            return;
        }
        $precedente = $index->dejaDansFichier($telephones, $nom);
        if (null !== $precedente) {
            $ligne->ignorer(sprintf('Déjà à la ligne %d (%s).', $precedente['ligne'], 'telephone' === $precedente['par'] ? 'même téléphone' : 'même nom'));

            return;
        }

        $saisie = [
            'type' => $type,
            'email' => $email,
            'commune' => $ligne->valeur('commune'),
            'quartier' => $ligne->valeur('quartier'),
            'repere' => Valeurs::texte($ligne->valeur('repere')),
            'secteur' => $ligne->valeur('secteur'),
            'grille' => $grille,
            'delai' => '' !== $delai ? $delai : null,
            'observation' => $notes,
        ];
        if (null !== $plafond) {
            $saisie['plafond'] = (string) $plafond;
        }
        $contact = Valeurs::texte($ligne->valeur('contact'), 120);
        if ($organisation) {
            $saisie += ['raisonSociale' => $nom, 'nif' => $ligne->valeur('nif'), 'rccm' => $ligne->valeur('rccm')];
            if (null !== $contact) {
                $saisie['contacts'] = [['nom' => $contact, 'telephone' => $telephone, 'role' => 'principal', 'whatsapp' => false]];
            } else {
                $saisie['telephone'] = $telephone;
            }
        } else {
            $saisie += ['nomComplet' => $nom, 'telephone' => $telephone];
            if (null !== $contact) {
                $ligne->avertir('Contact ignoré : c\'est un particulier.');
            }
            if ('' !== $ligne->valeur('nif') || '' !== $ligne->valeur('rccm')) {
                $ligne->avertir('NIF et RCCM ignorés : c\'est un particulier.');
            }
            if (1 === \count(preg_split('/\s+/', $nom) ?: [])) {
                $ligne->avertir('Un seul mot : il sert de prénom et de nom.');
            }
        }

        // Même contrôle et même mise en forme qu'une fiche saisie à la main, sans rien enregistrer.
        $essai = new Clients();
        $r = $this->edition->appliquer($essai, $saisie, $contexte->par, true, $contexte->maintenant, 'import', true, false);
        if ([] !== $r['erreurs']) {
            $ligne->erreur(implode(' ', array_unique($r['erreurs'])));

            return;
        }
        $ligne->statut = LigneImport::CREER;
        $ligne->libelle = $essai->getNomComplet();
        $ligne->detail = implode(' · ', array_filter([
            $essai->getTypeCompteLabel(),
            implode(' ', str_split($telephone, 2)),
            null !== $contact && $organisation ? 'contact '.$contact : null,
            'B2B' === $grille ? 'Revendeur (B2B)' : null,
            $essai->getCommune(),
        ]));
        $ligne->donnees = ['saisie' => $saisie, 'telephone2' => $telephone2];
        $index->retenirLigne($ligne->numero, $telephones, $nom);
    }

    /**
     * La ligne désigne une fiche qui existe : écartée, ou complétée si
     * l'option est cochée et qu'elle a des champs vides que la ligne remplit.
     * Un nom seul ne suffit pas pour compléter : c'est peut-être un homonyme.
     *
     * @param array<string, ?string> $valeurs
     */
    private function doublon(LigneImport $ligne, array $existant, ContexteImport $contexte, array $valeurs): void
    {
        $pourquoi = match ($existant['par']) {
            'code' => 'même code',
            'telephone' => 'même téléphone',
            default => $existant['ambigu'] ? 'même nom, porté par plusieurs fiches' : 'même nom',
        };
        $fiche = IndexClients::decrire($existant);
        if ('nom' === $existant['par']) {
            $ligne->ignorer(sprintf('Doublon probable de %s (%s, autre téléphone). Si c\'est une autre personne, ajoutez-la à la main.', $fiche, $pourquoi));

            return;
        }
        if (!$contexte->completer) {
            $ligne->ignorer(sprintf('Déjà enregistré : %s (%s).', $fiche, $pourquoi));

            return;
        }
        $client = $this->em->find(Clients::class, $existant['id']);
        $completer = [];
        foreach (self::COMPLETABLES as $cle => [$libelle, , $getter]) {
            $valeur = $valeurs[$cle] ?? null;
            if (null === $valeur || '' === $valeur || null === $client) {
                continue;
            }
            if (\in_array($cle, ['nif', 'rccm'], true) && !$client->isOrganisation()) {
                continue;
            }
            $actuel = $client->{$getter}();
            if (null === $actuel || '' === trim((string) $actuel)) {
                $completer[$cle] = $valeur;
            }
        }
        if ([] === $completer) {
            $ligne->ignorer(sprintf('Déjà enregistré : %s (%s), rien à compléter.', $fiche, $pourquoi));

            return;
        }
        $ligne->statut = LigneImport::MAJ;
        $ligne->libelle = $existant['nom'];
        $ligne->detail = sprintf('%s : %s', $existant['code'], implode(', ', array_map(static fn (string $c): string => mb_strtolower(self::COMPLETABLES[$c][0]), array_keys($completer))));
        $ligne->donnees = ['id' => $existant['id'], 'completer' => $completer];
    }

    /**
     * Remplit les champs vides et les note au journal de la fiche.
     *
     * @param array<string, string> $completer
     *
     * @return array<string, string> ce qui a été écrit
     */
    private function completer(Clients $client, array $completer, ContexteImport $contexte): array
    {
        $ecrits = [];
        foreach ($completer as $cle => $valeur) {
            [$libelle, $categorie, $getter, $setter] = self::COMPLETABLES[$cle];
            $actuel = $client->{$getter}();
            if (null !== $actuel && '' !== trim((string) $actuel)) {
                continue;
            }
            $client->{$setter}($valeur);
            $ecrit = $client->{$getter}();
            if (null === $ecrit) {
                continue;
            }
            $ecrits[$cle] = (string) $ecrit;
            $this->em->persist(new ModificationClient($client, $libelle, $categorie, null, self::lisible($cle, (string) $ecrit), $contexte->maintenant, $contexte->par, 'import', $contexte->parNom));
        }

        return $ecrits;
    }

    private static function lisible(string $cle, string $valeur): string
    {
        return 'delai' === $cle ? (Clients::DELAIS_PAIEMENT[$valeur] ?? $valeur) : $valeur;
    }

    /**
     * Fiches créées (identifiants) et fiches complétées (identifiant => champs écrits).
     *
     * @return array{0: list<int>, 1: array<int, array<string, string>>}
     */
    private static function fiches(ImportDonnees $import): array
    {
        $crees = [];
        $completes = [];
        foreach ($import->getDetails() as $d) {
            if (LigneImport::CREER === ($d['statut'] ?? null) && isset($d['id'])) {
                $crees[] = (int) $d['id'];
            } elseif (LigneImport::MAJ === ($d['statut'] ?? null) && isset($d['id'])) {
                $completes[(int) $d['id']] = (array) ($d['apres'] ?? []);
            }
        }

        return [$crees, $completes];
    }
}
