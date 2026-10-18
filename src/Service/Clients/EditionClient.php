<?php

namespace App\Service\Clients;

use App\Entity\Clients;
use App\Entity\ContactClient;
use App\Entity\ModificationClient;
use App\Entity\Prospect;
use App\Entity\User;
use App\Service\NotificationService;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création et modification d'une fiche client (maquettes K02 et K05).
 *
 * Toutes les saisies passent par appliquer() : la fiche, ses contacts et
 * le journal des modifications (K04) restent cohérents, quel que soit
 * l'écran. Une remise au-delà de 10 %, un plafond au-delà de 500 000 F
 * ou la grille Revendeur demandés par quelqu'un qui n'est pas de la
 * direction ne sont pas appliqués : la fiche garde sa valeur et la
 * direction reçoit une demande.
 */
final class EditionClient
{
    public const REMISE_MAX = 10.0;
    public const PLAFOND_MAX = 500000;

    /**
     * Grilles de prix. « Revendeur » (B2B) est réservée aux gens du métier,
     * démarchés et qui connaissent les prix ; une entreprise qui n'est pas
     * dans ce cas paie le prix standard et garde fidélité et promotions.
     */
    public const GRILLES = ['B2C' => 'Standard', 'B2B' => 'Revendeur (B2B)'];

