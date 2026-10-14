<?php

namespace App\Service\Import;

use App\Entity\CompteTresorerie;
use App\Entity\DecaissementRecurrent;
use App\Entity\Echeance;
use App\Entity\ImportDonnees;
use App\Entity\SoldeOuverture;

/**
 * Solde de départ des comptes de trésorerie (caisse, banque, Orange
 * Money, Wave), docs/import-donnees.md.
 *
 * Le solde importé devient le solde initial du compte, comme s'il avait
 * été saisi à sa création : le journal du compte, la caisse du jour et
 * la trésorerie partent de lui. Seulement pour un compte qui n'a encore
 * aucune opération : sinon le solde initial réécrirait le passé, et un
 * ajustement de trésorerie reste la bonne voie.
 */
final class ImportSoldesComptes extends ImportSoldes
{
    public function cle(): string
    {
        return 'soldes_comptes';
    }

    public function libelle(): string
    {
        return 'Soldes des comptes';
    }

    public function description(): string
    {
        return 'Ce qu\'il y a au départ dans la caisse, en banque, sur Orange Money et Wave. Pour les comptes qui n\'ont encore aucune opération.';
    }

    public function droits(): array
    {
        return ['ROLE_ADMIN', 'ROLE_TRESORERIE_VOIR', 'ROLE_TRESORERIE_SAISIR', 'ROLE_COMPTABILITE'];
    }

    public function colonnes(): array
    {
        return [
            ['cle' => 'compte', 'libelle' => 'Compte', 'alias' => ['code', 'nom du compte', 'code compte', 'tresorerie'], 'obligatoire' => true,
                'exemples' => ['Caisse boutique', 'BDM'], 'aide' => 'Le code ou le nom d\'un compte de trésorerie déjà créé (Finances › Trésorerie).'],
            ['cle' => 'solde', 'libelle' => 'Solde', 'alias' => ['montant', 'solde de depart', 'solde initial', 'disponible'], 'obligatoire' => true,
                'exemples' => ['185 000', '4 750 000'], 'aide' => 'En francs, 0 ou plus : l\'argent compté ou le relevé du jour d\'ouverture.'],
        ];
    }

    public function notes(): array
    {
        return [
            'Une ligne par compte. Les comptes ne sont pas créés par l\'import : créez-les d\'abord dans Finances › Trésorerie.',
            'Le solde devient le solde initial du compte. Seulement pour un compte sans aucune opération ; pour un compte déjà utilisé, faites un ajustement de trésorerie.',
            'Un découvert (solde négatif) ne s\'importe pas : saisissez-le comme ajustement.',
            'L\'import est en tout ou rien : corrigez chaque ligne en erreur et renvoyez le fichier.',
        ];
    }

