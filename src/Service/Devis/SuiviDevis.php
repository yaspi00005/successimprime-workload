<?php

namespace App\Service\Devis;

use App\Entity\Devis;
use App\Entity\User;
use App\Service\VentesService;
use App\Twig\NomCommercialExtension;

/**
 * Règles du suivi d'un devis (docs/devis.md), sans base ni envoi :
 * envoi d'une version, nouvelle version, ouverture par le client, refus,
 * demande de modification, relance due, expiration, et les textes des
 * écrans (colonne « Suivi » de la liste, suivi de la fiche, suivi du
 * client). Testé sans base (tests/Unit/Service/Devis/SuiviDevisTest.php).
 */
final class SuiviDevis
{
    /** Relance WhatsApp automatique : 3 jours après l'envoi, si le client n'a pas répondu. */
    public const JOURS_RELANCE = VentesService::JOURS_AVANT_RELANCE;

    /** Deux ouvertures à moins de 30 minutes comptent pour une. */
    public const MINUTES_ENTRE_VUES = 30;

    /**
     * Le devis part au client : version notée (instantané sans coût),
     * validité recalculée depuis aujourd'hui, statut « Envoyé ».
     *
     * @param list<string> $canaux
     */
    public static function envoyer(Devis $devis, array $canaux, ?string $parNomPublic, \DateTimeImmutable $maintenant): void
    {
        if ([] === $canaux) {
            $canaux = [Devis::CANAL_ESPACE];
        }
        // Ce que le client avait demandé et qui a fait naître cette version.
        $commentaire = $devis->getVersion() > 1 && $devis->attendUneModification() ? $devis->getDemandeModification() : null;
        $devis->setCanauxEnvoi($canaux)
            ->setEnvoyeLe($maintenant)
            ->setEnvoyePar($parNomPublic)
            ->setDateValidite($maintenant->modify(\sprintf('+%d days', $devis->getValiditeJours())))
            ->setStatut(Devis::STATUT_ENVOYE)
            ->setReponseLe(null)
            ->setReponduPar(null)
            ->setMotifRefus(null)
            ->setNombreVues(0)
            ->setPremiereVueLe(null)
            ->setDerniereVueLe(null)
            ->setVuPar(null);
        $devis->noterVersion(self::instantane($devis, $maintenant, $commentaire));
        if ('' === trim((string) $devis->getTokenAuthenticite())) {
            $devis->genererTokenAuthenticite();
        }
    }

    /**
     * « Nouvelle version » : le devis repasse en brouillon pour être
     * modifié puis renvoyé. La version envoyée reste dans l'historique.
     */
    public static function nouvelleVersion(Devis $devis): void
    {
        if (null === $devis->getVersionNumero($devis->getVersion()) && null !== $devis->getEnvoyeLe()) {
            // Devis envoyé avant le suivi des versions : sa version d'alors est gardée.
            $devis->noterVersion(self::instantane($devis, $devis->getEnvoyeLe()));
        }
        $devis->setVersion($devis->getVersion() + 1)->setStatut(Devis::STATUT_BROUILLON);
    }

    /**
     * Instantané de la version en cours : ce que le client a reçu.
     *
     * @return array{numero: int, le: string, total: int, resume: string, lignes: list<array{designation: string, quantite: float, total: int}>, commentaire: ?string}
     */
    public static function instantane(Devis $devis, \DateTimeImmutable $le, ?string $commentaire = null): array
    {
        $noms = new NomCommercialExtension();
        $lignes = [];
        foreach ($devis->getDevisDetails() as $l) {
            $lignes[] = [
                'designation' => explode(' · ', $noms->texteLigne($l))[0],
                'quantite' => (float) $l->getQuantite(),
                'total' => (int) $l->getTotalTtc(),
            ];
        }
        return [
            'numero' => $devis->getVersion(),
            'le' => $le->format(\DATE_ATOM),
            'total' => $devis->getTotalTtc(),
            'resume' => self::resume($lignes),
            'lignes' => $lignes,
            'commentaire' => $commentaire,
        ];
    }

    /**
     * « 3 Kakémono · Création graphique offerte » : les lignes en une
     * phrase, sans montant (le total s'affiche à part, selon les droits).
     *
     * @param list<array{designation: string, quantite: float, total: int}> $lignes
     */
    public static function resume(array $lignes): string
    {
        $morceaux = [];
        foreach ($lignes as $l) {
            $q = $l['quantite'];
            $texte = ($q > 1 ? self::nombre($q).' × ' : '').$l['designation'];
            if (0 === $l['total']) {
                $texte .= ' offert';
            }
            $morceaux[] = $texte;
        }
        $resume = implode(' · ', \array_slice($morceaux, 0, 4));
        if (\count($morceaux) > 4) {
            $resume .= \sprintf(' · +%d', \count($morceaux) - 4);
        }

        return mb_substr($resume, 0, 240);
    }

