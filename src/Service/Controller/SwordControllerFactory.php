<?php
namespace ArchivematicaConnector\Service\Controller;

use ArchivematicaConnector\Controller\SwordController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SwordControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new SwordController(
            $container->get('Omeka\EntityManager'),
            $container->get('Omeka\AuthenticationService'),
            $container->get('ArchivematicaConnector\Sword\MetsImporter')
        );
    }
}
