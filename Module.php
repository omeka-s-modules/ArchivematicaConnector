<?php
namespace ArchivematicaConnector;

use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        $acl->allow(
            null,
            ['ArchivematicaConnector\Api\Adapter\ArchivematicaItemAdapter'],
            ['search', 'read']
        );
        $this->registerStaticSiteExporter($services);
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec("CREATE TABLE archivematica_item (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, job_id INT NOT NULL, uri VARCHAR(255) NOT NULL, last_modified DATETIME NOT NULL, UNIQUE INDEX UNIQ_F03B22D6126F525E (item_id), INDEX IDX_F03B22D6BE04EA9 (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;");
        $connection->exec("CREATE TABLE archivematica_import (id INT AUTO_INCREMENT NOT NULL, job_id INT NOT NULL, undo_job_id INT DEFAULT NULL, rerun_job_id INT DEFAULT NULL, added_count INT NOT NULL, updated_count INT NOT NULL, comment VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_9E9D8BE04EA9 (job_id), UNIQUE INDEX UNIQ_9E9D84C276F75 (undo_job_id), UNIQUE INDEX UNIQ_9E9D87071F49C (rerun_job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;");
        $connection->exec("ALTER TABLE archivematica_item ADD CONSTRAINT FK_F03B22D6126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;");
        $connection->exec("ALTER TABLE archivematica_item ADD CONSTRAINT FK_F03B22D6BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id);");
        $connection->exec("ALTER TABLE archivematica_import ADD CONSTRAINT FK_9E9D8BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id); ");
        $connection->exec("ALTER TABLE archivematica_import ADD CONSTRAINT FK_9E9D84C276F75 FOREIGN KEY (undo_job_id) REFERENCES job (id); ");
        $connection->exec("ALTER TABLE archivematica_import ADD CONSTRAINT FK_9E9D87071F49C FOREIGN KEY (rerun_job_id) REFERENCES job (id);");
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec("ALTER TABLE archivematica_item DROP FOREIGN KEY FK_F03B22D6126F525E;");
        $connection->exec("ALTER TABLE archivematica_item DROP FOREIGN KEY FK_F03B22D6BE04EA9;");
        $connection->exec("ALTER TABLE archivematica_import DROP FOREIGN KEY FK_9E9D8BE04EA9;");
        $connection->exec("ALTER TABLE archivematica_import DROP FOREIGN KEY FK_9E9D84C276F75;");
        $connection->exec("ALTER TABLE archivematica_import DROP FOREIGN KEY FK_9E9D87071F49C;");
        $connection->exec('DROP TABLE archivematica_item');
        $connection->exec('DROP TABLE archivematica_import');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.search.query',
            [$this, 'importSearch']
        );
    }

    public function importSearch($event)
    {
        $query = $event->getParam('request')->getContent();
        if (isset($query['archivematica_import_id'])) {
            $qb = $event->getParam('queryBuilder');
            $adapter = $event->getTarget();
            $importItemAlias = $adapter->createAlias();
            $qb->innerJoin(
                \ArchivematicaConnector\Entity\ArchivematicaItem::class, $importItemAlias,
                'WITH', "$importItemAlias.item = omeka_root.id"
            )->andWhere($qb->expr()->eq(
                "$importItemAlias.job",
                $adapter->createNamedParameter($qb, $query['archivematica_import_id'])
            ));
        }
    }

    protected function registerStaticSiteExporter($services)
    {
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('StaticSiteExport');
        if (!$module || $module->getState() !== \Omeka\Module\Manager::STATE_ACTIVE) {
            return;
        }
        if (!$services->has('Exports\ExporterManager')) {
            return;
        }
        // Check that at least one completed static site export has its ZIP on disk
        $sitesDir = rtrim((string) $services->get('Omeka\Settings')->get('static_site_export_sites_directory_path', ''), '/');
        $completedSites = $services->get('Omeka\Connection')->fetchAllAssociative(
            'SELECT ss.name FROM static_site ss INNER JOIN job j ON ss.job_id = j.id WHERE j.status = ?',
            ['completed']
        );
        $hasZip = false;
        foreach ($completedSites as $site) {
            if (is_file(sprintf('%s/%s.zip', $sitesDir, $site['name']))) {
                $hasZip = true;
                break;
            }
        }
        if (!$hasZip) {
            return;
        }
        $services->get('Exports\ExporterManager')->configure([
            'factories' => [
                'archivematica_static_site' => Service\Exporter\ArchivematicaStaticSiteFactory::class,
            ],
        ]);
    }
}