    /**
     * Ce qui a changé d'une version à l'autre, ligne par ligne, sans
     * montant : « ajout de 3 × Totem », « Panneau : 1 → 2 », « retrait
     * de Pose ». Les lignes se reconnaissent à leur désignation.
     *
     * @param array{lignes?: list<array{designation: string, quantite: float, total: int}>} $avant
     * @param array{lignes?: list<array{designation: string, quantite: float, total: int}>} $apres
     *
     * @return list<array{texte: string, sorte: string}> sorte : ajout, retrait, quantite, prix
     */
    public static function changements(array $avant, array $apres): array
    {
        $indexer = static function (array $lignes): array {
            $r = [];
            foreach ($lignes as $l) {
                $cle = mb_strtolower(trim($l['designation']));
                $r[$cle] = isset($r[$cle])
                    ? ['designation' => $l['designation'], 'quantite' => $r[$cle]['quantite'] + $l['quantite'], 'total' => $r[$cle]['total'] + $l['total']]
                    : $l;
            }

            return $r;
        };
        $a = $indexer($avant['lignes'] ?? []);
        $b = $indexer($apres['lignes'] ?? []);
        $quantite = static fn (float $q): string => $q > 1 ? self::nombre($q).' × ' : '';
        $c = [];
        foreach ($b as $cle => $l) {
            if (!isset($a[$cle])) {
                $c[] = ['texte' => 'ajout de '.$quantite($l['quantite']).$l['designation'], 'sorte' => 'ajout'];
            } elseif (abs($a[$cle]['quantite'] - $l['quantite']) > 0.0001) {
                $c[] = ['texte' => \sprintf('%s : %s → %s', $l['designation'], self::nombre($a[$cle]['quantite']), self::nombre($l['quantite'])), 'sorte' => 'quantite'];
            } elseif ($a[$cle]['total'] !== $l['total']) {
                $c[] = ['texte' => $l['designation'].' : prix revu', 'sorte' => 'prix'];
            }
        }
        foreach ($a as $cle => $l) {
            if (!isset($b[$cle])) {
                $c[] = ['texte' => 'retrait de '.$l['designation'], 'sorte' => 'retrait'];
            }
        }

        return $c;
    }

    /** Une phrase courte pour la liste des versions : « ajout de 3 × Totem · retrait de Pose ». */
    public static function resumeChangements(array $avant, array $apres): string
    {
        $c = array_column(self::changements($avant, $apres), 'texte');

        return [] === $c ? 'mêmes lignes' : mb_strimwidth(implode(' · ', $c), 0, 90, '…');
    }

    /**
     * Le client ouvre le devis (sa page dans l'espace client ou le lien
     * WhatsApp). Une ouverture à moins de 30 minutes de la précédente ne
     * compte pas. Rend vrai si l'ouverture est comptée.
     */
    public static function noterOuverture(Devis $devis, string $par, \DateTimeImmutable $maintenant): bool
    {
        if (!$devis->estEnAttente() && Devis::STATUT_EXPIRE !== $devis->getStatut()) {
            return false;
        }
        $derniere = $devis->getDerniereVueLe();
        if (null !== $derniere && $derniere > $maintenant->modify(\sprintf('-%d minutes', self::MINUTES_ENTRE_VUES))) {
            return false;
        }
        $devis->setNombreVues($devis->getNombreVues() + 1)->setDerniereVueLe($maintenant);
        if (null === $devis->getPremiereVueLe()) {
            $devis->setPremiereVueLe($maintenant)->setVuPar($par);
        }
        if (Devis::STATUT_ENVOYE === $devis->getStatut()) {
            $devis->setStatut(Devis::STATUT_VU);
        }

        return true;
    }

    /** Refus du client (dans son espace) ou noté à la boutique (refus au téléphone). */
    public static function refuser(Devis $devis, string $cle, ?string $precision, string $par, \DateTimeImmutable $maintenant): void
    {
        $libelle = Devis::MOTIFS_REFUS[$cle] ?? Devis::MOTIFS_REFUS['autre'];
        $precision = trim((string) preg_replace('/\s+/u', ' ', (string) $precision));
        $devis->setStatut(Devis::STATUT_REFUSE)
            ->setMotifRefus('' === $precision ? $libelle : $libelle.' · '.mb_substr($precision, 0, 200))
            ->setReponseLe($maintenant)
            ->setReponduPar($par);
    }

