<?php

namespace App\Service\Production;

use App\Entity\OrdreProduction;
use App\Entity\TirageRouleau;
use App\Service\Atelier\AtelierProduction;
use App\Service\Atelier\PosteMachiniste;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le parcours d'un fichier, du BAT au colis (maquette PD01) : chaque étape
 * avec ce qui s'est vraiment passé et quand. Le serveur ne convertit pas
 * les couleurs (RVB → CMJN, PDF/X) : c'est le RIP qui le fait, l'écran le
 * dit tel quel.
 */
final class ParcoursFichier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TiragesRouleau $tirages,
        private readonly FichierProduction $fichiers,
    ) {
    }

    /**
     * L'ordre montré d'office : le dernier dont le fichier est parti au RIP
     * (rouleau envoyé), sinon le plus récemment envoyé en production.
     */
    public function dernierEnvoye(): ?OrdreProduction
    {
        $auRip = $this->em->createQueryBuilder()->select('o')->from(OrdreProduction::class, 'o')
            ->join('o.tirage', 't')
            ->where('t.envoyeLe IS NOT NULL')->andWhere('t.statut != :tirageAnnule')->andWhere('o.statut != :annule')
            ->setParameter('tirageAnnule', TirageRouleau::STATUT_ANNULE)->setParameter('annule', OrdreProduction::STATUT_ANNULE)
            ->orderBy('t.envoyeLe', 'DESC')->addOrderBy('o.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if ($auRip instanceof OrdreProduction) {
            return $auRip;
        }

        return $this->em->createQueryBuilder()->select('o')->from(OrdreProduction::class, 'o')
            ->where('o.transmisLe IS NOT NULL')->andWhere('o.statut != :annule')->setParameter('annule', OrdreProduction::STATUT_ANNULE)
            ->orderBy('o.transmisLe', 'DESC')->addOrderBy('o.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Ordres d'une commande (numéro) ou d'un client (nom, raison sociale).
     *
     * @return list<OrdreProduction>
     */
    public function rechercher(string $texte): array
    {
        $texte = trim($texte);
        if (mb_strlen($texte) < 2) {
            return [];
        }
        $motif = '%'.addcslashes($texte, '%_\\').'%';

        return $this->em->createQueryBuilder()->select('o', 'd', 'c', 'cl')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->join('d.commande', 'c')->leftJoin('c.clients', 'cl')
            ->where('c.numero LIKE :m OR cl.nom LIKE :m OR cl.prenom LIKE :m OR cl.raisonSociale LIKE :m OR CONCAT(cl.prenom, \' \', cl.nom) LIKE :m')
            ->setParameter('m', $motif)
            ->orderBy('o.id', 'DESC')->setMaxResults(20)
            ->getQuery()->getResult();
    }

    /**
     * Les sept étapes : BAT validé, fichier de production, imposition,
     * dossier de la machine, impression, finition, étiquette et contrôle.
     *
     * @return list<array{cle: string, titre: string, sous: string, detail: string, le: ?\DateTimeImmutable, etat: string, icone: string}>
     */
    public function etapes(OrdreProduction $o): array
    {
        $ligne = $o->getCommandeDetail();
        $controle = $ligne?->getDernierControlePrePresse() ?? $o->getControlePrePresse();
        $client = (string) $ligne?->getCommande()?->getClients()?->getNomComplet();
        $tirage = null !== $o->getTirage() && !$o->getTirage()->estAnnule() ? $o->getTirage() : null;

        // 1. BAT
        $batLe = $controle?->getValideLe();
        $batFait = null !== $batLe || (null !== $controle && !$controle->isBatNecessaire()) || null !== $o->getTransmisLe();
        $bat = null !== $batLe
            ? trim($client.' · '.$batLe->format('d/m H:i'), ' ·')
            : (null !== $controle && !$controle->isBatNecessaire() ? 'BAT non demandé' : (null !== $ligne?->getProduitClient() ? 'BAT du produit (« Mes produits »)' : 'en attente'));

        // 2. Fichier de production
        $f = PosteMachiniste::fichier($o);
        $nomSource = $f?->getNomOriginal() ?? (string) ($ligne?->getFichierLie()['nom'] ?? '');
        $infos = self::infosFichier($nomSource, $f?->getAnalyse() ?? []);
        if ([] === $infos) {
            $infos[] = null !== $f || null !== $ligne?->getFichierLie() ? 'couleurs converties au RIP si besoin' : 'pas encore de fichier';
        }
        $nom = FichierProduction::nomProduction($o);

        // 3. Imposition
        $imposition = null !== $tirage
            ? \sprintf('%s · %s · laize %s m · %d %%', $tirage->getNumero(), self::court($tirage->getLibelleSupport()), number_format($tirage->getLaizeM(), 2, ',', ' '), $tirage->getPourcent())
            : (null !== $o->getMachine() && !$this->tirages->estMachineRouleau($o->getMachine()) ? 'à la feuille : pas de rouleau' : 'pas encore sur un rouleau');

        // 4. Dossier de la machine
        $machine = $tirage?->getMachine() ?? $o->getMachine();
        $code = $this->fichiers->codeDossier($machine);
        $copieLe = $tirage?->getEnvoyeLe() ?? $o->getFichierMachineLe();
        if (null !== $o->getFichierMachineChemin() || null !== $tirage?->getCopieMachine()) {
            $dossier = (string) $this->fichiers->dossierAffiche($code).' · envoyé au RIP';
        } elseif (null !== $tirage?->getEnvoyeLe()) {
            $dossier = 'Dossier de la machine non réglé : le fichier reste dans l\'ERP';
        } else {
            $dossier = null !== $code ? (string) $this->fichiers->dossierAffiche($code) : 'dossier de la machine à régler';
        }

        // 5. Impression
        $profil = null !== $tirage ? $this->tirages->profil($tirage) : (null !== $machine ? $this->profilMachine($o) : null);
        $impression = null !== $profil
            ? implode(' · ', array_filter([pathinfo($profil['profilIcc'], \PATHINFO_FILENAME) ?: null, $profil['mode'] ?: null, null !== $profil['vitesseM2h'] ? ImpositionRouleau::nombre($profil['vitesseM2h']).' m²/h' : null]))
            : ($machine?->getNom() ?? 'machine à choisir');
        if (null !== $o->getDemarreLe() && null !== $o->getOperateur()) {
            $impression .= ' · '.AtelierProduction::prenom($o->getOperateur());
        }

        // 6. Finition, 7. Étiquette et contrôle
        $finitions = $ligne?->nomsFinitions() ?? [];
        $etiquette = match (true) {
            null !== $o->getPretLivraisonLe() => 'prêt pour la livraison',
            null !== $o->getEtiquettesImprimeesLe() => \sprintf('%d étiquette%s imprimée%s · scan = prêt', (int) $o->getNombreColis(), (int) $o->getNombreColis() > 1 ? 's' : '', (int) $o->getNombreColis() > 1 ? 's' : ''),
            default => 'scan = prêt',
        };

        $etapes = [
            ['cle' => 'bat', 'titre' => 'BAT validé', 'sous' => 'par le client', 'detail' => $bat, 'le' => $batLe, 'fait' => $batFait, 'icone' => 'fa-check'],
            ['cle' => 'fichier', 'titre' => 'Fichier de production', 'sous' => 'nommé par l\'ERP', 'detail' => implode(' · ', $infos), 'le' => null !== $o->getNomFichierProduction() ? $o->getTransmisLe() : null, 'fait' => null !== $o->getNomFichierProduction(), 'icone' => 'fa-file-pdf', 'nom' => $nom],
            ['cle' => 'imposition', 'titre' => 'Imposition', 'sous' => 'regroupé sur le rouleau', 'detail' => $imposition, 'le' => $tirage?->getCreeLe(), 'fait' => null !== $tirage, 'icone' => 'fa-th-large'],
            ['cle' => 'dossier', 'titre' => 'Dossier de la machine', 'sous' => 'envoyé au RIP', 'detail' => $dossier, 'le' => $copieLe, 'fait' => null !== $copieLe, 'icone' => 'fa-folder-open'],
            ['cle' => 'impression', 'titre' => 'Impression', 'sous' => 'profil et mode imposés', 'detail' => $impression, 'le' => $o->getDemarreLe(), 'fait' => null !== $o->getDemarreLe(), 'icone' => 'fa-print'],
            ['cle' => 'finition', 'titre' => 'Finition', 'sous' => [] !== $finitions ? 'finitions de la ligne' : 'coupe', 'detail' => [] !== $finitions ? implode(', ', array_map('mb_strtolower', $finitions)) : 'coupe suivant les repères', 'le' => $o->getTermineLe(), 'fait' => null !== $o->getTermineLe(), 'icone' => 'fa-cut'],
            ['cle' => 'etiquette', 'titre' => 'Étiquette et contrôle', 'sous' => 'scan = prêt', 'detail' => $etiquette, 'le' => $o->getPretLivraisonLe() ?? $o->getEtiquettesImprimeesLe(), 'fait' => null !== $o->getPretLivraisonLe(), 'icone' => 'fa-tag'],
        ];
        $encours = false;
        foreach ($etapes as $i => $e) {
            $etat = $e['fait'] ? 'fait' : ($encours ? 'avenir' : 'encours');
            if (!$e['fait']) {
                $encours = true;
            }
            $etapes[$i]['etat'] = $etat;
            $etapes[$i]['numero'] = $i + 1;
        }

        return $etapes;
    }

    /** Profil de la machine de l'ordre pour son support (ordre hors rouleau). */
    private function profilMachine(OrdreProduction $o): ?array
    {
        $machine = $o->getMachine();
        $ligne = $o->getCommandeDetail();
        $atelier = null !== $machine ? $this->tirages->atelier($machine) : null;
        if (null === $atelier || null === $ligne) {
            return null;
        }

        return ProfilsImpression::choisir($atelier->getProfils(), $ligne->getArticle()?->getId(), FichierProduction::libelleSupport($ligne), (string) FichierProduction::codeSupport($ligne));
    }

    /**
     * Ce que l'analyse du prépresse dit du fichier, tel quel : type, taille
     * (format de page d'un PDF), résolution réelle d'une image, fonds
     * perdus, couleurs. « PNG · 12 dpi (conseillé : 72) · RVB : conversion
     * aux couleurs d'impression au RIP ».
     *
     * @param list<array{libelle?: string, valeur?: string}> $analyse
     *
     * @return list<string>
     */
    public static function infosFichier(string $nomSource, array $analyse): array
    {
        $extension = strtoupper(NomFichierProduction::extension($nomSource, ''));
        $infos = '' !== $extension ? [$extension] : [];
        $couleurs = null;
        foreach ($analyse as $a) {
            $libelle = (string) ($a['libelle'] ?? '');
            $valeur = trim((string) ($a['valeur'] ?? ''));
            if ('' === $valeur) {
                continue;
            }
            match (true) {
                'Type de fichier' === $libelle && [] === $infos => $infos[] = $valeur,
                'Format de page' === $libelle => $infos[] = $valeur,
                str_starts_with($libelle, 'Résolution réelle') => $infos[] = $valeur,
                'Fonds perdus' === $libelle => $infos[] = 'fond perdu '.$valeur,
                'Mode colorimétrique' === $libelle => $couleurs = self::couleurs($valeur),
                default => null,
            };
        }
        if (null !== $couleurs) {
            $infos[] = $couleurs;
        }

        return $infos;
    }

    /** La vérité sur les couleurs : le RIP convertit, pas le serveur. */
    public static function couleurs(string $valeur): string
    {
        return match (true) {
            str_starts_with($valeur, 'RVB') => 'RVB : conversion aux couleurs d\'impression au RIP',
            str_starts_with($valeur, 'CMJN avec') => 'CMJN et RVB : le RIP convertit le RVB',
            str_starts_with($valeur, 'CMJN') => 'CMJN',
            default => 'couleurs non déclarées : à vérifier au RIP',
        };
    }

    /** « Bâche 440 g frontlit · laize 3,20 m » → « Bâche 440 g frontlit ». */
    private static function court(string $libelle): string
    {
        return trim(explode('·', $libelle)[0]);
    }
}
