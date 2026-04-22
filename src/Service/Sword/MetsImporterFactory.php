<?php
namespace ArchivematicaConnector\Service\Sword;

use ArchivematicaConnector\Sword\MetsImporter;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MetsImporterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return new MetsImporter(
            $container->get('Omeka\ApiManager'),
            $container->get('Omeka\EntityManager'),
            $container->get('Omeka\File\TempFileFactory')
        );
    }
}
