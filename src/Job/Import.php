<?php
namespace ArchivematicaConnector\Job;

use DOMDocument;
use DOMXPath;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\ErrorStore;
use ZipArchive;

class Import extends AbstractJob
{
    protected $api;
    protected $logger;
    protected $propertyIds = [];

    public function perform()
    {
        $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');

        $args = $this->job->getArgs();
        $dipPath = $args['dip_path'] ?? null;

        if (!$dipPath || !file_exists($dipPath)) {
            $this->logger->err('DIP file not found: ' . $dipPath);
            return;
        }

        $tempDir = null;
        try {
            $tempDir = $this->extractZip($dipPath);
            $metsPath = $this->findMets($tempDir);
            if (!$metsPath) {
                throw new \RuntimeException('No METS file found in DIP ZIP');
            }

            $dom = new DOMDocument;
            $dom->load($metsPath);

            $this->propertyIds = $this->loadPropertyIds();
            $metadata = $this->parseDc($dom);
            foreach ($this->parseRights($dom) as $rightsValue) {
                $metadata['rights'][] = $rightsValue;
            }
            $filePaths = $this->parseFilePaths($dom, $metsPath);

            $item = $this->createItem($metadata, $args);
            $this->createMedia($item, $filePaths);
            $this->recordItem($item);
            $this->createImportRecord($args);

        } catch (\Exception $e) {
            $this->logger->err('DIP import failed: ' . $e->getMessage());
        } finally {
            // Clean up extracted ZIP contents
            if ($tempDir && is_dir($tempDir)) {
                $this->deleteDir($tempDir);
            }
            // Remove the staging file saved by the controller before job dispatch
            if ($dipPath && file_exists($dipPath)) {
                unlink($dipPath);
            }
        }
    }

