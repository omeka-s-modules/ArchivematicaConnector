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
        if ($this->staticSiteExportIsActive()) {
            $services->get('FormElementManager')->configure([
                'factories' => [
                    'StaticSiteExport\Form\StaticSiteForm' => \ArchivematicaConnector\Service\Form\StaticSiteFormFactory::class,
                ],
            ]);
        }
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec("CREATE TABLE archivematica_item (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, job_id INT NOT NULL, uri VARCHAR(255) NOT NULL, last_modified DATETIME NOT NULL, UNIQUE INDEX UNIQ_F03B22D6126F525E (item_id), INDEX IDX_F03B22D6BE04EA9 (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;");
        $connection->exec("CREATE TABLE archivematica_import (id INT AUTO_INCREMENT NOT NULL, job_id INT NOT NULL, undo_job_id INT DEFAULT NULL, added_count INT NOT NULL, updated_count INT NOT NULL, comment LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_9E9D8BE04EA9 (job_id), UNIQUE INDEX UNIQ_9E9D84C276F75 (undo_job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;");
        $connection->exec("ALTER TABLE archivematica_item ADD CONSTRAINT FK_F03B22D6126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;");
        $connection->exec("ALTER TABLE archivematica_item ADD CONSTRAINT FK_F03B22D6BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id);");
        $connection->exec("ALTER TABLE archivematica_import ADD CONSTRAINT FK_9E9D8BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id); ");
        $connection->exec("ALTER TABLE archivematica_import ADD CONSTRAINT FK_9E9D84C276F75 FOREIGN KEY (undo_job_id) REFERENCES job (id); ");
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec("ALTER TABLE archivematica_item DROP FOREIGN KEY FK_F03B22D6126F525E;");
        $connection->exec("ALTER TABLE archivematica_item DROP FOREIGN KEY FK_F03B22D6BE04EA9;");
        $connection->exec("ALTER TABLE archivematica_import DROP FOREIGN KEY FK_9E9D8BE04EA9;");
        $connection->exec("ALTER TABLE archivematica_import DROP FOREIGN KEY FK_9E9D84C276F75;");
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
        if (!$this->staticSiteExportIsActive()) {
            return;
        }
        $sharedEventManager->attach(
            'StaticSiteExport\Api\Adapter\StaticSiteAdapter',
            'api.hydrate.post',
            [$this, 'hydrateStaticSiteData']
        );
        $sharedEventManager->attach(
            'StaticSiteExport\Job\ExportStaticSite',
            'static_site_export.site_export.post',
            [$this, 'dispatchSipPackagingJob']
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

    public function hydrateStaticSiteData($event)
    {
        $request = $event->getParam('request');
        $content = $request->getContent();
        if (empty($content['package_as_archivematica_sip'])) {
            return;
        }
        $entity = $event->getParam('entity');
        $data = $entity->getData() ?? [];
        $data['package_as_archivematica_sip'] = true;
        $entity->setData($data);
    }

    public function dispatchSipPackagingJob($event)
    {
        $exportJob = $event->getTarget();
        $staticSite = $exportJob->getStaticSite();
        if (!$staticSite->dataValue('package_as_archivematica_sip')) {
            return;
        }

        // Capture everything we need now, before the entity manager state changes
        $services = $this->getServiceLocator();
        $sitesDir = rtrim((string) $services->get('Omeka\Settings')
            ->get('static_site_export_sites_directory_path', ''), '/');
        $name = $staticSite->name();
        $site = $staticSite->site();
        $siteTitle = $site->title();
        $siteSummary = $site->summary() ?? '';
        $baseUrl = $staticSite->dataValue('base_url') ?? '';

        // Packaging is deferred to a shutdown function because this event fires
        // before createSiteArchive() runs (ZIP doesn't exist yet).
        // The shutdown function fires after perform-job.php exits when ZIP is sure to be created.
        register_shutdown_function(
            static function () use ($sitesDir, $name, $siteTitle, $siteSummary, $baseUrl): void {
                $zipPath = sprintf('%s/%s.zip', $sitesDir, $name);
                $sipZipPath = sprintf('%s/%s-AM_SIP.zip', $sitesDir, $name);

                if (!is_file($zipPath)) {
                    return;
                }

                $csvHandle = fopen('php://temp', 'r+');
                fputcsv($csvHandle, ['filename', 'dc.title', 'dc.description', 'dc.identifier'], ',', '"', '');
                fputcsv($csvHandle, [
                    sprintf('objects/%s.zip', $name),
                    $siteTitle,
                    $siteSummary,
                    $baseUrl,
                ], ',', '"', '');
                rewind($csvHandle);
                $csvContent = stream_get_contents($csvHandle);
                fclose($csvHandle);

                $zip = new \ZipArchive;
                if ($zip->open($sipZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    return;
                }
                $zip->addFile($zipPath, sprintf('objects/%s.zip', $name));
                $zip->addFromString('metadata/metadata.csv', $csvContent);
                $zip->close();
            }
        );
    }

    protected function staticSiteExportIsActive(): bool
    {
        $moduleManager = $this->getServiceLocator()->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('StaticSiteExport');
        return $module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }
}
