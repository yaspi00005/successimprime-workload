<?php

namespace App\Form;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Format;
use App\Entity\Machines;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Entity\Articles;
use App\Repository\ProduitConfigurationRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Doctrine\ORM\EntityManagerInterface;

class CommandesDetailsType extends AbstractType
{
    public function __construct(
        private readonly ProduitConfigurationRepository $configurationRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('modeConfiguration', ChoiceType::class, [
                'label' => 'Mode de configuration',
                'choices' => [
                    'Configuration automatique' => 'automatique',
                    'Configuration manuelle' => 'manuel',
                    'Saisie libre' => 'libre',
                ],
                'expanded' => true,
                /*
                 * Le champ n'est plus affiche a l'utilisateur (voir
                 * _form.html.twig, .js-zone-mode-configuration) et
                 * CommandesController force de toute facon la valeur
                 * a "manuel" a l'enregistrement. Le laisser "required"
                 * faisait planter la validation HTML5 du navigateur :
                 * un champ requis mais cache (display:none) ne peut
                 * pas recevoir le focus, ce qui bloquait
                 * silencieusement la soumission du formulaire
                 * ("An invalid form control ... is not focusable").
                 */
                'required' => false,
                /*
                 * Aucun radio n'est plus jamais coche (le groupe est
                 * cache) : "empty_data" est donc systematiquement
                 * utilise. Il doit valoir "manuel" (le seul mode
                 * desormais utilise pour une ligne Produit), sinon le
                 * validateur d'entite (CommandesDetails::validerModeCommande,
                 * mode automatique) exige une produitConfiguration qui
                 * n'existe plus dans ce formulaire et bloque
                 * l'enregistrement ("Une configuration est obligatoire
                 * en mode automatique.").
                 */
                'empty_data' => 'manuel',
                'attr' => [
                    'class' => 'js-mode-configuration',
                    'data-detail-field' => 'modeConfiguration',
                ],
            ])

            ->add('produit', EntityType::class, [
                'class' => Produits::class,
                'query_builder' => static function (
                    \Doctrine\ORM\EntityRepository $repository
                ) {
                    return $repository->createQueryBuilder('p')
                        ->andWhere('p.actif = true')
                        ->andWhere('p.publie = true')
                        ->orderBy('p.ordre', 'ASC')
                        ->addOrderBy('p.nom', 'ASC');
                },
                'choice_label' => 'nom',
                'label' => 'Produit',
                'placeholder' => 'Sélectionnez un produit',
                'required' => false,
                'attr' => [
                    'class' => 'form-select js-select-search js-produit',
                    'data-placeholder' => 'Rechercher un produit...',
                    'data-detail-field' => 'produit',
                ],

            ])

            ->add('produitConfiguration', EntityType::class, [
                'class' => ProduitConfiguration::class,
                'choice_attr' => static function (
                    ProduitConfiguration $configuration
                ): array {
                    return [
                        'data-produit-id'
                        => (string) ($configuration->getProduit()?->getId() ?? ''),
                        'data-type-impression-id'
                        => (string) ($configuration->getTypeImpression()?->getId() ?? ''),
                        'data-support-id'
                        => (string) ($configuration->getSupport()?->getId() ?? ''),
                        'data-format-id'
                        => (string) ($configuration->getFormat()?->getId() ?? ''),
                        'data-mode-dimension'
                        => $configuration->getModeDimension(),
                        'data-mode-calcul'
                        => $configuration->getModeCalcul(),
                        'data-largeur'
                        => $configuration->getLargeurDefaut() ?? '',
                        'data-longueur'
                        => $configuration->getLongueurDefaut() ?? '',
                        'data-surface'
                        => $configuration->getSurfaceDefaut() ?? '',
                        'data-prix-base'
                        => (string) ($configuration->getPrixBase() ?? 0),
                        'data-prix-b2b'
                        => (string) ($configuration->getPrixB2B() ?? 0),
                        'data-quantite-min'
                        => (string) $configuration->getQuantiteMinimale(),
                        'data-quantite-max'
                        => (string) ($configuration->getQuantiteMaximale() ?? ''),
                    ];
                },
                'label' => 'Configuration',
                'placeholder' => 'Sélectionnez une configuration',
                'required' => false,
                'attr' => [
                    'class' => 'form-select js-select-search '
                        . 'js-produit-configuration',
                    'data-placeholder'
                    => 'Rechercher une configuration...',
                    'data-detail-field' => 'configuration',
                ],
            ])

            ->add('typeImpression', EntityType::class, [
                'class' => TypesImpression::class,
                'choice_label' => 'nom',
                'label' => 'Type d’impression',
              //  'placeholder' => 'Sélectionnez un type d’impression',
                'required' => false,
                'attr' => [
                    'class' => 'form-select js-select-search '
                        . 'js-type-impression',
                    'data-detail-field' => 'typeImpression',
                ],
            ])

            ->add('support', EntityType::class, [
                'class' => Supports::class,
                'choice_label' => 'nom',
                'label' => 'Support',
                'placeholder' => 'Sélectionnez un support',
                'required' => false,
                'attr' => [
                    'class' => 'form-select js-select-search js-support',
                    'data-detail-field' => 'support',
                ],
            ])

            ->add('format', EntityType::class, [
                'class' => Format::class,
                'choice_label' => 'nom',
                'label' => 'Format',
                'placeholder' => 'Sélectionnez un format',
                'required' => false,
                'attr' => [
                    'class' => 'form-select js-select-search js-format',
                    'data-detail-field' => 'format',
                ],
            ])

          

            ->add('designation', TextType::class, [
                'label' => 'Désignation',
                'required' => true,
                'attr' => [
                    'class' => 'form-control js-designation',
                    'placeholder' => 'Description du travail',
                    'data-detail-field' => 'designation',
                ],
            ])

            ->add('largeur', NumberType::class, [
                'label' => 'Largeur (cm)',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control js-largeur js-calcul-detail',
                    'min' => 0,
                    'step' => '0.01',
                    'data-detail-field' => 'largeur',
                ],
                'constraints' => [
                    new GreaterThanOrEqual(0),
                ],
            ])

