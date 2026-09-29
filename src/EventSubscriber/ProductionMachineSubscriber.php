<?php

namespace App\EventSubscriber;

use App\Entity\Machines;
use App\Repository\MachinesRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

final class ProductionMachineSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MachinesRepository $machinesRepository,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                'verifierMachineProduction',
                10,
            ],
        ];
    }

    public function verifierMachineProduction(
        RequestEvent $event
    ): void {
        /*
         * On ne traite que la requête principale.
         */
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        /*
         * Toutes nos routes du module utilisent :
         * app_production_...
         */
        $route = (string) $request->attributes->get('_route');

        if (!str_starts_with($route, 'app_production_')) {
            return;
        }

        /*
         * Récupération de l'adresse IP du poste.
         */
        $adresseIp = $request->getClientIp();

        if ($adresseIp === null) {
            $event->setResponse(
                $this->creerReponseAccesRefuse(
                    'Adresse IP du poste impossible à déterminer.'
                )
            );

            return;
        }

        /*
         * Recherche de la machine correspondant à cette IP.
         */
        $machine = $this->machinesRepository
            ->findOneByAdresseIp($adresseIp);

        if (!$machine instanceof Machines) {
            $event->setResponse(
                $this->creerReponseAccesRefuse(
                    sprintf(
                        'Le poste utilisant l’adresse IP %s n’est pas autorisé à accéder à la production.',
                        $adresseIp
                    )
                )
            );

            return;
        }

        /*
         * Machine reconnue.
         *
         * On mémorise son ID en session.
         */
        if ($request->hasSession()) {
            $request->getSession()->set(
                'production_machine_id',
                $machine->getId()
            );

            $request->getSession()->set(
                'production_machine_ip',
                $adresseIp
            );
        }

        /*
         * Très intéressant :
         * on attache directement l'objet Machine à la requête.
         *
         * Les contrôleurs pourront ensuite le récupérer.
         */
        $request->attributes->set(
            '_production_machine',
            $machine
        );
    }

    private function creerReponseAccesRefuse(
        string $message
    ): Response {
        return new Response(
            sprintf(
                '<!DOCTYPE html>
                <html lang="fr">
                <head>
                    <meta charset="UTF-8">
                    <title>Accès production refusé</title>
                    <style>
                        body {
                            margin: 0;
                            background: #f5f6fa;
                            font-family: Arial, sans-serif;
                        }

                        .wrapper {
                            min-height: 100vh;
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            padding: 30px;
                        }

                        .card {
                            width: 100%%;
                            max-width: 600px;
                            padding: 40px;
                            border-radius: 10px;
                            background: #fff;
                            box-shadow: 0 10px 30px rgba(0,0,0,.08);
                            text-align: center;
                        }

                        h1 {
                            color: #dc3545;
                        }

                        .ip {
                            margin: 20px 0;
                            padding: 15px;
                            background: #f8f9fa;
                            border-radius: 6px;
                            font-family: monospace;
                            font-size: 18px;
                            font-weight: bold;
                        }
                    </style>
                </head>

                <body>
                    <div class="wrapper">
                        <div class="card">
                            <h1>Accès production refusé</h1>

                            <p>%s</p>

                            <p>
                                Veuillez contacter un administrateur
                                afin d’enregistrer ce poste dans
                                la liste des machines autorisées.
                            </p>
                        </div>
                    </div>
                </body>
                </html>',
                htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    'UTF-8'
                )
            ),
            Response::HTTP_FORBIDDEN
        );
    }
}