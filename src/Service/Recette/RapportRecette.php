<?php

namespace App\Service\Recette;

/**
 * Écrit le bilan d'une recette des accès : un résumé en Markdown (comptes,
 * constats, contrôles) et la matrice complète compte × page en CSV.
 */
final class RapportRecette
{
    /**
     * @return list<string> fichiers écrits
     */
    public function ecrire(BilanRecette $bilan, string $dossier, string $instance, ?\DateTimeImmutable $le = null): array
    {
        $le ??= new \DateTimeImmutable();
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier impossible à créer : '.$dossier);
        }
        $base = $dossier.'/acces-'.$instance.'-'.$le->format('Y-m-d-His');
        file_put_contents($base.'.md', self::markdown($bilan, $instance, $le));
        $fichiers = [$base.'.md'];
        if ([] !== $bilan->matrice) {
            file_put_contents($base.'-matrice.csv', self::csv($bilan));
            $fichiers[] = $base.'-matrice.csv';
        }

        return $fichiers;
    }

    public static function markdown(BilanRecette $bilan, string $instance, \DateTimeImmutable $le): string
    {
        $n = $bilan->compterConstats();
        $md = ['# Recette des accès ('.('local' === $instance ? 'ERP local' : 'site en ligne').', '.$le->format('d/m/Y H:i').')', ''];
        $md[] = \sprintf('%d fuite(s), %d erreur(s), %d page(s) à examiner, %d remarque(s) ; %d contrôle(s), dont %d en échec.', $n[BilanRecette::FUITE], $n[BilanRecette::ERREUR], $n[BilanRecette::A_EXAMINER], $n[BilanRecette::INFO], \count($bilan->controles), \count(array_filter($bilan->controles, static fn (array $c): bool => !$c['ok'])));
        $md[] = '';

        if ([] !== $bilan->comptes) {
            $md[] = '## Comptes et pages';
            $md[] = '';
            $md[] = \sprintf('%d pages demandées par compte. Lettres de la matrice : O ouverte, R refusée, C page de connexion, > redirigée, I introuvable, E erreur.', \count($bilan->pages));
            $md[] = '';
            $md[] = '| Compte | Rôle | Ouvertes | Refusées | Connexion | Redirigées | Introuvables | Erreurs |';
            $md[] = '|---|---|---|---|---|---|---|---|';
            foreach ($bilan->resumeParCompte() as $compte => $c) {
                $md[] = \sprintf('| %s | %s | %d | %d | %d | %d | %d | %d |', $compte, $bilan->comptes[$compte]['libelle'], $c['ouvert'], $c['refuse'], $c['connexion'], $c['redirige'], $c['introuvable'], $c['erreur']);
            }
            $md[] = '';
            foreach ($bilan->comptes as $compte => $infos) {
                $md[] = '- **'.$compte.'** : '.implode(', ', $infos['droits']);
            }
            $md[] = '';
        }

        foreach ([BilanRecette::FUITE, BilanRecette::ERREUR, BilanRecette::A_EXAMINER, BilanRecette::INFO] as $gravite) {
            $liste = array_values(array_filter($bilan->constats, static fn (array $c): bool => $c['gravite'] === $gravite));
            if ([] === $liste) {
                continue;
            }
            $md[] = '## '.BilanRecette::GRAVITES[$gravite].' ('.\count($liste).')';
            $md[] = '';
            $md[] = '| Compte | Page | Route | Constat |';
            $md[] = '|---|---|---|---|';
            foreach ($liste as $c) {
                $md[] = \sprintf('| %s | `%s` | %s | %s |', $c['compte'], $c['chemin'], $c['route'], str_replace('|', '/', $c['detail']));
            }
            $md[] = '';
        }

        if ([] !== $bilan->controles) {
            $md[] = '## Contrôles';
            $md[] = '';
            foreach ($bilan->controles as $c) {
                $md[] = \sprintf('- %s %s%s', $c['ok'] ? 'OK' : '**ÉCHEC**', $c['controle'], '' !== $c['resultat'] ? ' : '.$c['resultat'] : '');
            }
            $md[] = '';
        }

        if ([] !== $bilan->ignorees) {
            $md[] = '## Pages non demandées ('.\count($bilan->ignorees).')';
            $md[] = '';
            foreach ($bilan->ignorees as $route => $raison) {
                $md[] = '- '.$route.' : '.$raison;
            }
            $md[] = '';
        }

        return implode("\n", $md);
    }

    public static function csv(BilanRecette $bilan): string
    {
        $comptes = array_keys($bilan->comptes);
        $flux = fopen('php://temp', 'r+');
        fputcsv($flux, ['route', 'adresse', 'onglet', ...$comptes], ';', '"', '');
        foreach ($bilan->matrice as $chemin => $cases) {
            $ligne = [$bilan->pages[$chemin]['route'] ?? '', $chemin, $bilan->pages[$chemin]['onglet'] ?? ''];
            foreach ($comptes as $compte) {
                $ligne[] = Reponse::LETTRES[$cases[$compte] ?? ''] ?? '-';
            }
            fputcsv($flux, $ligne, ';', '"', '');
        }
        rewind($flux);

        return (string) stream_get_contents($flux);
    }
}
