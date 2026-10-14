<?php

namespace App\Service\Site;

/**
 * Textes provisoires du site vitrine (commande app:site:contenu-initial),
 * à relire et à remplacer par l'atelier dans Réglages › Site vitrine
 * (liste dans docs/site-vitrine.md).
 *
 * Règles suivies : aucun chiffre d'activité, aucune note, aucun nom de
 * client ; seulement ce que l'atelier affirme déjà (grand format jusqu'à
 * 3,20 m, BAT avant chaque impression, réimpression si le défaut vient
 * de l'atelier, paiement Orange Money, nom commercial de Madial Group).
 * Les réalisations et les conseils d'exemple portent « (démo) ».
 */
final class ContenuInitial
{
    /**
     * Pages du site : slug => titre, accroche, description, corps.
     *
     * @return array<string, array{titre: string, accroche: string, description: string, corps: string}>
     */
    public static function pages(): array
    {
        return [
            'equipements' => [
                'titre' => 'Nos équipements',
                'accroche' => 'Un atelier équipé pour le grand format, le textile et la finition.',
                'description' => 'L\'atelier Succès Imprim à Bamako : impression grand format jusqu\'à 3,20 m, roll-up et adhésifs, textile DTF, petit format et finitions.',
                'corps' => <<<'MD'
                    ### Impression grand format
                    *Grand format*
                    - Largeur jusqu'à 3,20 m
                    - Bâches, mesh, vinyle
                    - Banderoles et façades

                    ### Roll-up, vitrines et adhésifs
                    *Grand format*
                    - Roll-up et Door Shape
                    - Vinyle adhésif et vitrophanie
                    - Micro-perforé pour les vitrines

                    ### Impression textile DTF
                    *Textile*
                    - T-shirts, polos, tote bags
                    - Logos, noms et numéros
                    - Couleurs vives, toucher souple

                    ## Finitions en atelier
                    Œillets · Ourlets · Fourreaux · Découpe · Pose sur site
                    MD,
            ],
            'guide-fichiers' => [
                'titre' => 'Préparer votre fichier',
                'accroche' => 'Un bon fichier, c\'est une impression réussie du premier coup.',
                'description' => 'Formats acceptés, résolution, fonds perdus et couleurs : préparer un fichier prêt à imprimer chez Succès Imprim, et les gabarits à demander.',
                'corps' => <<<'MD'
                    ### Formats acceptés
                    PDF (recommandé), AI, PSD, JPG ou PNG en haute définition.

                    ### Résolution
                    Grand format : 100 à 150 dpi à taille réelle. Petit format : 300 dpi.

                    ### Fonds perdus
                    Petit format : 3 mm. Bâche : prévoir une marge de 5 cm pour les œillets et les ourlets.

                    ### Couleurs
                    Fichiers en CMJN, polices vectorisées, noir riche pour les aplats.

                    ## Avant d'envoyer votre fichier
                    - Le document est à l'échelle de l'impression.
                    - Les textes importants ne touchent pas le bord.
                    - Les photos sont nettes quand on les regarde à 100 %.

                    > Un doute ? Envoyez quand même votre fichier : nous le vérifions et vous montrons le BAT avant d'imprimer.

                    ## Nos gabarits
                    - Roll-up 85 × 200 cm — PDF · AI
                    - Carte de visite 85 × 55 mm — PDF · AI
                    - Flyer A5 — PDF · AI
                    - Door Shape 80 × 180 cm — PDF · AI
                    - Banderole (toutes tailles) — PDF
                    MD,
            ],
            'a-propos' => [
                'titre' => 'Qui sommes-nous ?',
                'accroche' => 'Succès Imprim, l\'imprimerie de Madial Group à Bamako.',
                'description' => 'Succès Imprim, imprimerie de Madial Group à Bamako : impression grand format, textile DTF et finition dans notre atelier.',
                'corps' => <<<'MD'
                    ## Nous imprimons vos succès, depuis notre atelier de Bamako
                    Succès Imprim accompagne commerçants, entreprises, ONG et institutions dans leur communication visuelle : banderoles, enseignes, roll-up, textile personnalisé et supports imprimés.

                    Notre atelier réunit l'impression grand format, le textile DTF et la finition, pour livrer vite et bien.

                    ### Qualité
                    BAT avant chaque impression

                    ### Délais
                    Annoncés dès la commande

                    ### Proximité
                    Réponse sur WhatsApp

                    ## L'équipe
                    ### Direction
                    Devis et suivi

                    ### Atelier
                    Impression et finition

                    ### PAO
                    Maquettes et BAT
                    MD,
            ],
            'nos-engagements' => [
                'titre' => 'Nos engagements',
                'accroche' => 'Ce que nous vous garantissons, par écrit.',
                'description' => 'BAT avant impression, réimpression si le défaut vient de nous, délai annoncé, prix sans surprise : les engagements de Succès Imprim à Bamako.',
                'corps' => <<<'MD'
                    ### BAT avant impression
                    Rien n'est imprimé sans votre accord écrit sur le bon à tirer.

                    ### Réimpression garantie
                    Défaut de notre fait ? Nous réimprimons, sans frais.

                    ### Délai annoncé
                    Le délai est indiqué à la commande et suivi dans votre espace client.

                    ### Prix sans surprise
                    Le prix est celui du devis : rien de caché à la livraison.

                    ## Comment se passe votre commande
                    1. Devis
                    2. Paiement de l'acompte
                    3. BAT validé
                    4. Impression
                    5. Contrôle qualité
                    6. Livraison ou retrait

                    ## Paiement
                    Vous pouvez payer par Orange Money ; une facture vous est remise pour chaque paiement.
                    MD,
            ],
            'faq' => [
                'titre' => 'Questions fréquentes',
                'accroche' => 'Vous ne trouvez pas votre réponse ? Écrivez-nous sur WhatsApp.',
                'description' => 'Délais, paiement Orange Money, fichiers et BAT, livraison et pose, produits : les réponses de Succès Imprim aux questions fréquentes.',
                'corps' => <<<'MD'
                    ## Commandes et délais
                    ### Quels sont vos délais ?
                    Le délai dépend du produit et de la quantité. Il est indiqué sur votre devis, puis suivi dans votre espace client.

                    ### Imprimez-vous à partir d'une seule pièce ?
                    Cela dépend du produit. Le devis indique la quantité minimale de chaque support.

                    ### Comment suivre ma commande ?
                    Depuis votre espace client : vous y voyez où en est chaque commande, du BAT à la livraison. Sans compte, avec le numéro de commande et votre téléphone : [Suivre ma commande](/suivre-ma-commande).

                    ## Paiement
                    ### Puis-je payer par Orange Money ?
                    Oui. Une facture vous est remise pour chaque paiement.

                    ### Faut-il verser un acompte ?
                    Un acompte peut être demandé avant l'impression ; il est indiqué sur votre devis.

                    ## Fichiers et BAT
                    ### Quels fichiers acceptez-vous ?
                    PDF de préférence, mais aussi AI, PSD, JPG ou PNG en haute définition. Voir [Préparer votre fichier](/guide-fichiers).

                    ### Qu'est-ce que le BAT ?
                    Le bon à tirer est l'aperçu final de votre impression. Rien n'est imprimé tant que vous ne l'avez pas validé.

                    ### Que se passe-t-il si mon fichier n'est pas bon ?
                    Nous vous prévenons avant d'imprimer. Notre équipe PAO peut corriger ou refaire le fichier ; c'est alors indiqué sur le devis.

                    ## Livraison et pose
                    ### Livrez-vous ?
                    Oui, à Bamako, ou retrait à l'atelier. Les conditions sont précisées sur le devis.

                    ### Posez-vous sur site ?
                    Pour certains travaux (bâches, vitrines, enseignes), une pose peut être proposée sur devis.

                    ## Produits
                    ### Quelle largeur en grand format ?
                    Jusqu'à 3,20 m de large.

                    ### Comment entretenir une bâche extérieure ?
                    À l'eau claire avec un savon doux, sans produit abrasif. Laissez-la sécher, puis rangez-la roulée plutôt que pliée.
                    MD,
            ],
        ];
    }

