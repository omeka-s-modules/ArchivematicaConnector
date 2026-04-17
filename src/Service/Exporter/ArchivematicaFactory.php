<?php
namespace ArchivematicaConnector\Service\Exporter;

use ArchivematicaConnector\Exporter\Archivematica;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ArchivematicaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Archivematica(
            $services->get('Omeka\ApiManager')
        );
    }
}
