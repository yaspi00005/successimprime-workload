<?php

namespace App\Service\Import;

use App\Entity\Depense;
use App\Entity\Fournisseurs;
use App\Entity\ImportDonnees;
use App\Entity\SoldeOuverture;

/**
 * Ce que la boutique devait à ses fournisseurs au démarrage
 * (docs/import-donnees.md).
 *
 * Chaque dette devient une dépense « à payer », marquée solde
 * d'ouverture : elle est dans l'échéancier et la prévision de trésorerie,
 * se paie comme une autre (décaissement à valider, compte 401), mais
 * n'entre ni dans les budgets ni dans les dépenses du mois : ce n'est pas
 * une charge de la période.
 */
final class ImportSoldesFournisseurs extends ImportSoldes
{
    public function cle(): string
    {
        return 'soldes_fournisseurs';
    }

    public function libelle(): string
    {
        return 'Dettes fournisseurs';
    }

    public function description(): string
    {
        return 'Ce que la boutique devait à ses fournisseurs au démarrage : à payer, avec leur échéance.';
    }

    public function droits(): array
    {
        return ['ROLE_ADMIN', 'ROLE_TRESORERIE_VOIR', 'ROLE_COMPTABILITE'];
    }

    public function colonnes(): array
    {
        return [
            ['cle' => 'fournisseur', 'libelle' => 'Fournisseur', 'alias' => ['nom', 'beneficiaire', 'creancier', 'nom du fournisseur'], 'obligatoire' => true,
                'exemples' => ['Sahel Supplies', 'Imprimerie du Centre'], 'aide' => 'Un fournisseur de la liste, par son nom. Sinon le nom est gardé comme bénéficiaire.'],
            ['cle' => 'montant', 'libelle' => 'Montant dû', 'alias' => ['montant', 'solde', 'reste a payer', 'dette', 'du'], 'obligatoire' => true,
                'exemples' => ['850 000', '120 000'], 'aide' => 'En francs, ce que la boutique doit encore. Plus que 0.'],
            ['cle' => 'echeance', 'libelle' => 'Échéance', 'alias' => ['date limite', 'a payer le', 'date d echeance'], 'obligatoire' => false,
                'exemples' => ['15/11/2026', ''], 'aide' => 'Jour où payer (jj/mm/aaaa). Vide : le jour d\'ouverture.'],
            ['cle' => 'objet', 'libelle' => 'Objet', 'alias' => ['note', 'detail', 'libelle', 'facture', 'reference'], 'obligatoire' => false,
                'exemples' => ['Bâches et encres, facture F-2026-118', ''], 'aide' => 'Ce que la dette couvre. Vide : « Solde d\'ouverture ».'],
        ];
    }

    public function notes(): array
    {
        return [
            'Une ligne par dette (un fournisseur peut en avoir plusieurs, avec leurs échéances).',
            'Chaque dette devient une dépense « à payer » marquée solde d\'ouverture : dans l\'échéancier et la prévision, payée comme une autre, mais hors budgets et hors dépenses du mois.',
            'L\'import est en tout ou rien : corrigez chaque ligne en erreur et renvoyez le fichier.',
        ];
    }

    public function analyser(array $lignes, ContexteImport $contexte): void
    {
        $fournisseurs = [];
        foreach ($this->em->createQuery('SELECT f.id, f.nom FROM '.Fournisseurs::class.' f')->getArrayResult() as $f) {
            $fournisseurs[Valeurs::cle((string) $f['nom'])] ??= $f;
        }
        foreach ($lignes as $ligne) {
            $nom = Valeurs::texte($ligne->valeur('fournisseur'), 100);
            $ligne->libelle = $nom ?? '';
            if (null === $nom) {
                $ligne->erreur('Le fournisseur est obligatoire.');
                continue;
            }
            $montant = self::lireMontant($ligne, 'montant', false);
            if (null === $montant) {
                continue;
            }
            if (0 === $montant) {
                $ligne->ignorer('Montant nul : rien à payer.');
                continue;
            }
            $echeance = self::lireDate($ligne, 'echeance');
            if (false === $echeance) {
                continue;
            }
            $fournisseur = $fournisseurs[Valeurs::cle($nom)] ?? null;
            if (null === $fournisseur) {
                $ligne->avertir('Fournisseur absent de la liste : son nom est gardé comme bénéficiaire.');
            } else {
                $ligne->libelle = (string) $fournisseur['nom'];
            }
            $objet = Valeurs::texte($ligne->valeur('objet'), 150) ?? 'Solde d\'ouverture';
            $ligne->statut = LigneImport::CREER;
            $ligne->detail = sprintf('%s à payer le %s · %s', Valeurs::francs($montant), ($echeance ?? $contexte->jourOuverture())->format('d/m/Y'), $objet);
            $ligne->donnees = ['fournisseur' => $fournisseur['id'] ?? null, 'nom' => $nom, 'montant' => $montant, 'echeance' => $echeance?->format('Y-m-d'), 'objet' => $objet];
        }
    }

    public function appliquer(array $lignes, ContexteImport $contexte, ImportDonnees $import): void
    {
        $jour = $contexte->jourOuverture();
        $enregistres = [];
        foreach ($lignes as $ligne) {
            if (LigneImport::CREER !== $ligne->statut) {
                self::noterAutres($import, $ligne);
                continue;
            }
            $d = $ligne->donnees;
            $fournisseur = null !== $d['fournisseur'] ? $this->em->find(Fournisseurs::class, $d['fournisseur']) : null;
            $echeance = null !== $d['echeance'] ? new \DateTimeImmutable($d['echeance']) : $jour;
            $depense = (new Depense($contexte->par, $contexte->maintenant))
                ->setObjet($d['objet'])
                // Matières : payée, elle règle le fournisseur (compte 401).
                ->setCategorie('matieres')
                ->setMontant((int) $d['montant'])
                ->setDateDepense($jour)
                ->setFournisseur($fournisseur)
                ->setBeneficiaire(null === $fournisseur ? $d['nom'] : null)
                ->setEcheance($echeance)
                ->setNote(sprintf('Solde d\'ouverture repris par l\'import n° %d (%s).', $import->getId(), $import->getNomFichier()))
                ->marquerSoldeOuverture();
            if (null !== $contexte->par) {
                $depense->approuver($contexte->par, $contexte->maintenant);
            }
            $this->em->persist($depense);
            $solde = (new SoldeOuverture(SoldeOuverture::FOURNISSEUR, (int) $d['montant'], $jour, $fournisseur?->getNom() ?? $d['nom'], $contexte->par, $contexte->maintenant))
                ->setDepense($depense)->setEcheance($echeance)->setNote($d['objet'])->setImport($import);
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
        $payees = [];
        foreach ($this->soldes($import) as $s) {
            $d = $s->getDepense();
            if (null !== $d && (Depense::A_PAYER !== $d->getStatut() || null !== $d->getMouvement())) {
                $payees[] = $s->getTiers();
            }
        }

        return [] !== $payees ? [sprintf('Des dettes ont déjà été payées : %s.', implode(', ', \array_slice($payees, 0, 5)))] : [];
    }

    public function annuler(ImportDonnees $import, ContexteImport $contexte): void
    {
        foreach ($this->soldes($import) as $s) {
            $d = $s->getDepense();
            $s->setDepense(null);
            if (null !== $d) {
                $this->em->remove($d);
            }
            $s->annuler($contexte->maintenant);
        }
        $this->em->flush();
    }
}