    /**
     * Réalisations d'exemple, sans client ni photo (l'illustration de la
     * famille s'affiche) : titre, famille de catégorie, description, âge en jours.
     *
     * @return list<array{titre: string, famille: string, description: string, jours: int}>
     */
    public static function realisations(): array
    {
        return [
            ['titre' => 'Habillage de vitrine · pharmacie (démo)', 'famille' => 'grand', 'description' => 'Vinyle micro-perforé sur toute la vitrine : le message se voit de la rue, la lumière passe toujours.', 'jours' => 9],
            ['titre' => 'T-shirts DTF · tournoi (démo)', 'famille' => 'textile', 'description' => 'Logo de l\'équipe devant, nom et numéro au dos, imprimés en DTF.', 'jours' => 21],
            ['titre' => 'Roll-up · salon professionnel (démo)', 'famille' => 'signaletique', 'description' => 'Roll-up 85 × 200 cm avec sa housse de transport, BAT validé avant impression.', 'jours' => 34],
            ['titre' => 'Banderole · festival (démo)', 'famille' => 'grand', 'description' => 'Bâche avec œillets tout autour, prête à tendre à l\'entrée du site.', 'jours' => 47],
            ['titre' => 'Cartes de visite · cabinet (démo)', 'famille' => 'petit', 'description' => 'Cartes recto verso, maquette préparée par notre équipe PAO.', 'jours' => 63],
            ['titre' => 'Bâche de façade · boutique (démo)', 'famille' => 'grand', 'description' => 'Bâche grand format avec ourlets et œillets, posée sur la façade.', 'jours' => 80],
        ];
    }