            ->add('longueur', NumberType::class, [
                'label' => 'Longueur (cm)',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control js-longueur js-calcul-detail',
                    'min' => 0,
                    'step' => '0.01',
                    'data-detail-field' => 'longueur',
                ],
                'constraints' => [
                    new GreaterThanOrEqual(0),
                ],
            ])

            /*
             * Le champ existe dans l’entité :
             * il doit donc rester mappé.
             */
            ->add('surface', NumberType::class, [
                'label' => 'Surface (m²)',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'attr' => [
                    'class' => 'form-control js-surface',
                    'readonly' => true,
                    'min' => 0,
                    'step' => '0.0001',
                    'data-detail-field' => 'surface',
                ],
                'constraints' => [
                    new GreaterThanOrEqual(0),
                ],
            ])

            /*
             * Une seule déclaration de quantité.
             */
            ->add('quantite', IntegerType::class, [
                'label' => 'Quantité',
                'required' => true,
                'empty_data' => '1',
                'attr' => [
                    'class' => 'form-control js-quantite '
                        . 'js-calcul-detail',
                    'min' => 1,
                    'step' => 1,
                    'data-detail-field' => 'quantite',
                ],
                'constraints' => [
                    new Positive(
                        message: 'La quantité doit être supérieure à zéro.'
                    ),
                ],
            ])

            /*
             * L’ancien champ "prix" est supprimé.
             * Le seul prix utilisé est prixUnitaire.
             */
            ->add('prixUnitaire', NumberType::class, [
                'label' => 'Prix unitaire',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-prix-unitaire '
                        . 'js-calcul-detail',
                    'min' => 0,
                    'step' => '0.0001',
                    'data-detail-field' => 'prixUnitaire',
                ],
                'constraints' => [
                    new GreaterThanOrEqual(
                        value: 0,
                        message: 'Le prix ne peut pas être négatif.'
                    ),
                ],
            ])
            ->add('modeCalcul', ChoiceType::class, [
                'label' => 'Mode de calcul',
                'required' => true,
                'choices' => [
                    'Forfait' => 'forfait',
                    'Unité' => 'unite',
                    'Heure' => 'heure',
                    'Feuille' => 'feuille',
                    'Exemplaire' => 'exemplaire',
                    'Mètre linéaire' => 'metre',
                    'Mètre carré' => 'metre_carre',
                    'Point' => 'point',
                    'Face' => 'face',
                ],
                'placeholder' => false,
                'attr' => [
                    'class' => 'form-select js-mode-calcul js-calcul-detail',
                    'data-detail-field' => 'modeCalcul',
                ],
            ])
            ->add('coutRevient', IntegerType::class, [
                'label' => 'Coût de revient',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-cout-revient',
                    'min' => 0,
                    'step' => 1,
                    'data-detail-field' => 'coutRevient',
                ],
                'constraints' => [
                    new GreaterThanOrEqual(0),
                ],
            ])

            /*
             * Remise en FCFA par unité facturable (par m², par mètre
             * linéaire, par exemplaire... selon le mode de calcul),
             * pas un montant fixe sur toute la ligne ni un
             * pourcentage : plus simple à saisir précisément.
             */
            ->add('remise', NumberType::class, [
                'label' => 'Remise (FCFA / unité)',
                'help' => 'Montant par unité (par m², par mètre '
                    . 'linéaire, par exemplaire... selon le mode de '
                    . 'calcul), pas sur toute la ligne.',
                'required' => false,
                'empty_data' => '0',
                'scale' => 0,
                'html5' => true,
                'attr' => [
                    'class' => 'form-control js-remise-detail '
                        . 'js-calcul-detail',
                    'min' => 0,
                    'step' => 1,
                    'data-detail-field' => 'remise',
                ],
                'constraints' => [
                    new PositiveOrZero(),
                ],
            ])

            ->add('tva', IntegerType::class, [
                'label' => 'TVA (%)',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-tva-detail '
                        . 'js-calcul-detail',
                    'min' => 0,
                    'step' => 1,
                    'data-detail-field' => 'tva',
                ],
                'constraints' => [
                    new GreaterThanOrEqual(0),
                ],
            ])

            /*
             * Ces champs existent dans l’entité.
             * Ils sont readonly, mais pas disabled et pas mapped=false.
             */
            ->add('totalHt', IntegerType::class, [
                'label' => 'Total HT',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-total-ht',
                    'readonly' => true,
                    'min' => 0,
                    'data-detail-field' => 'totalHt',
                ],
            ])

            ->add('totalTtc', IntegerType::class, [
                'label' => 'Total TTC',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-total-ttc',
                    'readonly' => true,
                    'min' => 0,
                    'data-detail-field' => 'totalTtc',
                ],
            ])

            ->add('profilCouleurs', TextType::class, [
                'label' => 'Profil de couleurs',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex. CMJN',
                ],
            ])

            ->add('resolution', TextType::class, [
                'label' => 'Résolution',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex. 300 DPI',
                ],
            ])

            ->add('grammage', TextType::class, [
                'label' => 'Grammage',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex. 135 g/m²',
                ],
            ])

            ->add('epaisseur', TextType::class, [
                'label' => 'Épaisseur',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex. 3 mm',
                ],
            ])

            ->add('rectoVerso', CheckboxType::class, [
                'label' => 'Recto verso',
                'required' => false,
            ])

            ->add('nombreFaces', IntegerType::class, [
                'label' => 'Nombre de faces',
                'required' => false,
                'empty_data' => '1',
                'attr' => [
                    'class' => 'form-control js-calcul-detail',
                    'min' => 1,
                    'step' => 1,
                    'data-detail-field' => 'nombreFaces',
                ],
                'constraints' => [
                    new Positive(),
                ],
            ])

            ->add('laminage', TextType::class, [
                'label' => 'Laminage',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            ->add('oeillets', CheckboxType::class, [
                'label' => 'Œillets',
                'required' => false,
            ])

            ->add('decoupe', CheckboxType::class, [
                'label' => 'Découpe',
                'required' => false,
            ])

            ->add('pliage', TextType::class, [
                'label' => 'Pliage',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            ->add('emballage', CheckboxType::class, [
                'label' => 'Emballage',
                'required' => false,
            ])

            ->add('batValide', CheckboxType::class, [
                'label' => 'BAT validé',
                'required' => false,
            ])

            ->add('etat', CheckboxType::class, [
                'label' => 'Ligne active',
                'required' => false,
            ])
            ->add('article', EntityType::class, [
    'class' => Articles::class,

    'choice_label' => static function (
        Articles $article
    ): string {
        return sprintf(
            '%s — %s',
            $article->getReference(),
            $article->getDesignation()
        );
    },

    'query_builder' => static function (
        EntityRepository $repository
    ) {
        return $repository
            ->createQueryBuilder('a')
            ->andWhere('a.actif = :actif')
            ->andWhere('a.vendable = :vendable')
            ->setParameter('actif', true)
            ->setParameter('vendable', true)
            ->orderBy('a.designation', 'ASC');
    },

    'placeholder' =>
        'Rechercher un article en stock...',

    'required' => false,

    'attr' => [
        'class' =>
            'form-control js-select-search js-article-stock',

        'data-detail-field' =>
            'article',

        'data-placeholder' =>
            'Référence ou désignation...',
    ],
])
->add('typeLigne', ChoiceType::class, [
    'label' => 'Type de ligne',
    'choices' => [
        'Produit / prestation' =>
            CommandesDetails::TYPE_PRODUIT,

        'Article en stock' =>
            CommandesDetails::TYPE_ARTICLE,

        'Saisie libre' =>
            CommandesDetails::TYPE_LIBRE,
    ],
    'expanded' => true,
    'multiple' => false,
    'required' => true,
    'attr' => [
        'class' => 'js-type-ligne',
    ],
])
            ->add('priorite', ChoiceType::class, [
                'label' => 'Priorité',
                'required' => true,
                'placeholder' => false,
                'choices' => [
                    'Basse' => 'basse',
                    'Normale' => 'normale',
                    'Haute' => 'haute',
                    'Urgente' => 'urgente',
                ],
                'attr' => [
                    'class' => 'form-select',
                ],
            ])

            ->add('tempsEstime', IntegerType::class, [
                'label' => 'Temps estimé',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'step' => 1,
                ],
                'constraints' => [
                    new GreaterThanOrEqual(0),
                ],
            ])

            ->add('tempsReel', IntegerType::class, [
                'label' => 'Temps réel',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'step' => 1,
                ],
                'constraints' => [
                    new GreaterThanOrEqual(0),
                ],
            ])

           ->add('jetonsFichiers', HiddenType::class, [
    'mapped' => true,
    'required' => false,
    'attr' => [
        'class' => 'js-jetons-fichiers',
    ],
])
            ->add('finitions', CollectionType::class, [
                'entry_type' => CommandeDetailFinitionType::class,
                'label' => false,
                'required' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__finition_index__',
                'entry_options' => [
                    'label' => false,
                ],
            ])
            ->add('finitionsSelectionnees', EntityType::class, [
                'class' => ProduitConfigurationFinition::class,
                'choice_label' => function (
                    ProduitConfigurationFinition $configurationFinition
                ): string {
                    $nom = $configurationFinition
                        ->getFinition()
                        ?->getNom() ?? 'Finition';

                    $prix = $configurationFinition->getPrix();

                    return sprintf(
                        '%s — %s FCFA',
                        $nom,
                        number_format($prix, 0, ',', ' ')
                    );
                },
                'choices' => [],
                'multiple' => true,
                'expanded' => true,
                'mapped' => false,
                'required' => false,
            ])
            ->add('observation', TextareaType::class, [
                'label' => 'Observation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Informations complémentaires...',
                ],
            ])
            ->add('prePresseNecessaire',
    CheckboxType::class,
    [
        'required' => false,
        'label' => 'Passage en prépresse',
    ]
)

->add('productionNecessaire',
    CheckboxType::class,
    [
        'required' => false,
        'label' => 'Passage en production',
    ]
)
            ->addEventListener(
    FormEvents::PRE_SUBMIT,
    function (FormEvent $event): void {
        $data = $event->getData();

        if (!is_array($data)) {
            return;
        }

        /*
         * Repli sur "manuel" (pas "automatique") : le champ
         * modeConfiguration est desormais cache a l'utilisateur, le
         * groupe de radios ne soumet donc plus jamais aucune cle
         * pour ce champ -- $data['modeConfiguration'] est toujours
         * absent ici.
         */
        $mode = $data['modeConfiguration'] ?? 'manuel';

        /*
         * En mode automatique, on ne doit pas envoyer
         * les lignes de finitions manuelles.
         */
        if ($mode === 'automatique') {
            $data['finitions'] = [];
        } else {
            /*
             * En modes manuel et libre, on retire toute ligne
             * pour laquelle aucune finition n’a été choisie.
             */
            $finitions = $data['finitions'] ?? [];

            if (is_array($finitions)) {
                $data['finitions'] = array_filter(
                    $finitions,
                    static function ($ligne): bool {
                        if (!is_array($ligne)) {
                            return false;
                        }

                        $finitionId = $ligne['finition'] ?? null;

                        return $finitionId !== null
                            && $finitionId !== '';
                    }
                );

                $data['finitions'] = array_values(
                    $data['finitions']
                );
            }

            /*
             * Les cases de la configuration automatique
             * ne doivent pas être traitées ici.
             */
            $data['finitionsSelectionnees'] = [];
        }

        $event->setData($data);
    }
);

        /*
         * ============================================================
         * SAISIE EN CENTIMÈTRES
         * ============================================================
         *
         * En base, largeur/longueur restent stockées en mètres (pour
         * rester compatibles avec les dimensions du catalogue et les
         * calculs au mètre/mètre carré). Pour l’agent, il est bien
         * plus naturel de saisir des dimensions en centimètres :
         * ce transformer convertit uniquement à l’affichage/la saisie.
         */
        $transformerDimension = new CallbackTransformer(
            function ($valeurMetres) {
                return $valeurMetres === null || $valeurMetres === ''
                    ? null
                    : (float) $valeurMetres * 100;
            },
            function ($valeurCm) {
                if ($valeurCm === null || $valeurCm === '') {
                    return null;
                }

                return (float) str_replace(',', '.', (string) $valeurCm) / 100;
            }
        );

        $builder->get('largeur')->addModelTransformer($transformerDimension);
        $builder->get('longueur')->addModelTransformer($transformerDimension);

        /*
         * Chargement initial :
         * récupère les finitions déjà sélectionnées.
         */
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event): void {
                $detail = $event->getData();
                $configuration = null;
                $selectionnees = [];

                if ($detail instanceof CommandesDetails) {
                    $configuration = $detail->getProduitConfiguration();

                    foreach ($detail->getFinitions() as $finitionCommande) {
                        $configurationFinition = $finitionCommande
                            ->getConfigurationFinition();

                        if ($configurationFinition !== null) {
                            $selectionnees[] = $configurationFinition;
                        }
                    }
                }

                $this->ajouterChampFinitions(
                    $event->getForm(),
                    $configuration,
                    $selectionnees
                );
            }
        );

        /*
         * Recharge les finitions autorisées pour la configuration
         * envoyée pendant la soumission.
         */
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event): void {
                $donnees = $event->getData();

                if (!is_array($donnees)) {
                    return;
                }

                /*
                 * Repli sur "manuel" (pas "automatique") : le champ
                 * modeConfiguration est desormais cache a
                 * l'utilisateur, la cle est donc toujours absente
                 * des donnees soumises. Avec un repli sur
                 * "automatique", cette ligne finissait toujours en
                 * mode automatique sans configuration catalogue
                 * choisie, ce que le validateur d'entite refusait
                 * ("Une configuration est obligatoire en mode
                 * automatique.") -- avant meme que le controleur
                 * n'ait la main pour forcer "manuel".
                 */
                $mode = strtolower(trim(
                    (string) ($donnees['modeConfiguration'] ?? 'manuel')
                ));

                if (!in_array($mode, ['automatique', 'manuel', 'libre'], true)) {
                    $mode = 'manuel';
                }

                $donnees['modeConfiguration'] = $mode;
                $configuration = null;

                /*
         * MODE AUTOMATIQUE
         */
                if ($mode === 'automatique') {
                    $configurationId =
                        $donnees['produitConfiguration'] ?? null;

                    if (
                        $configurationId !== null
                        && $configurationId !== ''
                        && ctype_digit((string) $configurationId)
                    ) {
                        $configuration = $this
                            ->configurationRepository
                            ->find((int) $configurationId);
                    }

                    if ($configuration !== null) {
                        $donnees['produit'] = (string) (
                            $configuration->getProduit()?->getId() ?? ''
                        );

                        $donnees['typeImpression'] = (string) (
                            $configuration->getTypeImpression()?->getId() ?? ''
                        );

                        $donnees['support'] = (string) (
                            $configuration->getSupport()?->getId() ?? ''
                        );

                        $donnees['format'] = (string) (
                            $configuration->getFormat()?->getId() ?? ''
                        );

                        $donnees['prixUnitaire'] = (string) (
                            $configuration->getPrixBase() ?? 0
                        );

                        $donnees['modeCalcul'] =
                            $configuration->getModeCalcul();

                        if (
                            !isset($donnees['designation'])
                            || trim((string) $donnees['designation']) === ''
                        ) {
                            $donnees['designation'] =
                                $configuration->getProduit()?->getNom() ?? '';
                        }

                        if ($configuration->utiliseFormatFixe()) {
                            $donnees['largeur'] = '';
                            $donnees['longueur'] = '';
                            $donnees['surface'] = '';
                        }

                        if ($configuration->utiliseDimensions()) {
                            $donnees['largeur'] =
                                $configuration->getLargeurDefaut() ?? '';

                            $donnees['longueur'] =
                                $configuration->getLongueurDefaut() ?? '';

                            $donnees['surface'] =
                                $configuration->getSurfaceDefaut() ?? '';
                        }

                        if ($configuration->estSansDimensions()) {
                            $donnees['format'] = '';
                            $donnees['largeur'] = '';
                            $donnees['longueur'] = '';
                            $donnees['surface'] = '';
                        }

                        $quantite = (int) ($donnees['quantite'] ?? 0);

                        if ($quantite <= 0) {
                            $donnees['quantite'] = (string) (
                                $configuration->getQuantiteMinimale()
                            );
                        }
                    }
                }

                /*
         * MODE MANUEL
         *
         * Le produit reste obligatoire, mais aucune configuration
         * catalogue ne doit être enregistrée.
         */
                if ($mode === 'manuel') {
                    $donnees['produitConfiguration'] = '';
                }

                /*
         * MODE LIBRE
         *
         * Seuls la désignation, la quantité, le prix et le mode
         * de calcul sont nécessaires.
         */
                if ($mode === 'libre') {
                    $donnees['produit'] = '';
                    $donnees['produitConfiguration'] = '';
                    $donnees['typeImpression'] = '';
                    $donnees['support'] = '';
                    $donnees['format'] = '';
                    ;
                }

                $event->setData($donnees);

                $this->ajouterChampFinitions(
                    $event->getForm(),
                    $configuration
                );
            }
        );
        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event): void {
                $form = $event->getForm();
                $detail = $event->getData();

                if (
                    !$detail instanceof CommandesDetails
                    || !$form->isSubmitted()
                ) {
                    return;
                }

                /*
         * Les lignes "article" et "libre" n'ont pas de
         * configuration produit : modeConfiguration retombe
         * sur "automatique" par défaut quand aucune valeur
         * n'est soumise (radios masquées), il ne faut donc
         * jamais se fier à modeConfiguration seul ici.
         */
                if ($detail->getTypeLigne() !== CommandesDetails::TYPE_PRODUIT) {
                    $detail->setProduitConfiguration(null);

                    return;
                }

                /*
         * Les lignes "article" et "libre" n'ont pas de
         * configuration produit : modeConfiguration retombe
         * sur "automatique" par défaut quand aucune valeur
         * n'est soumise (radios masquées), il ne faut donc
         * jamais se fier à modeConfiguration seul ici.
         */
                if ($detail->getTypeLigne() !== CommandesDetails::TYPE_PRODUIT) {
                    $detail->setProduitConfiguration(null);

                    return;
                }

                /*
         * Seul le mode automatique exige et applique
         * ProduitConfiguration.
         */
                if ($detail->isConfigurationAutomatique()) {
                    if ($detail->getProduitConfiguration() === null) {
                        $form
                            ->get('produitConfiguration')
                            ->addError(
                                new FormError(
                                    'Veuillez sélectionner une configuration.'
                                )
                            );

                        return;
                    }

                    try {
                        $detail->appliquerConfiguration(false);
                    } catch (\DomainException $exception) {
                        $form->addError(
                            new FormError($exception->getMessage())
                        );
                    }

                    return;
                }

                /*
         * Sécurité supplémentaire pour les modes manuel et libre.
         */
                $detail->setProduitConfiguration(null);

                if ($detail->isSaisieLibre()) {
                    $detail
                        ->setProduit(null)
                        ->setTypeImpression(null)
                        ->setSupport(null)
                        ->setFormat(null)
                        ;
                }
            }
        );
        /*
         * La commande n’apparaît que lorsque ce formulaire
         * est utilisé seul.
         */
        if (!$options['embedded']) {
            $builder->add('commande', EntityType::class, [
                'class' => Commandes::class,
                'choice_label' => static function (
                    Commandes $commande
                ): string {
                    return $commande->getNumero()
                        ?? sprintf(
                            'Commande #%d',
                            $commande->getId()
                        );
                },
                'label' => 'Commande',
                'placeholder' => 'Sélectionnez une commande',
                'required' => true,
            ]);
        }
    }

    private function ajouterChampFinitions(
        FormInterface $form,
        ?ProduitConfiguration $configuration,
        ?array $selectionnees = null
    ): void {
        $choix = [];

        if ($configuration !== null) {
            foreach (
                $configuration->getConfigurationFinitions()
                as $configurationFinition
            ) {
                if ($configurationFinition->isActive()) {
                    $choix[] = $configurationFinition;
                }
            }
        }

        usort(
            $choix,
            static function (
                ProduitConfigurationFinition $a,
                ProduitConfigurationFinition $b
            ): int {
                return $a->getOrdre() <=> $b->getOrdre();
            }
        );

        /*
         * À la création, présélectionne les finitions obligatoires
         * et celles définies par défaut.
         */
        if ($selectionnees !== null && $selectionnees === []) {
            foreach ($choix as $configurationFinition) {
                if (
                    $configurationFinition->isObligatoire()
                    || $configurationFinition->isSelectionneeParDefaut()
                ) {
                    $selectionnees[] = $configurationFinition;
                }
            }
        }

        $options = [
            'class' => ProduitConfigurationFinition::class,
            'choices' => $choix,
            'choice_label' => static function (
                ProduitConfigurationFinition $configurationFinition
            ): string {
                $nom = $configurationFinition
                    ->getFinition()
                    ?->getNom() ?? 'Finition';

                if (!$configurationFinition->isPayante()) {
                    return sprintf('%s — incluse', $nom);
                }

                return sprintf(
                    '%s — %s FCFA / %s',
                    $nom,
                    number_format(
                        $configurationFinition->getPrix() ?? 0,
                        0,
                        ',',
                        ' '
                    ),
                    $configurationFinition->getModeCalcul()
                );
            },
            'choice_attr' => static function (
                ProduitConfigurationFinition $configurationFinition
            ): array {
                return [
                    'data-prix'
                    => (string) ($configurationFinition->getPrix() ?? 0),
                    'data-mode-calcul'
                    => $configurationFinition->getModeCalcul(),
                    'data-obligatoire'
                    => $configurationFinition->isObligatoire()
                        ? '1'
                        : '0',
                    'data-payante'
                    => $configurationFinition->isPayante()
                        ? '1'
                        : '0',
                    'class' => 'js-finition-selectionnee',
                ];
            },
            'multiple' => true,
            'expanded' => true,
            'mapped' => false,
            'required' => false,
            'label' => 'Finitions',
            'attr' => [
                'class' => 'js-finitions-container',
            ],
        ];

        /*
         * Ne pas définir data pendant PRE_SUBMIT :
         * cela écraserait les choix réellement envoyés.
         */
        if ($selectionnees !== null) {
            $options['data'] = $selectionnees;
        }

        $form->add(
            'finitionsSelectionnees',
            EntityType::class,
            $options
        );
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => CommandesDetails::class,
            'embedded' => false,
        ]);

        $resolver->setAllowedTypes('embedded', 'bool');
    }
    private function ajouterFinitionsSelectionnees(
        FormInterface $form,
        iterable $choix
    ): void {
        $form->add(
            'finitionsSelectionnees',
            EntityType::class,
            [
                'class' =>
                ProduitConfigurationFinition::class,

                'choice_label' => function (
                    ProduitConfigurationFinition $configurationFinition
                ): string {
                    $nom = $configurationFinition
                        ->getFinition()
                        ?->getNom() ?? 'Finition';

                    return sprintf(
                        '%s — %s FCFA',
                        $nom,
                        number_format(
                            $configurationFinition->getPrix(),
                            0,
                            ',',
                            ' '
                        )
                    );
                },

                'choices' => $choix,
                'choice_value' => 'id',
                'multiple' => true,
                'expanded' => true,
                'mapped' => false,
                'required' => false,
            ]
        );
    }
}
