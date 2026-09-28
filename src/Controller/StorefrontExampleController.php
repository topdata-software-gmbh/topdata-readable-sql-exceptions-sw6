<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class StorefrontExampleController extends StorefrontController
{
    #[Route(
        path: '/readablesqlexceptionssw6/example', 
        name: 'frontend.readablesqlexceptionssw6.example', 
        methods: ['GET']
    )]
    public function exampleAction(): Response
    {
        return $this->renderStorefront('@TopdataReadableSqlExceptionsSW6/storefront/example.html.twig', [
            'pluginName' => 'ReadableSqlExceptionsSW6'
        ]);
    }
}