    public function analyser(array $lignes, ContexteImport $contexte): void
    {
        $comptes = [];
        foreach ($this->em->getRepository(CompteTresorerie::class)->findAll() as $c) {
            $comptes[Valeurs::cle($c->getCode())] = $c;
            $comptes[Valeurs::cle($c->getNom())] ??= $c;
        }
        $dejaImportes = [];
        foreach ($this->em->createQuery('SELECT IDENTITY(s.compte) AS compte FROM '.SoldeOuverture::class.' s WHERE s.annuleLe IS NULL AND s.type = :t')
            ->setParameter('t', SoldeOuverture::COMPTE)->getArrayResult() as $s) {
            $dejaImportes[(int) $s['compte']] = true;
        }
        $vus = [];
        foreach ($lignes as $ligne) {
            $ligne->libelle = $ligne->valeur('compte');
            $compte = $comptes[Valeurs::cle($ligne->valeur('compte'))] ?? null;
            if (null === $compte) {
                $ligne->erreur('' === $ligne->valeur('compte') ? 'Le compte est obligatoire.' : sprintf('Aucun compte de trésorerie « %s » : créez-le d\'abord.', $ligne->valeur('compte')));
                continue;
            }
            $ligne->libelle = $compte->getNom();
            $montant = self::lireMontant($ligne, 'solde', true);
            if (null === $montant) {
                continue;
            }
            if ($montant < 0) {
                $ligne->erreur('Un solde négatif (découvert) ne s\'importe pas : saisissez un ajustement de trésorerie.');
                continue;
            }
            if (isset($vus[$compte->getId()])) {
                $ligne->erreur(sprintf('Ce compte est déjà à la ligne %d.', $vus[$compte->getId()]));
                continue;
            }
            $vus[$compte->getId()] = $ligne->numero;
            if (isset($dejaImportes[$compte->getId()])) {
                $ligne->erreur('Ce compte a déjà un solde d\'ouverture importé : annulez d\'abord cet import.');
                continue;
            }
            $operations = $this->operations($compte);
            if ([] !== $operations) {
                $ligne->erreur(sprintf('Le compte a déjà des opérations (%s) : faites un ajustement de trésorerie.', References::phrase($operations)));
                continue;
            }
            if ($montant === $compte->getSoldeInitial()) {
                $ligne->ignorer(sprintf('Le solde initial est déjà de %s.', Valeurs::francs($montant)));
                continue;
            }
            $ligne->statut = LigneImport::MAJ;
            $ligne->detail = sprintf('%s · solde initial %s → %s', $compte->getTypeLabel(), Valeurs::francs($compte->getSoldeInitial()), Valeurs::francs($montant));
            $ligne->donnees = ['compte' => $compte->getId(), 'montant' => $montant];
        }
    }

    public function appliquer(array $lignes, ContexteImport $contexte, ImportDonnees $import): void
    {
        $enregistres = [];
        foreach ($lignes as $ligne) {
            if (!$ligne->aEcrire()) {
                self::noterAutres($import, $ligne);
                continue;
            }
            $compte = $this->em->find(CompteTresorerie::class, $ligne->donnees['compte']) ?? throw new \RuntimeException(sprintf('Ligne %d : compte introuvable.', $ligne->numero));
            $montant = (int) $ligne->donnees['montant'];
            // Un solde de 0 ne fait pas d'écriture : la trace garde l'ancien solde pour l'annulation.
            $solde = (new SoldeOuverture(SoldeOuverture::COMPTE, $montant, $contexte->jourOuverture(), $compte->getNom(), $contexte->par, $contexte->maintenant))
                ->setCompte($compte)->setAncienSoldeInitial($compte->getSoldeInitial())->setImport($import);
            $compte->setSoldeInitial($montant);
            $compte->setSoldeActuel($montant);
            $this->em->persist($solde);
            $enregistres[] = [$ligne, $solde];
        }
        $this->em->flush();
        foreach ($enregistres as [$ligne, $solde]) {
            self::noterSolde($import, $ligne, $solde);
        }
    }

    public function blocagesAnnulation(ImportDonnees $import): array
    {
        $utilises = [];
        foreach ($this->soldes($import) as $s) {
            if (null !== $s->getCompte() && [] !== $this->operations($s->getCompte())) {
                $utilises[] = $s->getTiers();
            }
        }

        return [] !== $utilises ? [sprintf('Des comptes ont eu des opérations depuis : %s.', implode(', ', $utilises))] : [];
    }

    public function annuler(ImportDonnees $import, ContexteImport $contexte): void
    {
        foreach ($this->soldes($import) as $s) {
            $compte = $s->getCompte();
            if (null !== $compte) {
                $ancien = (int) $s->getAncienSoldeInitial();
                $compte->setSoldeInitial($ancien);
                $compte->setSoldeActuel($ancien);
            }
            $s->annuler($contexte->maintenant);
        }
        $this->em->flush();
    }

    /**
     * Ce qui s'est passé sur le compte : mouvements, paiements, clôtures,
     * rapprochements, dépenses payées. Les réglages (décaissements
     * récurrents, échéances prévues) ne comptent pas.
     *
     * @return array<string, int>
     */
    private function operations(CompteTresorerie $compte): array
    {
        return $this->references->compter(CompteTresorerie::class, [(int) $compte->getId()], [SoldeOuverture::class, DecaissementRecurrent::class, Echeance::class]);
    }
}
