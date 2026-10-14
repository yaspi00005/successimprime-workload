<?php

namespace App\Service\Import;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\ImportDonnees;
use App\Entity\ModificationClient;
use App\Entity\Paiements;
use App\Entity\SoldeOuverture;

/**
 * Soldes d'ouverture des clients (docs/import-donnees.md) : ce que chaque
 * client devait au démarrage, ou l'avance qu'il avait versée.
 *
 * - Il devait de l'argent : une commande « Solde d'ouverture », sans
 *   ligne ni facture, du montant dû. Elle compte dans son solde, les
 *   créances, le plafond de crédit et l'espace client (payeur seulement),
 *   se règle à la caisse comme une commande, et reste hors du chiffre
 *   d'affaires, des statistiques, de la fidélité, des promotions et des
 *   primes.
 * - Il avait une avance (montant négatif) : elle s'ajoute à son solde
 *   client (la monnaie gardée par la boutique), sans entrée d'argent.
 */
final class ImportSoldesClients extends ImportSoldes
{
    public function cle(): string
    {
        return 'soldes_clients';
    }

    public function libelle(): string
    {
        return 'Soldes clients';
    }

    public function description(): string
    {
        return 'Ce que chaque client devait au démarrage, ou l\'avance qu\'il avait versée. Les clients doivent déjà exister.';
    }

    public function droits(): array
    {
        return ['ROLE_ADMIN', 'ROLE_PAIEMENT_VOIR', 'ROLE_COMPTABILITE'];
    }

    public function colonnes(): array
    {
        return [
            ['cle' => 'code', 'libelle' => 'Code client', 'alias' => ['code', 'reference client', 'numero client'], 'obligatoire' => false,
                'exemples' => ['CLI-000123', ''], 'aide' => 'Le code de la fiche (CLI-000123). Sinon le téléphone, sinon le nom.'],
            ['cle' => 'telephone', 'libelle' => 'Téléphone', 'alias' => ['tel', 'portable', 'numero', 'mobile'], 'obligatoire' => false,
                'exemples' => ['', '76 12 34 56'], 'aide' => '8 chiffres : retrouve la fiche, ou son contact.'],
            ['cle' => 'nom', 'libelle' => 'Client', 'alias' => ['nom', 'nom du client', 'raison sociale'], 'obligatoire' => false,
                'exemples' => ['Hôtel du Fleuve SARL', 'Aminata Coulibaly'], 'aide' => 'Le nom de la fiche, si ni le code ni le téléphone ne sont donnés. Deux fiches du même nom : donnez le code.'],
            ['cle' => 'montant', 'libelle' => 'Montant dû', 'alias' => ['montant', 'solde', 'reste a payer', 'du', 'dette', 'creance'], 'obligatoire' => true,
                'exemples' => ['1 250 000', '-15 000'], 'aide' => 'En francs. Positif : le client doit cet argent. Négatif : c\'est une avance qu\'il a versée. 0 : la ligne est écartée.'],
            ['cle' => 'echeance', 'libelle' => 'Échéance', 'alias' => ['date limite', 'a payer le', 'date d echeance'], 'obligatoire' => false,
                'exemples' => ['30/11/2026', ''], 'aide' => 'Jour où le montant doit être payé (jj/mm/aaaa). Vide : le jour d\'ouverture.'],
            ['cle' => 'note', 'libelle' => 'Note', 'alias' => ['notes', 'observation', 'commentaire', 'detail', 'objet'], 'obligatoire' => false,
                'exemples' => ['Factures 2025 non réglées', ''], 'aide' => 'Note interne, jamais montrée au client.'],
        ];
    }

    public function notes(): array
    {
        return [
            'Une ligne par client. Le client doit déjà exister : importez d\'abord les clients.',
            'Positif : ce que le client doit. Il apparaît dans son solde, les créances et la caisse sous le nom « Solde d\'ouverture », et se règle comme une commande. Ce n\'est pas une vente : ni chiffre d\'affaires, ni statistiques, ni points de fidélité, ni facture.',
            'Négatif : une avance versée par le client. Elle s\'ajoute à son solde client, qu\'il utilise sur ses prochaines commandes.',
            'Aucun message n\'est envoyé aux clients, et ces montants n\'ont pas de rappel automatique.',
            'L\'import est en tout ou rien : corrigez chaque ligne en erreur et renvoyez le fichier.',
        ];
    }

