<?php
namespace ArchivematicaConnector\Exporter;

use Exports\Exporter\ExporterInterface;
use Exports\Job\ExportJob;
use Laminas\Form\Element as LaminasElement;
use Laminas\Form\Fieldset;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Manager as ApiManager;
use Omeka\Form\Element as OmekaElement;

class Archivematica implements ExporterInterface
{
    /**
     * Maximum length, in bytes, of a single metadata.csv field value.
     *
     * Archivematica parses metadata.csv with Python's csv module, which has a
     * hard-coded 131072-byte field size limit. Exceeding it aborts METS
     * generation for the whole SIP, so values are truncated well below that.
     */
    const MAX_FIELD_LENGTH = 100000;

    protected $apiManager;

    public function __construct(ApiManager $apiManager)
    {
        $this->apiManager = $apiManager;
    }

    public function getLabel(): string
    {
        return 'Archivematica'; // @translate
    }

    public function getDescription(): ?string
    {
        return 'Export items and files as an Archivematica transfer package (objects/ + metadata/metadata.csv).'; // @translate
    }

    public function prepareForm(PhpRenderer $view): void
    {
    }

    public function addElements(Fieldset $fieldset): void
    {
        $fieldset->add([
            'type' => OmekaElement\Query::class,
            'name' => 'query_items',
            'options' => [
                'label' => 'Item query', // @translate
                'info' => 'Select the items to export. If empty, all available items will be exported.', // @translate
                'query_resource_type' => 'items',
            ],
            'attributes' => [
                'id' => 'query_items',
                'required' => false,
            ],
        ]);
        $fieldset->add([
            'type' => LaminasElement\Checkbox::class,
            'name' => 'include_files',
            'options' => [
                'label' => 'Include original files', // @translate
                'info' => 'If checked, original media files will be copied into the objects/ directory of the transfer package.', // @translate
                'use_hidden_element' => true,
                'checked_value' => '1',
                'unchecked_value' => '0',
            ],
            'attributes' => [
                'id' => 'include_files',
                'value' => '1',
            ],
        ]);
    }

    public function export(ExportJob $job): void
    {
        $export = $job->getExport();
        $job->setOriginalIdentityMap();
        $job->makeDirectory('objects');
        $job->makeDirectory('metadata');

        parse_str($export->dataValue('query_items') ?? '', $itemQuery);
        $includeFiles = (bool) $export->dataValue('include_files');

        $itemIds = $this->apiManager->search('items', $itemQuery, ['returnScalar' => 'id'])->getContent();

        if (empty($itemIds)) {
            return;
        }

        // Iterate every item, building the CSV header row.
        $headerColumns = [];
        foreach (array_chunk($itemIds, 100) as $chunk) {
            if ($job->shouldStop()) {
                return; // Stop the job if requested.
            }
            foreach ($chunk as $itemId) {
                $item = $this->apiManager->read('items', $itemId)->getContent();
                $itemJson = json_decode(json_encode($item), true);
                foreach ($itemJson as $k => $v) {
                    $fieldData = $this->getFieldData($k, $v);
                    if (is_array($fieldData)) {
                        foreach ($fieldData as $data) {
                            $headerColumns[$data[0]] = $data[0];
                        }
                    }
                }
            }
            // Clear memory after every chunk.
            $job->detachAllNewEntities();
        }
        ksort($headerColumns);

        // Write the header row to the CSV file.
        $csvPath = $job->getExportDirectoryPath() . '/metadata/metadata.csv';
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, array_merge(['filename'], array_keys($headerColumns)), ',', '"', '');
        $headerEndPos = ftell($fp);
        $rowTemplate = array_fill_keys(array_keys($headerColumns), '');

