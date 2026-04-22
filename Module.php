<?php
namespace ArchivematicaConnector;

use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use ArchivematicaConnector\Form\ConfigForm;
use Composer\Semver\Comparator;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            null,
            ['ArchivematicaConnector\Api\Adapter\ArchivematicaItemAdapter'],
            ['search', 'read']
            );
        $acl->allow(
            null,
            [\ArchivematicaConnector\Controller\SwordController::class],
            ['deposit']
        );
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
}