    public function analyser(array $lignes, ContexteImport $contexte): void
    {
        $index = IndexClients::charger($this->em);
        $existants = [];
        foreach ($this->em->createQuery('SELECT IDENTITY(s.client) AS client FROM '.SoldeOuverture::class.' s WHERE s.annuleLe IS NULL AND s.type IN (:types)')
            ->setParameter('types', [SoldeOuverture::CLIENT, SoldeOuverture::AVANCE_CLIENT])->getArrayResult() as $s) {
            $existants[(int) $s['client']] = true;
        }
        $vus = [];

        foreach ($lignes as $ligne) {
            $ligne->libelle = $ligne->valeur('nom') ?: $ligne->valeur('code') ?: $ligne->valeur('telephone');
            if ('' === $ligne->valeur('code') && '' === $ligne->valeur('telephone') && '' === $ligne->valeur('nom')) {
                $ligne->erreur('Indiquez le code, le téléphone ou le nom du client.');
                continue;
            }
            $montant = self::lireMontant($ligne, 'montant', true);
            if (null === $montant) {
                continue;
            }
            $echeance = self::lireDate($ligne, 'echeance');
            if (false === $echeance) {
                continue;
            }
            $trouve = $index->chercher($ligne->valeur('code'), [$ligne->valeur('telephone')], $ligne->valeur('nom'));
            if (null === $trouve || ('' !== $ligne->valeur('code') && 'code' !== $trouve['par'])) {
                $ligne->erreur('' !== $ligne->valeur('code') ? sprintf('Aucun client n\'a le code %s.', $ligne->valeur('code')) : 'Client introuvable : importez-le d\'abord, ou donnez son code.');
                continue;
            }
            if ($trouve['ambigu']) {
                $ligne->erreur(sprintf('Plusieurs clients s\'appellent « %s » : donnez le code ou le téléphone.', $trouve['nom']));
                continue;
            }
            $ligne->libelle = trim($trouve['code'].' '.$trouve['nom']);
            if (isset($vus[$trouve['id']])) {
                $ligne->erreur(sprintf('Ce client est déjà à la ligne %d : une ligne par client.', $vus[$trouve['id']]));
                continue;
            }
            $vus[$trouve['id']] = $ligne->numero;
            if (isset($existants[$trouve['id']])) {
                $ligne->erreur('Ce client a déjà un solde d\'ouverture : annulez d\'abord l\'import qui l\'a créé.');
                continue;
            }
            if (0 === $montant) {
                $ligne->ignorer('Montant nul : rien à reprendre.');
                continue;
            }
            $client = $this->em->find(Clients::class, $trouve['id']);
            if (null === $client) {
                $ligne->erreur('Client introuvable.');
                continue;
            }
            $ligne->statut = LigneImport::CREER;
            if ($montant > 0) {
                $ligne->detail = sprintf('Doit %s · échéance %s', Valeurs::francs($montant), ($echeance ?? $contexte->jourOuverture())->format('d/m/Y'));
                if ((int) $client->getPlafondCredit() > 0 && $montant > (int) $client->getPlafondCredit()) {
                    $ligne->avertir(sprintf('Dépasse son plafond de crédit (%s) : ses prochaines commandes à crédit seront bloquées.', Valeurs::francs((int) $client->getPlafondCredit())));
                }
            } else {
                $ligne->detail = sprintf('Avance de %s ajoutée à son solde client', Valeurs::francs(-$montant));
                if (null !== $echeance) {
                    $ligne->avertir('Échéance ignorée pour une avance.');
                }
            }
            $ligne->donnees = ['client' => $trouve['id'], 'montant' => $montant, 'echeance' => $echeance?->format('Y-m-d'), 'note' => Valeurs::texte($ligne->valeur('note'), 1000)];
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
            $client = $this->em->find(Clients::class, $d['client']) ?? throw new \RuntimeException(sprintf('Ligne %d : client introuvable.', $ligne->numero));
            $echeance = null !== $d['echeance'] ? new \DateTimeImmutable($d['echeance']) : null;
            $montant = (int) $d['montant'];
            if ($montant > 0) {
                $solde = (new SoldeOuverture(SoldeOuverture::CLIENT, $montant, $jour, $client->getNomComplet(), $contexte->par, $contexte->maintenant))
                    ->setClient($client)->setEcheance($echeance)->setNote($d['note'])->setImport($import);
                $commande = Commandes::soldeOuverture($client, $montant, $jour, 'SO-'.$client->getCode(), self::observation($import, $d['note']), $echeance);
                $this->em->persist($commande);
                $solde->setCommande($commande);
            } else {
                $solde = (new SoldeOuverture(SoldeOuverture::AVANCE_CLIENT, -$montant, $jour, $client->getNomComplet(), $contexte->par, $contexte->maintenant))
                    ->setClient($client)->setNote($d['note'])->setImport($import);
                $avant = $client->getSoldeCredit();
                $client->setSoldeCredit($avant - $montant);
                $this->em->persist(new ModificationClient($client, 'Solde client', 'conditions', Valeurs::francs($avant), Valeurs::francs($client->getSoldeCredit()).' (solde d\'ouverture)', $contexte->maintenant, $contexte->par, 'import', $contexte->parNom));
            }
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
        $payes = [];
        $avances = [];
        foreach ($this->soldes($import) as $s) {
            $client = $s->getClient();
            if (SoldeOuverture::CLIENT === $s->getType() && null !== $s->getCommande()) {
                $references = $this->references->compter(Commandes::class, [(int) $s->getCommande()->getId()], [SoldeOuverture::class]);
                if ([] !== $references) {
                    $payes[] = $s->getTiers();
                }
            } elseif (SoldeOuverture::AVANCE_CLIENT === $s->getType() && null !== $client) {
                $utilise = (int) $this->em->createQuery('SELECT COUNT(p.id) FROM '.Paiements::class.' p JOIN p.commande c WHERE c.clients = :cl AND p.mode = :solde AND p.date >= :le')
                    ->setParameter('cl', $client)->setParameter('solde', Paiements::MODE_SOLDE_CLIENT)->setParameter('le', $import->getLe())
                    ->getSingleScalarResult();
                if ($utilise > 0 || $client->getSoldeCredit() < $s->getMontant()) {
                    $avances[] = $s->getTiers();
                }
            }
        }
        $blocages = [];
        if ([] !== $payes) {
            $blocages[] = sprintf('Des soldes ont déjà reçu un paiement ou une relance : %s.', self::quelques($payes));
        }
        if ([] !== $avances) {
            $blocages[] = sprintf('Des avances ont déjà servi : %s.', self::quelques($avances));
        }

        return $blocages;
    }

    public function annuler(ImportDonnees $import, ContexteImport $contexte): void
    {
        foreach ($this->soldes($import) as $s) {
            $client = $s->getClient();
            if (SoldeOuverture::CLIENT === $s->getType()) {
                $commande = $s->getCommande();
                $s->setCommande(null);
                if (null !== $commande) {
                    $this->em->remove($commande);
                }
            } elseif (null !== $client) {
                $avant = $client->getSoldeCredit();
                $client->setSoldeCredit($avant - $s->getMontant());
                $this->em->persist(new ModificationClient($client, 'Solde client', 'conditions', Valeurs::francs($avant), Valeurs::francs($client->getSoldeCredit()).' (import annulé)', $contexte->maintenant, $contexte->par, 'import', $contexte->parNom));
            }
            $s->annuler($contexte->maintenant);
        }
        $this->em->flush();
    }

    private static function observation(ImportDonnees $import, ?string $note): string
    {
        return trim('Solde d\'ouverture repris par l\'import n° '.$import->getId().' ('.$import->getNomFichier().').'.(null !== $note ? "\n".$note : ''));
    }

    /** @param list<string> $noms */
    private static function quelques(array $noms): string
    {
        return implode(', ', \array_slice($noms, 0, 5)).(\count($noms) > 5 ? sprintf(' et %d autres', \count($noms) - 5) : '');
    }
}