    public static function demanderModification(Devis $devis, string $texte, string $par, \DateTimeImmutable $maintenant): void
    {
        $devis->setDemandeModification(mb_substr(trim($texte), 0, 1000))->setDemandeModificationLe($maintenant)->setReponduPar($par);
    }

    /** Le client accepte : la commande est créée par AcceptationDevis. */
    public static function accepter(Devis $devis, string $par, \DateTimeImmutable $maintenant): void
    {
        $devis->setStatut(Devis::STATUT_ACCEPTE)->setReponseLe($maintenant)->setReponduPar($par);
    }

    /** Validité dépassée (fin de journée comprise) pour un devis encore sans réponse. */
    public static function estAExpirer(Devis $devis, \DateTimeImmutable $maintenant): bool
    {
        return $devis->estEnAttente() && null !== $devis->getDateValidite()
            && $devis->getDateValidite()->format('Y-m-d') < $maintenant->format('Y-m-d');
    }

    /**
     * Relance due : envoyé ou vu sans réponse depuis 3 jours, encore
     * valable, et pas déjà relancé depuis l'envoi de cette version.
     */
    public static function relanceDue(Devis $devis, \DateTimeImmutable $maintenant): bool
    {
        $envoi = $devis->getEnvoyeLe();

        return $devis->estEnAttente() && null !== $envoi && !$devis->isDeleted()
            && $envoi->setTime(0, 0) <= $maintenant->setTime(0, 0)->modify(\sprintf('-%d days', self::JOURS_RELANCE))
            && !self::estAExpirer($devis, $maintenant)
            && null === $devis->getDerniereRelanceDepuisEnvoi();
    }

    /** Date de la relance automatique prévue, ou null s'il n'y en a pas (ou plus). */
    public static function relancePrevue(Devis $devis): ?\DateTimeImmutable
    {
        $envoi = $devis->getEnvoyeLe();
        if (!$devis->estEnAttente() || null === $envoi || null !== $devis->getDerniereRelanceDepuisEnvoi()) {
            return null;
        }
        $le = $envoi->modify(\sprintf('+%d days', self::JOURS_RELANCE))->setTime(9, 0);

        return null !== $devis->getDateValidite() && $le->format('Y-m-d') > $devis->getDateValidite()->format('Y-m-d') ? null : $le;
    }

    /**
     * Colonne « Suivi » de la liste (maquette GC03) : « vu 2 fois · expire
     * 23/10 », « version 2 », « → commande CM-0166 », « motif : prix ».
     *
     * @return array{texte: string, ton: string}
     */
    public static function texteSuivi(Devis $devis, \DateTimeImmutable $maintenant): array
    {
        $expire = null !== $devis->getDateValidite() ? 'expire '.$devis->getDateValidite()->format('d/m') : null;
        $version = $devis->getVersion() > 1 ? 'version '.$devis->getVersion() : null;

        return match ($devis->getStatut()) {
            Devis::STATUT_BROUILLON => ['texte' => $version ? $version.' en préparation' : '—', 'ton' => 'gris'],
            Devis::STATUT_ENVOYE, Devis::STATUT_VU => match (true) {
                $devis->attendUneModification() => ['texte' => 'modification demandée', 'ton' => 'orange'],
                Devis::STATUT_VU === $devis->getStatut() => ['texte' => implode(' · ', array_filter(['vu '.($devis->getNombreVues() > 1 ? $devis->getNombreVues().' fois' : 'une fois'), $expire])), 'ton' => 'normal'],
                default => ['texte' => implode(' · ', array_filter([$version ?? 'pas encore ouvert', $expire])), 'ton' => 'normal'],
            },
            Devis::STATUT_ACCEPTE => ['texte' => 'accepté'.($devis->getReponseLe() ? ' le '.$devis->getReponseLe()->format('d/m') : '').' · à transformer', 'ton' => 'vert'],
            Devis::STATUT_CONVERTI => ['texte' => '→ commande '.($devis->getCommande()?->getNumero() ?? ''), 'ton' => 'vert'],
            Devis::STATUT_EXPIRE => ['texte' => 'expiré'.($devis->getDateValidite() ? ' le '.$devis->getDateValidite()->modify('+1 day')->format('d/m') : ''), 'ton' => 'orange'],
            Devis::STATUT_REFUSE => ['texte' => 'motif : '.($devis->getMotifRefusCourt() ?? 'non donné'), 'ton' => 'rouge'],
            default => ['texte' => '—', 'ton' => 'gris'],
        };
    }

