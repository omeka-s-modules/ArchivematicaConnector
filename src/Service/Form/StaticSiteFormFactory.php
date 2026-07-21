<?php
namespace ArchivematicaConnector\Service\Form;

use ArchivematicaConnector\Form\StaticSiteForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class StaticSiteFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new StaticSiteForm;
    }
}
