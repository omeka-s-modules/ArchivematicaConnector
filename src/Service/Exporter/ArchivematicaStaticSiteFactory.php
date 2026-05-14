<?php
namespace ArchivematicaConnector\Service\Exporter;

use ArchivematicaConnector\Exporter\ArchivematicaStaticSite;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ArchivematicaStaticSiteFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ArchivematicaStaticSite(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Settings')
        );
    }
}