    protected function extractZip(string $zipPath): string
    {
        $tempDir = sys_get_temp_dir() . '/archivematica_dip_' . uniqid();
        mkdir($tempDir, 0755, true);
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open DIP ZIP file');
        }
        $zip->extractTo($tempDir);
        $zip->close();
        return $tempDir;
    }

    protected function findMets(string $tempDir): ?string
    {
        // Account for structural variations across Archivematica versions
        foreach ([
            $tempDir . '/*/data/METS.*.xml',
            $tempDir . '/data/METS.*.xml',
            $tempDir . '/METS.*.xml',
        ] as $pattern) {
            $matches = glob($pattern);
            if (!empty($matches)) {
                return $matches[0];
            }
        }
        return null;
    }

    // Only load DC property IDs once
    protected function loadPropertyIds(): array
    {
        $terms = [
            'dcterms:title', 'dcterms:description', 'dcterms:creator',
            'dcterms:subject', 'dcterms:date', 'dcterms:format',
            'dcterms:identifier', 'dcterms:rights', 'dcterms:publisher',
            'dcterms:contributor', 'dcterms:type', 'dcterms:source',
            'dcterms:language', 'dcterms:relation', 'dcterms:coverage',
        ];
        $ids = [];
        foreach ($terms as $term) {
            $results = $this->api->search('properties', ['term' => $term])->getContent();
            if (!empty($results)) {
                $ids[$term] = $results[0]->id();
            }
        }
        return $ids;
    }

    protected function parseDc(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mets', 'http://www.loc.gov/METS/');
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('dcterms', 'http://purl.org/dc/terms/');

        $dcElements = [
            'title', 'description', 'creator', 'subject', 'date', 'format',
            'identifier', 'rights', 'publisher', 'contributor', 'type',
            'source', 'language', 'relation', 'coverage',
        ];

        $metadata = [];
        // Only the first dmdSec is parsed; a DIP represents one intellectual entity
        $nodes = $xpath->query('//mets:dmdSec[1]//*');
        foreach ($nodes as $node) {
            $ns = $node->namespaceURI;
            if ($ns !== 'http://purl.org/dc/elements/1.1/' && $ns !== 'http://purl.org/dc/terms/') {
                continue;
            }
            $localName = $node->localName;
            $value = trim($node->textContent);
            if (in_array($localName, $dcElements) && $value !== '') {
                $metadata[$localName][] = $value;
            }
        }
        return $metadata;
    }

    protected function parseFilePaths(DOMDocument $dom, string $metsPath): array
    {
        $metsDir = dirname($metsPath);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mets', 'http://www.loc.gov/METS/');

        $filePaths = [];
        $fileNodes = $xpath->query('//mets:fileGrp[@USE="original"]//mets:FLocat');
        foreach ($fileNodes as $node) {
            $href = $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            if (!$href) {
                continue;
            }
            // xlink:href is relative to the METS file's directory
            $fullPath = realpath($metsDir . '/' . $href);
            if ($fullPath && file_exists($fullPath)) {
                $filePaths[] = $fullPath;
            }
        }
        return $filePaths;
    }

    protected function parseRights(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mets', 'http://www.loc.gov/METS/');

        $rights = [];

        // Archivematica uses PREMIS v3 in recent versions, v2 in older ones
        foreach (['http://www.loc.gov/premis/v3', 'http://www.loc.gov/premis/v2'] as $premisNs) {
            $xpath->registerNamespace('premis', $premisNs);
            $statements = $xpath->query('//mets:amdSec//premis:rightsStatement');
            if ($statements->length === 0) {
                continue;
            }

            foreach ($statements as $statement) {
                $basisNode = $xpath->query('premis:rightsBasis', $statement)->item(0);
                if (!$basisNode) {
                    continue;
                }
                $basis = trim($basisNode->textContent);
                $detail = [];

                switch (strtolower($basis)) {
                    case 'copyright':
                        $status = $xpath->query('premis:copyrightInformation/premis:copyrightStatus', $statement)->item(0);
                        $jurisdiction = $xpath->query('premis:copyrightInformation/premis:copyrightJurisdiction', $statement)->item(0);
                        $note = $xpath->query('premis:copyrightInformation/premis:copyrightNote', $statement)->item(0);
                        if ($status) $detail[] = trim($status->textContent);
                        if ($jurisdiction) $detail[] = '(' . trim($jurisdiction->textContent) . ')';
                        if ($note) $detail[] = trim($note->textContent);
                        break;

                    case 'license':
                        $terms = $xpath->query('premis:licenseInformation/premis:licenseTerms', $statement)->item(0);
                        $note = $xpath->query('premis:licenseInformation/premis:licenseNote', $statement)->item(0);
                        if ($terms) $detail[] = trim($terms->textContent);
                        if ($note) $detail[] = trim($note->textContent);
                        break;

                    case 'statute':
                        $citation = $xpath->query('premis:statuteInformation/premis:statuteCitation', $statement)->item(0);
                        $jurisdiction = $xpath->query('premis:statuteInformation/premis:statuteJurisdiction', $statement)->item(0);
                        $note = $xpath->query('premis:statuteInformation/premis:statuteNote', $statement)->item(0);
                        if ($citation) $detail[] = trim($citation->textContent);
                        if ($jurisdiction) $detail[] = '(' . trim($jurisdiction->textContent) . ')';
                        if ($note) $detail[] = trim($note->textContent);
                        break;

                    default: // Other
                        $note = $xpath->query('premis:otherRightsInformation/premis:otherRightsNote', $statement)->item(0);
                        if ($note) $detail[] = trim($note->textContent);
                        break;
                }

                $grantedNote = $xpath->query('premis:rightsGranted/premis:rightsGrantedNote', $statement)->item(0);
                if ($grantedNote && trim($grantedNote->textContent) !== '') {
                    $detail[] = trim($grantedNote->textContent);
                }

                $value = empty($detail) ? $basis : $basis . ': ' . implode('; ', $detail);
                $rights[] = $value;
            }

            if (!empty($rights)) {
                break;
            }
        }

        return $rights;
    }

    protected function createItem(array $metadata, array $args): \Omeka\Api\Representation\ItemRepresentation
    {
        $itemData = ['o:is_public' => true];

        foreach ($args['itemSets'] ?? [] as $setId) {
            $itemData['o:item_set'][] = ['o:id' => (int) $setId];
        }
        foreach ($args['itemSites'] ?? [] as $siteId) {
            $itemData['o:site'][] = ['o:id' => (int) $siteId];
        }

        foreach ($metadata as $element => $values) {
            $term = 'dcterms:' . $element;
            if (!isset($this->propertyIds[$term])) {
                continue;
            }
            $propertyId = $this->propertyIds[$term];
            foreach ($values as $value) {
                $itemData[$term][] = [
                    'type' => 'literal',
                    'property_id' => $propertyId,
                    '@value' => $value,
                ];
            }
        }

        return $this->api->create('items', $itemData)->getContent();
    }

    protected function createMedia(\Omeka\Api\Representation\ItemRepresentation $item, array $filePaths): void
    {
        $services = $this->getServiceLocator();
        $em = $services->get('Omeka\EntityManager');
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');

        $itemEntity = $em->find(\Omeka\Entity\Item::class, $item->id());
        $position = 1;

        foreach ($filePaths as $filePath) {
            try {
                $tempFile = $tempFileFactory->build();
                $tempFile->setSourceName(basename($filePath));

                // Copy directly into the TempFile path to bypass Uploader,
                // which rejects files not submitted via HTTP upload
                if (!copy($filePath, $tempFile->getTempPath())) {
                    throw new \RuntimeException('Could not copy file to temp location');
                }

                $media = new Media;
                $media->setItem($itemEntity);
                $media->setIngester('upload');
                $media->setRenderer('file');
                $media->setIsPublic(true);
                $media->setSource(basename($filePath));
                $media->setPosition($position);
                $media->setData([]);

                $errorStore = new ErrorStore;
                $tempFile->mediaIngestFile($media, new Request('create', 'media'), $errorStore);

                if ($errorStore->hasErrors()) {
                    $this->logger->warn('Media validation failed for ' . basename($filePath) . ': ' . json_encode($errorStore->getErrors()));
                    continue;
                }

                $em->persist($media);
                $em->flush();
                $position++;
            } catch (\Exception $e) {
                $this->logger->warn('Could not ingest ' . basename($filePath) . ': ' . $e->getMessage());
            }
        }
    }

    protected function recordItem(\Omeka\Api\Representation\ItemRepresentation $item): void
    {
        $this->api->create('archivematica_items', [
            'o:job' => ['o:id' => $this->job->getId()],
            'o:item' => ['o:id' => $item->id()],
            'uri' => $item->apiUrl(),
            'last_modified' => new \DateTime,
        ]);
    }

    protected function createImportRecord(array $args): void
    {
        $this->api->create('archivematica_imports', [
            'o:job' => ['o:id' => $this->job->getId()],
            'added_count' => 1,
            'updated_count' => 0,
            'comment' => $args['comment'] ?? null,
        ]);
    }

    protected function deleteDir(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