    /**
     * Conseils d'exemple : titre, rubrique, résumé, corps, âge en jours.
     *
     * @return list<array{titre: string, rubrique: string, resume: string, corps: string, jours: int}>
     */
    public static function conseils(): array
    {
        return [
            [
                'titre' => 'Bâche 440 g, 510 g ou mesh : comment choisir (démo)',
                'rubrique' => 'Grand format',
                'resume' => 'Durée d\'affichage, vent, intérieur ou extérieur : les repères pour choisir sa bâche.',
                'jours' => 3,
                'corps' => <<<'MD'
                    Le poids d'une bâche (en grammes par m²) dit surtout sa solidité. Trois questions suffisent pour choisir.

                    ## Combien de temps doit-elle rester en place ?
                    - **Quelques jours** (événement, salon) : une bâche légère suffit le plus souvent.
                    - **Plusieurs mois** (façade, chantier) : préférez une bâche plus épaisse, qui se déforme moins.

                    ## Y a-t-il du vent ?
                    Une grande bâche tendue en hauteur prend le vent comme une voile. La **bâche mesh**, ajourée, laisse passer l'air : elle convient aux façades et aux échafaudages.

                    ## Comment sera-t-elle fixée ?
                    Œillets, ourlets ou fourreaux : dites-le-nous dès le devis, la finition se prépare avec le fichier.

                    > Un doute ? Décrivez-nous l'endroit et la durée : nous vous conseillons la bâche adaptée sur le devis.
                    MD,
            ],
            [
                'titre' => 'Préparer son fichier pour l\'impression (démo)',
                'rubrique' => 'Fichiers et BAT',
                'resume' => 'Format, résolution, fonds perdus, couleurs : les quatre points à vérifier avant d\'envoyer un fichier.',
                'jours' => 12,
                'corps' => <<<'MD'
                    Un fichier bien préparé, c'est un BAT validé du premier coup.

                    1. **Le bon format** : le document est à l'échelle de l'impression.
                    2. **Une bonne résolution** : une photo floue à l'écran le sera aussi sur la bâche.
                    3. **Des fonds perdus** : le fond dépasse un peu du format, pour ne pas laisser de liseré blanc à la coupe.
                    4. **Des couleurs en CMJN** : les couleurs d'écran (RVB) changent à l'impression.

                    Tous les détails sont dans notre page [Préparer votre fichier](/guide-fichiers).
                    MD,
            ],
            [
                'titre' => 'DTF ou sérigraphie : que choisir ? (démo)',
                'rubrique' => 'Textile',
                'resume' => 'Nombre de pièces, nombre de couleurs, délai : ce qui fait pencher pour l\'une ou l\'autre technique.',
                'jours' => 20,
                'corps' => <<<'MD'
                    Le **DTF** imprime le visuel sur un film, transféré ensuite à chaud sur le tissu. La **sérigraphie** dépose l'encre à travers un écran, une couleur après l'autre.

                    ## Le DTF convient bien
                    - aux petites séries et aux pièces personnalisées (noms, numéros) ;
                    - aux visuels en couleurs, avec des dégradés ou des photos.

                    ## La sérigraphie convient bien
                    - aux très grandes séries d'un même visuel ;
                    - aux visuels de une ou deux couleurs.

                    Chez Succès Imprim, le textile est imprimé en DTF : demandez-nous un devis avec votre visuel et vos quantités.
                    MD,
            ],
            [
                'titre' => 'Roll-up : 5 erreurs à éviter (démo)',
                'rubrique' => 'Signalétique',
                'resume' => 'Texte trop bas, trop petit, trop long : ce qui rend un roll-up illisible, et comment l\'éviter.',
                'jours' => 35,
                'corps' => <<<'MD'
                    Un roll-up se lit de loin, en quelques secondes. Voici les erreurs les plus courantes.

                    1. **Le message en bas** : le bas du roll-up est caché par les tables et les visiteurs. Placez l'essentiel en haut.
                    2. **Trop de texte** : un titre, une phrase, un contact suffisent.
                    3. **Des lettres trop petites** : ce qui est lisible sur l'ordinateur ne l'est pas à trois mètres.
                    4. **Un logo trop pâle** : vérifiez le contraste entre le fond et le texte.
                    5. **Oublier les marges** : le bas du visuel entre dans le pied du roll-up.

                    Demandez-nous le gabarit avant de commencer votre maquette.
                    MD,
            ],
            [
                'titre' => 'Réussir sa vitrine en micro-perforé (démo)',
                'rubrique' => 'Vitrines',
                'resume' => 'Un visuel visible de la rue sans perdre la lumière du jour : ce qu\'il faut savoir sur le micro-perforé.',
                'jours' => 50,
                'corps' => <<<'MD'
                    Le **micro-perforé** est un adhésif percé de petits trous : de l'extérieur, on voit le visuel ; de l'intérieur, on voit la rue.

                    ## Pour un bon rendu
                    - Choisissez des couleurs franches et un texte court : les trous éclaircissent l'image.
                    - Évitez les détails fins et les petits caractères.
                    - Mesurez chaque vitre : la pose se fait vitre par vitre.

                    > La vitrine reste lumineuse, et votre message travaille pour vous toute la journée.
                    MD,
            ],
        ];
    }
}