    /**
     * Suivi de la fiche (maquette DT03), dans l'ordre : envoyé par, ouvert
     * par, ouvertures suivantes, demande du client, relances, relance
     * prévue, réponse, expiration.
     *
     * @return list<array{le: ?\DateTimeImmutable, texte: string, futur: bool}>
     */
    public static function evenements(Devis $devis, \DateTimeImmutable $maintenant): array
    {
        $e = [];
        $enCours = Devis::STATUT_BROUILLON !== $devis->getStatut() && null !== $devis->getEnvoyeLe();
        foreach (array_reverse($devis->getVersions()) as $v) {
            // La version en cours est dite plus bas, avec qui l'a envoyée et comment.
            if (!$enCours || $v['numero'] !== $devis->getVersion()) {
                $e[] = ['le' => new \DateTimeImmutable($v['le']), 'texte' => \sprintf('Version %d envoyée', $v['numero']), 'futur' => false];
            }
        }
        if ($enCours) {
            $canaux = array_map(static fn (string $c): string => Devis::CANAUX_LIBELLES[$c] ?? $c, $devis->getCanauxEnvoi());
            $par = $devis->getEnvoyePar() ? ' par '.explode(' (', $devis->getEnvoyePar())[0] : '';
            $e[] = ['le' => $devis->getEnvoyeLe(), 'texte' => ($devis->getVersion() > 1 ? 'Version '.$devis->getVersion().' envoyée' : 'Envoyé').$par.([] !== $canaux ? ' · '.implode(' + ', $canaux) : ''), 'futur' => false];
        }
        if (null !== $devis->getPremiereVueLe()) {
            $e[] = ['le' => $devis->getPremiereVueLe(), 'texte' => 'Ouvert'.($devis->getVuPar() ? ' par '.$devis->getVuPar() : ''), 'futur' => false];
            if ($devis->getNombreVues() > 1 && null !== $devis->getDerniereVueLe()) {
                $e[] = ['le' => $devis->getDerniereVueLe(), 'texte' => $devis->getNombreVues() > 2 ? \sprintf('Ouvert %d fois en tout', $devis->getNombreVues()) : 'Ouvert une 2e fois', 'futur' => false];
            }
        }
        if (null !== $devis->getDemandeModificationLe()) {
            $e[] = ['le' => $devis->getDemandeModificationLe(), 'texte' => 'Modification demandée : « '.mb_strimwidth((string) $devis->getDemandeModification(), 0, 90, '…').' »', 'futur' => false];
        }
        foreach ($devis->getRelances() as $r) {
            $e[] = ['le' => new \DateTimeImmutable($r['le']), 'texte' => $r['auto'] ? 'Relance WhatsApp automatique' : 'Relancé sur WhatsApp'.($r['par'] ? ' par '.$r['par'] : ''), 'futur' => false];
        }
        if (null !== $devis->getReponseLe()) {
            $texte = match (true) {
                $devis->estAccepte() => 'Accepté'.($devis->getReponduPar() ? ' par '.$devis->getReponduPar() : ''),
                Devis::STATUT_REFUSE === $devis->getStatut() => 'Refusé'.($devis->getMotifRefus() ? ' : '.$devis->getMotifRefus() : ''),
                default => null,
            };
            if (null !== $texte) {
                $e[] = ['le' => $devis->getReponseLe(), 'texte' => $texte, 'futur' => false];
            }
        }
        if (null !== $devis->getCommande()) {
            $e[] = ['le' => $devis->getCommande()->getDateCommande() ? \DateTimeImmutable::createFromInterface($devis->getCommande()->getDateCommande()) : null, 'texte' => 'Commande '.$devis->getCommande()->getNumero().' créée', 'futur' => false];
        }
        usort($e, static fn (array $a, array $b): int => ($a['le'] ?? $maintenant) <=> ($b['le'] ?? $maintenant));

        $prevue = self::relancePrevue($devis);
        if (null !== $prevue) {
            $e[] = ['le' => $prevue, 'texte' => $prevue <= $maintenant ? 'Relance à faire' : 'Relance automatique prévue', 'futur' => true];
        }
        if (null !== $devis->getDateValidite() && ($devis->estEnAttente() || Devis::STATUT_EXPIRE === $devis->getStatut())) {
            $e[] = ['le' => $devis->getDateValidite(), 'texte' => Devis::STATUT_EXPIRE === $devis->getStatut() ? 'Expiré' : 'Expiration', 'futur' => Devis::STATUT_EXPIRE !== $devis->getStatut()];
        }

        return $e;
    }