    /** Champs suivis par le journal : libellé, catégorie. */
    public const CHAMPS = [
        'typeCompte' => ['Type de client', 'identite'],
        'raisonSociale' => ['Raison sociale', 'identite'],
        'prenom' => ['Prénom', 'identite'],
        'nom' => ['Nom', 'identite'],
        'telephone' => ['Téléphone', 'identite'],
        'email' => ['E-mail', 'identite'],
        'nif' => ['NIF', 'identite'],
        'rccm' => ['RCCM', 'identite'],
        'secteur' => ['Secteur', 'identite'],
        'entiteFacturation' => ['Entité de facturation', 'identite'],
        'commune' => ['Commune', 'identite'],
        'quartier' => ['Quartier', 'identite'],
        'repere' => ['Repère', 'identite'],
        'position' => ['Position GPS', 'identite'],
        'origine' => ['Origine', 'identite'],
        'suiviPar' => ['Suivi par', 'identite'],
        'marketing' => ['Messages promotionnels', 'identite'],
        'observation' => ['Note interne', 'identite'],
        'etiquettes' => ['Étiquettes', 'identite'],
        'besoin' => ['Besoin', 'identite'],
        'typeClient' => ['Grille tarifaire', 'conditions'],
        'remiseHabituelle' => ['Remise habituelle', 'conditions'],
        'delaiPaiement' => ['Délai de paiement', 'conditions'],
        'plafondCredit' => ['Plafond de crédit', 'conditions'],
        'acompte' => ['Acompte à la commande', 'conditions'],
        'sansAcompte' => ['Dispensé d\'acompte', 'conditions'],
        'moyenPrefere' => ['Moyen préféré', 'conditions'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return list<ContactClient> principal d'abord */
    public function contacts(Clients $client): array
    {
        if (null === $client->getId()) {
            return [];
        }
        $liste = $this->em->getRepository(ContactClient::class)->findBy(['client' => $client], ['id' => 'ASC']);
        usort($liste, fn (ContactClient $a, ContactClient $b) => ('principal' === $b->getRole()) <=> ('principal' === $a->getRole()));

        return $liste;
    }

    /**
     * Valeurs du formulaire pour une fiche existante (même forme que ce
     * que le formulaire renvoie).
     *
     * @return array<string, mixed>
     */
    public function valeurs(Clients $c): array
    {
        return [
            'type' => $c->getTypeCompte(),
            'raisonSociale' => $c->getRaisonSociale(),
            'prenom' => $c->getPrenom(),
            'nom' => $c->getNom(),
            'telephone' => $c->getTelephone(),
            'email' => $c->getEmail(),
            'nif' => $c->getNif(),
            'rccm' => $c->getRccm(),
            'secteur' => $c->getSecteur(),
            'entite' => $c->getEntiteFacturation(),
            'contacts' => array_map(fn (ContactClient $k) => [
                'id' => $k->getId(), 'nom' => $k->getNom(), 'fonction' => $k->getFonction(),
                'telephone' => $k->getTelephone(), 'whatsapp' => $k->isWhatsapp(), 'role' => $k->getRole(),
            ], $this->contacts($c)),
            'commune' => $c->getCommune(),
            'quartier' => $c->getQuartier(),
            'repere' => $c->getRepere(),
            'latitude' => $c->getLatitude(),
            'longitude' => $c->getLongitude(),
            'grille' => $c->getTypeClient(),
            'remise' => $c->getRemiseHabituelle(),
            'delai' => $c->getDelaiPaiement(),
            'plafond' => $c->getPlafondCredit(),
            'acompte' => $c->getAcompte(),
            'sansAcompte' => $c->isSansAcompte(),
            'moyen' => $c->getMoyenPrefere(),
            'origine' => $c->getOrigine(),
            'suivi' => $c->getSuiviPar()?->getId(),
            'marketing' => $c->isAccepteMarketing(),
            'observation' => $c->getObservation(),
            'etiquettes' => implode(', ', $c->getEtiquettes()),
            'besoin' => $c->getBesoin(),
        ];
    }

    /**
     * Applique la saisie. Seules les clés présentes sont modifiées (l'ajout
     * terrain n'envoie qu'une partie des champs). Rien n'est enregistré :
     * l'appelant enregistre seulement s'il n'y a pas d'erreur.
     *
     * L'import (docs/import-donnees.md) passe chaque ligne par ici : d'abord
     * pour l'aperçu ($simuler : la fiche est contrôlée et normalisée, rien
     * n'est noté ni rattaché à la base), puis pour l'enregistrement. Il
     * cherche lui-même les doublons sur tout le fichier client en une fois
     * ($verifierDoublons = false) : même règle, sans une requête par ligne.
     *
     * @param array<string, mixed> $d
     *
     * @return array{erreurs: array<string, string>, demandes: list<string>}
     */
    public function appliquer(Clients $c, array $d, ?User $par, bool $direction, \DateTimeImmutable $maintenant, string $depuis = 'erp_local', bool $simuler = false, bool $verifierDoublons = true): array
    {
        $creation = null === $c->getId();
        $avant = $creation ? [] : self::instantane($c);
        $erreurs = [];
        $demandes = [];
        // Par référence : « nomComplet » (ajout terrain, import) remplit prénom et nom plus bas.
        $texte = static function (string $k) use (&$d): ?string {
            return \array_key_exists($k, $d) && null !== $d[$k] && '' !== trim((string) $d[$k]) ? trim((string) $d[$k]) : null;
        };
        $a = static function (string $k) use (&$d): bool {
            return \array_key_exists($k, $d);
        };

        if ($a('type')) {
            $c->setTypeCompte(isset(Clients::TYPES_COMPTE_LABELS[$d['type']]) ? $d['type'] : Clients::TYPE_COMPTE_PARTICULIER);
        }
        foreach (['raisonSociale' => 'setRaisonSociale', 'email' => 'setEmail', 'nif' => 'setNif', 'rccm' => 'setRccm', 'secteur' => 'setSecteur', 'entite' => 'setEntiteFacturation', 'commune' => 'setCommune', 'quartier' => 'setQuartier', 'repere' => 'setRepere', 'origine' => 'setOrigine', 'observation' => 'setObservation', 'besoin' => 'setBesoin', 'delai' => 'setDelaiPaiement', 'moyen' => 'setMoyenPrefere'] as $k => $setter) {
            if ($a($k)) {
                $c->{$setter}($texte($k));
            }
        }
        if (null !== $c->getEmail() && !filter_var($c->getEmail(), \FILTER_VALIDATE_EMAIL)) {
            $erreurs['email'] = 'L’adresse e-mail n’est pas valide.';
        }
        if (!$c->isOrganisation()) {
            $c->setRaisonSociale(null)->setNif(null)->setRccm(null);
        }
        if ($a('etiquettes')) {
            $c->setEtiquettes(preg_split('/[,;]/', (string) $d['etiquettes']) ?: []);
        }
        if ($a('marketing')) {
            $c->setAccepteMarketing((bool) $d['marketing']);
        }
        if ($a('suivi')) {
            $u = (int) $d['suivi'] > 0 ? $this->em->find(User::class, (int) $d['suivi']) : null;
            $c->setSuiviPar($u instanceof User && $u->isActif() ? $u : null);
        }
        if ($a('latitude') || $a('longitude')) {
            $lat = $texte('latitude');
            $lng = $texte('longitude');
            if (null !== $lat && null !== $lng && is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
                if ((float) $lat !== $c->getLatitude() || (float) $lng !== $c->getLongitude()) {
                    $c->localiser((float) $lat, (float) $lng, $maintenant);
                }
            } elseif (null === $lat && null === $lng) {
                $c->localiser(null, null);
            }
        }

        // Conditions commerciales : au-delà des seuils, demande à la direction.
        if ($a('grille')) {
            $grille = 'B2B' === $d['grille'] ? 'B2B' : 'B2C';
            if ('B2B' === $grille && !$c->isB2B() && !$direction) {
                $demandes[] = 'grille Revendeur (B2B)';
            } else {
                $c->setTypeClient($grille);
            }
        }
        if ($a('remise')) {
            $remise = null !== $texte('remise') ? (float) str_replace(',', '.', (string) $d['remise']) : null;
            if (null !== $remise && $remise > self::REMISE_MAX && !$direction && $remise !== $c->getRemiseHabituelle()) {
                $demandes[] = 'remise de '.self::pourcent($remise);
            } else {
                $c->setRemiseHabituelle($remise);
            }
        }
        if ($a('plafond')) {
            $plafond = null !== $texte('plafond') ? (int) preg_replace('/\D/', '', (string) $d['plafond']) : null;
            if (null !== $plafond && $plafond > self::PLAFOND_MAX && !$direction && $plafond !== $c->getPlafondCredit()) {
                $demandes[] = 'plafond de '.self::francs($plafond);
            } else {
                $c->setPlafondCredit($plafond);
            }
        }
        if ($a('acompte')) {
            $acompte = null !== $texte('acompte') ? (int) $d['acompte'] : null;
            // Un acompte à 0 % revient à dispenser le client : même règle que la case.
            if (0 === $acompte && !$direction && 0 !== $c->getAcompte()) {
                $demandes[] = 'acompte de 0 %';
            } else {
                $c->setAcompte($acompte);
            }
        }
        // Dispensé d'acompte (docs/clients.md) : la case se change par la direction ; sinon, demande à la direction.
        if ($a('sansAcompte')) {
            $sans = (bool) $d['sansAcompte'];
            if ($sans !== $c->isSansAcompte() && !$direction) {
                $demandes[] = $sans ? 'dispense d\'acompte' : 'fin de la dispense d\'acompte';
            } else {
                $c->setSansAcompte($sans);
            }
        }

        // Contacts : vérifiés avant d'appliquer quoi que ce soit.
        $saisis = null;
        if ($a('contacts')) {
            $saisis = [];
            foreach ((array) $d['contacts'] as $i => $k) {
                $nom = trim((string) ($k['nom'] ?? ''));
                $tel = ContactClient::chiffres((string) ($k['telephone'] ?? ''));
                if ('' === $nom && '' === $tel) {
                    continue;
                }
                if ('' === $nom) {
                    $erreurs['contacts'] = 'Chaque contact doit avoir un nom.';
                }
                if ('' !== $tel && 8 !== \strlen($tel)) {
                    $erreurs['contacts'] = 'Le téléphone de '.('' !== $nom ? $nom : 'ce contact').' doit avoir 8 chiffres.';
                }
                $saisis[] = ['id' => (int) ($k['id'] ?? 0), 'nom' => $nom, 'fonction' => $k['fonction'] ?? null, 'telephone' => $tel, 'whatsapp' => !empty($k['whatsapp']), 'role' => (string) ($k['role'] ?? 'autre')];
            }
            if ([] !== $saisis && !\in_array('principal', array_column($saisis, 'role'), true)) {
                $saisis[0]['role'] = 'principal';
            }
        }
        $principal = null;
        foreach ($saisis ?? array_map(fn (ContactClient $k) => ['nom' => $k->getNom(), 'telephone' => (string) $k->getTelephone(), 'role' => $k->getRole()], $this->contacts($c)) as $k) {
            if ('principal' === $k['role']) {
                $principal = $k;
                break;
            }
        }

        // Téléphone de la fiche : saisi (particulier) ou celui du contact principal.
        if ($a('telephone') && null !== $texte('telephone')) {
            $c->setTelephone(ContactClient::chiffres((string) $d['telephone']));
        } elseif (null !== $principal && '' !== $principal['telephone'] && (null === $c->getTelephone() || $c->isOrganisation())) {
            $c->setTelephone($principal['telephone']);
        }
        $tel = (string) $c->getTelephone();
        if ('' === $tel) {
            $erreurs['telephone'] = $c->isOrganisation() ? 'Indiquez le téléphone du contact principal.' : 'Le téléphone est obligatoire.';
        } elseif (!preg_match('/^\d{8}$/', ContactClient::chiffres($tel))) {
            $erreurs['telephone'] = 'Le téléphone doit avoir 8 chiffres.';
        } elseif ($verifierDoublons) {
            foreach ($this->doublons($tel, $c) as $doublon) {
                if ('client' === $doublon['type']) {
                    $erreurs['telephone'] = 'Ce numéro est déjà celui de '.$doublon['nom'].'.';
                }
            }
        }

        // Noms : le particulier saisit prénom et nom ; l'organisation prend
        // ceux de son contact principal (colonnes obligatoires).
        if ($c->isOrganisation()) {
            if (null === $c->getRaisonSociale()) {
                $erreurs['raisonSociale'] = 'La raison sociale est obligatoire.';
            }
            if (null !== $principal && '' !== $principal['nom']) {
                [$prenom, $nom] = self::separer($principal['nom']);
            } else {
                $prenom = $c->getPrenom() ?? 'Contact';
                $nom = $c->getNom() ?? (string) $c->getRaisonSociale();
            }
            $c->setPrenom(mb_substr($prenom, 0, 50))->setNom(mb_substr('' !== $nom ? $nom : (string) $c->getRaisonSociale(), 0, 50));
        } else {
            if ($a('nomComplet')) {
                [$prenom, $nom] = self::separer((string) $d['nomComplet']);
                $d['prenom'] = $prenom;
                $d['nom'] = $nom;
            }
            if ($a('prenom')) {
                $c->setPrenom(null !== $texte('prenom') ? mb_substr($texte('prenom'), 0, 50) : null);
            }
            if ($a('nom')) {
                $c->setNom(null !== $texte('nom') ? mb_substr($texte('nom'), 0, 50) : null);
            }
            if (null === $c->getPrenom() || null === $c->getNom()) {
                $erreurs['nom'] = 'Indiquez le prénom et le nom du client.';
            }
        }

        if ([] !== $erreurs) {
            return ['erreurs' => $erreurs, 'demandes' => []];
        }
        if ($simuler) {
            return ['erreurs' => [], 'demandes' => $demandes];
        }

        if ($creation) {
            $c->setCode('TMP-'.bin2hex(random_bytes(12)))->setStatut(true);
            $this->em->persist($c);
        }
        $journalContacts = null !== $saisis ? $this->synchroniserContacts($c, $saisis) : [];

        $qui = null !== $par ? Pointages::nom($par) : null;
        $noter = function (string $champ, string $categorie, ?string $av, ?string $ap) use ($c, $maintenant, $par, $depuis, $qui): void {
            $this->em->persist(new ModificationClient($c, $champ, $categorie, $av, $ap, $maintenant, $par, $depuis, $qui));
        };
        if ($creation) {
            $noter('Fiche créée', 'identite', null, $c->getNomComplet().' · '.$c->getTypeCompteLabel());
        } else {
            $apres = self::instantane($c);
            foreach (self::CHAMPS as $champ => [$libelle, $categorie]) {
                if (($avant[$champ] ?? null) !== ($apres[$champ] ?? null)) {
                    $noter($libelle, $categorie, $avant[$champ] ?? null, $apres[$champ] ?? null);
                }
            }
            foreach ($journalContacts as [$champ, $av, $ap]) {
                $noter($champ, 'contacts', $av, $ap);
            }
        }

        if ([] !== $demandes) {
            $noter('Demande à valider', 'conditions', null, ucfirst(implode(', ', $demandes)));
            $this->notifications->notifierRoles(
                ['ROLE_ADMIN'],
                sprintf('%s demande pour %s : %s.', $qui ?? 'Un utilisateur', $c->getNomComplet(), implode(', ', $demandes)),
                'app_clients_modifier',
                ['publicId' => $c->getPublicId()->toRfc4122()],
                $par,
            );
        }

        return ['erreurs' => [], 'demandes' => $demandes];
    }

    /** À appeler après le premier enregistrement d'une nouvelle fiche. */
    public function terminerCreation(Clients $c, ?Prospect $prospect, \DateTimeImmutable $maintenant): void
    {
        $c->genererCodeDepuisId();
        if (null !== $prospect && null === $prospect->getClient()) {
            $prospect->setClient($c)->changerEtape(Prospect::ETAPE_GAGNE, null, $maintenant);
        }
    }

    /**
     * Fiches, contacts et prospects ouverts qui ont déjà ce numéro.
     *
     * @return list<array{type: string, nom: string, detail: string, publicId: ?string, prospectId: ?int}>
     */
    public function doublons(string $telephone, ?Clients $sauf = null): array
    {
        $tel = ContactClient::chiffres($telephone);
        if (8 !== \strlen($tel)) {
            return [];
        }
        $formes = [$tel, '223'.$tel, '+223'.$tel, '00223'.$tel];
        $trouves = [];
        $vus = [];
        foreach ($this->em->getRepository(Clients::class)->findBy(['telephone' => $formes]) as $c) {
            if (null !== $sauf && $c->getId() === $sauf->getId()) {
                continue;
            }
            $vus[$c->getId()] = true;
            $trouves[] = ['type' => 'client', 'nom' => $c->getNomComplet(), 'detail' => 'client '.mb_strtolower($c->getTypeCompteLabel()), 'publicId' => $c->getPublicId()->toRfc4122(), 'prospectId' => null];
        }
        foreach ($this->em->getRepository(ContactClient::class)->findBy(['telephone' => $tel]) as $k) {
            $c = $k->getClient();
            if (isset($vus[$c->getId()]) || (null !== $sauf && $c->getId() === $sauf->getId())) {
                continue;
            }
            $vus[$c->getId()] = true;
            $trouves[] = ['type' => 'contact', 'nom' => $c->getNomComplet(), 'detail' => 'contact '.$k->getNom(), 'publicId' => $c->getPublicId()->toRfc4122(), 'prospectId' => null];
        }
        $prospects = $this->em->getRepository(Prospect::class)->createQueryBuilder('p')
            ->where('p.etape NOT IN (:clos)')->andWhere('p.client IS NULL')->andWhere('p.telephone IS NOT NULL')
            ->setParameter('clos', [Prospect::ETAPE_GAGNE, Prospect::ETAPE_PERDU])
            ->getQuery()->getResult();
        foreach ($prospects as $p) {
            if (ContactClient::chiffres($p->getTelephone()) !== $tel) {
                continue;
            }
            $trouves[] = ['type' => 'prospect', 'nom' => $p->getNom(), 'detail' => 'prospect créé le '.$p->getCreeLe()->format('d/m'), 'publicId' => null, 'prospectId' => $p->getId()];
        }

        return $trouves;
    }

    /**
     * Valeurs lisibles de la fiche pour le journal.
     *
     * @return array<string, ?string>
     */
    public static function instantane(Clients $c): array
    {
        return [
            'typeCompte' => $c->getTypeCompteLabel(),
            'raisonSociale' => $c->getRaisonSociale(),
            'prenom' => $c->isOrganisation() ? null : $c->getPrenom(),
            'nom' => $c->isOrganisation() ? null : $c->getNom(),
            'telephone' => $c->getTelephone(),
            'email' => $c->getEmail(),
            'nif' => $c->getNif(),
            'rccm' => $c->getRccm(),
            'secteur' => $c->getSecteur(),
            'entiteFacturation' => $c->getEntiteFacturation(),
            'commune' => $c->getCommune(),
            'quartier' => $c->getQuartier(),
            'repere' => $c->getRepere(),
            'position' => null !== $c->getLatitude() ? sprintf('%.5f, %.5f', $c->getLatitude(), $c->getLongitude()) : null,
            'origine' => $c->getOrigineLabel(),
            'suiviPar' => null !== $c->getSuiviPar() ? Pointages::nom($c->getSuiviPar()) : null,
            'marketing' => $c->isAccepteMarketing() ? 'Oui' : 'Non',
            'observation' => $c->getObservation(),
            'etiquettes' => [] !== $c->getEtiquettes() ? implode(', ', $c->getEtiquettes()) : null,
            'besoin' => $c->getBesoin(),
            'typeClient' => self::GRILLES[$c->getTypeClient()] ?? $c->getTypeClient(),
            'remiseHabituelle' => null !== $c->getRemiseHabituelle() ? self::pourcent($c->getRemiseHabituelle()) : null,
            'delaiPaiement' => $c->getDelaiPaiementLabel(),
            'plafondCredit' => null !== $c->getPlafondCredit() ? self::francs($c->getPlafondCredit()) : null,
            'acompte' => null !== $c->getAcompte() ? $c->getAcompte().' %' : null,
            'sansAcompte' => $c->isSansAcompte() ? 'Oui' : 'Non',
            'moyenPrefere' => $c->getMoyenPrefereLabel(),
        ];
    }

    /** « Dr Fatoumata Konaté » : prénom « Dr Fatoumata », nom « Konaté ». */
    public static function separer(string $nomComplet): array
    {
        $mots = preg_split('/\s+/', trim($nomComplet)) ?: [];
        if (\count($mots) < 2) {
            return [$mots[0] ?? '', $mots[0] ?? ''];
        }
        $nom = array_pop($mots);

        return [implode(' ', $mots), $nom];
    }

    public static function francs(int $n): string
    {
        return number_format($n, 0, ',', ' ').' F';
    }

    public static function pourcent(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2, ',', ''), '0'), ',').' %';
    }

    /**
     * @param list<array{id: int, nom: string, fonction: mixed, telephone: string, whatsapp: bool, role: string}> $saisis
     *
     * @return list<array{0: string, 1: ?string, 2: ?string}> lignes du journal
     */
    private function synchroniserContacts(Clients $c, array $saisis): array
    {
        $existants = [];
        foreach ($this->contacts($c) as $k) {
            $existants[$k->getId()] = $k;
        }
        $journal = [];
        foreach ($saisis as $s) {
            $k = $existants[$s['id']] ?? null;
            $avant = null !== $k ? self::resume($k) : null;
            if (null === $k) {
                $k = new ContactClient($c, $s['nom']);
                $this->em->persist($k);
            }
            unset($existants[$s['id']]);
            $k->setNom($s['nom'])->setFonction(null !== $s['fonction'] ? (string) $s['fonction'] : null)
                ->setTelephone($s['telephone'])->setWhatsapp($s['whatsapp'])->setRole($s['role']);
            $apres = self::resume($k);
            if (null === $avant) {
                $journal[] = ['Contact ajouté', null, $apres];
            } elseif ($avant !== $apres) {
                $journal[] = ['Contact modifié', $avant, $apres];
            }
        }
        foreach ($existants as $k) {
            $journal[] = ['Contact retiré', self::resume($k), null];
            $this->em->remove($k);
        }

        return $journal;
    }

    private static function resume(ContactClient $k): string
    {
        return implode(' · ', array_filter([
            $k->getNom(), $k->getFonction(), $k->getTelephoneAffiche(),
            $k->getTelephone() ? ($k->isWhatsapp() ? 'WhatsApp' : null) : null,
            mb_strtolower($k->getRoleLabel()),
        ]));
    }
}