        // Iterate every item, building one CSV row per media file.
        foreach (array_chunk($itemIds, 100) as $chunk) {
            if ($job->shouldStop()) {
                break; // Stop the job if requested.
            }
            foreach ($chunk as $itemId) {
                $item = $this->apiManager->read('items', $itemId)->getContent();
                $itemJson = json_decode(json_encode($item), true);
                $metaRow = $rowTemplate;
                foreach ($itemJson as $k => $v) {
                    $fieldData = $this->getFieldData($k, $v);
                    if (is_array($fieldData)) {
                        foreach ($fieldData as $data) {
                            if (array_key_exists($data[0], $metaRow)) {
                                $metaRow[$data[0]] = $data[1];
                            }
                        }
                    }
                }

                $itemHasRow = false;
                if ($includeFiles) {
                    foreach ($item->media() as $media) {
                        $filename = $this->mediaFilename($item->id(), $media);
                        if (!$filename) {
                            continue;
                        }
                        $this->copyMediaFile($media, $job->getExportDirectoryPath() . '/objects/' . $filename);
                        fputcsv($fp, array_merge(['objects/' . $filename], array_values($metaRow)), ',', '"', '');
                        $itemHasRow = true;
                    }
                }

                // Any item left without a real object in objects/ (no media,
                // none exportable, or files weren't included) still has
                // metadata worth keeping. Give it a small JSON snapshot to
                // carry that metadata, since Archivematica discards empty
                // directories before generating the SIP's METS and so cannot
                // attach metadata to a row with no real object.
                if (!$itemHasRow) {
                    $filename = $this->writeItemMetadataFile($job->getExportDirectoryPath(), $item->id(), $itemJson);
                    fputcsv($fp, array_merge(['objects/' . $filename], array_values($metaRow)), ',', '"', '');
                }
            }
            // Clear memory after every chunk.
            $job->detachAllNewEntities();
        }

        $dataWritten = ftell($fp) > $headerEndPos;
        fclose($fp);

        if (!$dataWritten) {
            unlink($csvPath);
        }
    }

    protected function getFieldData(string $k, $v): ?array
    {
        // Skip non-metadata fields and empty values.
        if (is_null($v) || (is_array($v) && empty($v))) {
            return null;
        }
        if (!$this->isPropertyValues($v)) {
            return null;
        }

        $column = str_replace(':', '.', $k);
        $values = [];
        foreach ($v as $value) {
            if (isset($value['@value'])) {
                $values[] = $value['@value'];
            } elseif (isset($value['display_title'])) {
                $values[] = $value['display_title'];
            } elseif (isset($value['@id'])) {
                $values[] = $value['@id'];
            }
        }
        if (empty($values)) {
            return null;
        }
        $value = implode('|', $values);
        if (strlen($value) > self::MAX_FIELD_LENGTH) {
            $value = substr($value, 0, self::MAX_FIELD_LENGTH) . '... [truncated]';
        }
        return [[$column, $this->stripInvalidXmlChars($value)]];
    }

    /**
     * Remove characters that lxml (used by Archivematica's METS generation)
     * refuses to serialize as XML text, such as the form feed page breaks
     * OCR/PDF text extraction leaves behind. Left in place, one aborts METS
     * generation for the whole SIP with "All strings must be XML compatible".
     */
    protected function stripInvalidXmlChars(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    protected function isPropertyValues($v): bool
    {
        return (
            is_array($v)
            && 0 < count($v)
            && is_array(reset($v))
            && isset(reset($v)['property_id'])
        );
    }

    protected function mediaFilename(int $itemId, $media): ?string
    {
        $storageId = $media->storageId();
        if (!$storageId) {
            return null;
        }
        $source = $media->source() ?: $storageId;
        $extension = $media->extension();
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $source);
        // Prefix with item ID to ensure uniqueness across items.
        return sprintf('%d_%s', $itemId, $safeName . ($extension && !str_ends_with($safeName, '.' . $extension) ? '.' . $extension : ''));
    }

    protected function writeItemMetadataFile(string $exportDirectoryPath, int $itemId, array $itemJson): string
    {
        $filename = sprintf('%d_metadata.json', $itemId);
        file_put_contents($exportDirectoryPath . '/objects/' . $filename, json_encode($itemJson, JSON_PRETTY_PRINT));
        return $filename;
    }

    protected function copyMediaFile($media, string $destPath): void
    {
        $sourceUrl = $media->originalUrl();
        if (!$sourceUrl) {
            return;
        }
        $source = @fopen($sourceUrl, 'r');
        if (!$source) {
            return;
        }
        $dest = fopen($destPath, 'w');
        stream_copy_to_stream($source, $dest);
        fclose($source);
        fclose($dest);
    }
}