    /**
     * « Suivi du devis » côté client (maquette DT01) : Demandé, Version n
     * envoyée, Votre réponse, Commande. Chaque étape : faite, en cours
     * (action attendue) ou à venir.
     *
     * @return list<array{libelle: string, detail: ?string, etat: string}>
     */
    public static function etapesClient(Devis $devis, ?\DateTimeImmutable $demandeLe, \DateTimeImmutable $maintenant): array
    {
        $etapes = [];
        if (null !== $demandeLe) {
            $etapes[] = ['libelle' => 'Demandé', 'detail' => $demandeLe->format('d/m').' · par vous', 'etat' => 'faite'];
        }
        $versions = array_reverse($devis->getVersions());
        if ([] === $versions && null !== $devis->getEnvoyeLe()) {
            $versions = [['numero' => $devis->getVersion(), 'le' => $devis->getEnvoyeLe()->format(\DATE_ATOM)]];
        }
        foreach ($versions as $v) {
            $le = new \DateTimeImmutable($v['le']);
            $etapes[] = ['libelle' => \count($versions) > 1 ? \sprintf('Version %d envoyée', $v['numero']) : 'Devis envoyé', 'detail' => $le->format('d/m · H:i'), 'etat' => 'faite'];
        }
        $reponse = match (true) {
            $devis->estAccepte() => ['libelle' => 'Accepté', 'detail' => $devis->getReponseLe()?->format('d/m').($devis->getReponduPar() ? ' · '.$devis->getReponduPar() : ''), 'etat' => 'faite'],
            Devis::STATUT_REFUSE === $devis->getStatut() => ['libelle' => 'Refusé', 'detail' => $devis->getReponseLe()?->format('d/m'), 'etat' => 'faite'],
            Devis::STATUT_EXPIRE === $devis->getStatut() => ['libelle' => 'Expiré', 'detail' => 'le '.$devis->getDateValidite()?->modify('+1 day')->format('d/m'), 'etat' => 'faite'],
            $devis->attendUneModification() => ['libelle' => 'Modification demandée', 'detail' => 'nous préparons une nouvelle version', 'etat' => 'action'],
            default => ['libelle' => 'Votre réponse', 'detail' => $devis->getDateValidite() ? 'avant le '.$devis->getDateValidite()->format('d/m') : null, 'etat' => 'action'],
        };
        $etapes[] = $reponse;
        $commande = $devis->getCommande();
        $etapes[] = null !== $commande
            ? ['libelle' => 'Commande '.$commande->getNumero(), 'detail' => 'créée à l\'acceptation', 'etat' => 'faite']
            : ['libelle' => 'Commande', 'detail' => 'créée dès l\'acceptation', 'etat' => Devis::STATUT_REFUSE === $devis->getStatut() || Devis::STATUT_EXPIRE === $devis->getStatut() ? 'annulee' : 'avenir'];

        return $etapes;
    }

    /**
     * Message WhatsApp (envoi ou relance), sans montant : le prix se lit
     * sur le devis, derrière le lien.
     */
    public static function messageWhatsApp(Devis $devis, string $lien, bool $relance): string
    {
        $client = $devis->getClients();
        $nom = trim((string) ($client?->getRaisonSociale() ?: $client?->getNomComplet()));
        $objet = $devis->getObjet() ? ' ('.$devis->getObjet().')' : '';
        $validite = $devis->getDateValidite() ? ' Il est valable jusqu\'au '.$devis->getDateValidite()->format('d/m').'.' : '';

        return $relance
            ? trim(\sprintf('Bonjour %s, petit rappel : votre devis %s%s de Succès Imprim vous attend.%s Vous pouvez le consulter et l\'accepter ici : %s Une question ? Répondez à ce message.', $nom, $devis->getNumero(), $objet, $validite, $lien))
            : trim(\sprintf('Bonjour %s, voici votre devis %s%s de Succès Imprim.%s Consultez-le et acceptez-le ici : %s Une question ? Répondez à ce message.', $nom, $devis->getNumero(), $objet, $validite, $lien));
    }

    /** Ce que le client voit de l'employé : « Moussa (commercial) », ou son identifiant sans fiche employé. */
    public static function nomPublic(?User $u): ?string
    {
        if (null === $u) {
            return null;
        }

        return $u->getEmploye()?->getNomPublic() ?? $u->getUsername();
    }

    private static function nombre(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    }
}
