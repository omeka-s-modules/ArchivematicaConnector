<?php
namespace ArchivematicaConnector\Exporter;

use Exports\Exporter\ExporterInterface;
use Exports\Job\ExportJob;
use Laminas\Form\Element\Select;
use Laminas\Form\Fieldset;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

class ArchivematicaStaticSite implements ExporterInterface
{
    protected $apiManager;
    protected $settings;

    public function __construct(ApiManager $apiManager, Settings $settings)
    {
        $this->apiManager = $apiManager;
        $this->settings = $settings;
    }

    public function getLabel(): string
    {
        return 'Archivematica (Static Site)'; // @translate
    }

    public function getDescription(): ?string
    {
        return 'Export a completed Static Site Export package as an Archivematica transfer package (objects/ + metadata/metadata.csv).'; // @translate
    }

    public function prepareForm(PhpRenderer $view): void
    {
    }

    public function addElements(Fieldset $fieldset): void
    {
        $options = ['' => 'Select a static site…']; // @translate
        foreach ($this->apiManager->search('static_site_export_static_sites')->getContent() as $staticSite) {
            $job = $staticSite->job();
            if ($job && $job->status() === 'completed') {
                $options[$staticSite->id()] = sprintf(
                    '%s (%s)',
                    $staticSite->site()->title(),
                    $staticSite->name()
                );
            }
        }

        $fieldset->add([
            'type' => Select::class,
            'name' => 'static_site_id',
            'options' => [
                'label' => 'Static site', // @translate
                'info' => 'Select a completed static site export to package as an Archivematica SIP.', // @translate
                'value_options' => $options,
            ],
            'attributes' => [
                'id' => 'static_site_id',
                'required' => true,
            ],
        ]);
    }

    public function export(ExportJob $job): void
    {
        $export = $job->getExport();
        $staticSiteId = $export->dataValue('static_site_id');

        if (!is_numeric($staticSiteId)) {
            throw new \RuntimeException('No static site selected for export.');
        }

        $staticSite = $this->apiManager
            ->read('static_site_export_static_sites', (int) $staticSiteId)
            ->getContent();

        $sitesDirectoryPath = $this->settings->get('static_site_export_sites_directory_path');
        $zipPath = sprintf('%s/%s.zip', rtrim($sitesDirectoryPath, '/'), $staticSite->name());

        if (!is_file($zipPath)) {
            throw new \RuntimeException(sprintf(
                'Static site ZIP not found at "%s". Ensure the export job has completed.',
                $zipPath
            ));
        }

        $job->makeDirectory('objects');
        $job->makeDirectory('metadata');

        $destFilename = sprintf('%s.zip', $staticSite->name());
        copy($zipPath, $job->getExportDirectoryPath() . '/objects/' . $destFilename);

        $site = $staticSite->site();
        $csvPath = $job->getExportDirectoryPath() . '/metadata/metadata.csv';
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, ['filename', 'dc.title', 'dc.description', 'dc.identifier'], ',', '"', '');
        fputcsv($fp, [
            'objects/' . $destFilename,
            $site->title(),
            $site->summary() ?? '',
            $staticSite->dataValue('base_url') ?? '',
        ], ',', '"', '');
        fclose($fp);
    }
}
