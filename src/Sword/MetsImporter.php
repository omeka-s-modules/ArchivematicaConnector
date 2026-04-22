<?php
namespace ArchivematicaConnector\Sword;

use ArchivematicaConnector\Entity\ArchivematicaImport;
use ArchivematicaConnector\Entity\ArchivematicaItem;
use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\Job;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;

// Extracts a BagIt DIP, parses its METS file, and creates Omeka items and media
class MetsImporter
{
    private const NS = [
        'mets'    => 'http://www.loc.gov/METS/',
        'dc'      => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'xlink'   => 'http://www.w3.org/1999/xlink',
    ];

    public function __construct(
        private ApiManager $api,
        private EntityManager $em,
        private TempFileFactory $tempFileFactory
    ) {}

    // Returns archivematica_import ID
    public function import(string $zipPath): int
    {
        $extractDir = $this->extractZip($zipPath);

        try {
            $metsPath  = $this->findMets($extractDir);
            $mets      = $this->loadMets($metsPath);
            $metadata  = $this->parseMetadata($mets);
            $filePaths = $this->parseFilePaths($mets, dirname($metsPath));

            $item     = $this->createItem($metadata);
            $position = 1;
            $added    = 0;

            foreach ($filePaths as $path) {
                if (file_exists($path)) {
                    $this->createMedia($item, $path, $position++);
                    $added++;
                }
            }

            return $this->recordImport($item, $added);
        } finally {
            // Always remove temp directory, even if an exception is thrown
            $this->rrmdir($extractDir);
        }
    }

    private function extractZip(string $zipPath): string
    {
        $dir = sys_get_temp_dir() . '/amdip_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open DIP ZIP');
        }
        $zip->extractTo($dir);
        $zip->close();

        return $dir;
    }

    // DIP structure varies by Archivematica version — check three possible METS locations
    private function findMets(string $dir): string
    {
        foreach ([
            $dir . '/*/data/METS.*.xml',
            $dir . '/data/METS.*.xml',
            $dir . '/METS.*.xml',
        ] as $pattern) {
            if ($matches = glob($pattern)) {
                return $matches[0];
            }
        }
        throw new \RuntimeException('METS file not found in DIP');
    }

    private function loadMets(string $path): \SimpleXMLElement
    {
        $mets = new \SimpleXMLElement(file_get_contents($path));
        foreach (self::NS as $prefix => $uri) {
            $mets->registerXPathNamespace($prefix, $uri);
        }
        return $mets;
    }

    // Dispatch to appropriate parser based on the metadata schema declared in the METS
    private function parseMetadata(\SimpleXMLElement $mets): array
    {
        $mdWrap = $mets->xpath('//mets:dmdSec[1]/mets:mdWrap');
        if (!$mdWrap) {
            return [];
        }
        switch ((string) $mdWrap[0]['MDTYPE']) {
            case 'DC':
                return $this->parseDc($mets);
            default:
                return [];
        }
    }

    // Pull element values from the first dmdSec, which describes the intellectual entity
    private function parseDc(\SimpleXMLElement $mets): array
    {
        $metadata = [];
        foreach ($mets->xpath('//mets:dmdSec[1]/mets:mdWrap/mets:xmlData/dcterms:dublincore/*') as $node) {
            $value = trim((string) $node);
            if ($value !== '') {
                $metadata['dcterms:' . $node->getName()][] = $value;
            }
        }
        return $metadata;
    }

    // Only ingest files from the "original" group
    private function parseFilePaths(\SimpleXMLElement $mets, string $metsDir): array
    {
        $paths = [];
        foreach ($mets->xpath('//mets:fileGrp[@USE="original"]//mets:FLocat') as $loc) {
            $href = (string) $loc->attributes('http://www.w3.org/1999/xlink')['href'];
            if ($href) {
                $paths[] = $metsDir . '/' . $href;
            }
        }
        return $paths;
    }

    // Build a property map for whichever vocabularies the parsed metadata uses
    private function createItem(array $metadata): object
    {
        $prefixes = array_unique(array_map(fn($t) => explode(':', $t)[0], array_keys($metadata)));
        $propMap  = [];
        foreach ($prefixes as $prefix) {
            foreach ($this->api->search('properties', ['vocabulary_prefix' => $prefix])->getContent() as $prop) {
                $propMap[$prop->term()] = $prop->id();
            }
        }

        $values = [];
        foreach ($metadata as $term => $strings) {
            if (!isset($propMap[$term])) {
                continue;
            }
            foreach ($strings as $string) {
                $values[$term][] = [
                    'type'        => 'literal',
                    '@value'      => $string,
                    'property_id' => $propMap[$term],
                ];
            }
        }

        return $this->api->create('items', $values)->getContent();
    }

    // Copy the extracted file into an Omeka TempFile so the file store handles storage and thumbnailing,
    private function createMedia(object $item, string $filePath, int $position): void
    {
        $filename  = basename($filePath);
        $mediaType = mime_content_type($filePath) ?: 'application/octet-stream';

        $tempFile = $this->tempFileFactory->build();
        copy($filePath, $tempFile->getTempPath());
        $tempFile->setSourceName($filename);
        $tempFile->store('original', $mediaType);

        $itemEntity = $this->em->getReference(\Omeka\Entity\Item::class, $item->id());

        $media = new Media;
        $media->setItem($itemEntity);
        $media->setOwner($itemEntity->getOwner());
        $media->setIngester('upload');
        $media->setRenderer('file');
        $media->setData([]);
        $media->setSource($filename);
        $media->setMediaType($tempFile->getMediaType());
        $media->setStorageId($tempFile->getStorageId());
        $media->setExtension($tempFile->getExtension());
        $media->setSha256($tempFile->getSha256());
        $media->setSize($tempFile->getSize());
        $media->setHasOriginal(true);
        $media->setHasThumbnails($tempFile->hasThumbnails());
        $media->setPosition($position);

        $this->em->persist($media);
        $this->em->flush();
    }

    // archivematica_import requires a job FK, so we create a synthetic completed job rather than
    // dispatching a real async job — SWORD processing is synchronous and the job will never run.
    private function recordImport(object $item, int $added): int
    {
        $job = new Job;
        $job->setStatus(Job::STATUS_COMPLETED);
        $job->setClass('ArchivematicaConnector\Job\Import');
        $job->setArgs(['source' => 'sword']);
        $job->setStarted(new \DateTime);
        $job->setEnded(new \DateTime);
        $this->em->persist($job);
        $this->em->flush();

        $jobRef  = $this->em->getReference(Job::class, $job->getId());
        $itemRef = $this->em->getReference(\Omeka\Entity\Item::class, $item->id());

        $archivematicaItem = new ArchivematicaItem;
        $archivematicaItem->setItem($itemRef);
        $archivematicaItem->setJob($jobRef);
        $archivematicaItem->setUri('');
        $archivematicaItem->setLastModified(new \DateTime);
        $this->em->persist($archivematicaItem);

        $archivematicaImport = new ArchivematicaImport;
        $archivematicaImport->setJob($jobRef);
        $archivematicaImport->setAddedCount($added);
        $archivematicaImport->setUpdatedCount(0);
        $archivematicaImport->setComment('Imported via SWORD');
        $this->em->persist($archivematicaImport);

        $this->em->flush();

        return $archivematicaImport->getId();
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (in_array(basename($file), ['.', '..'], true)) {
                continue;
            }
            is_dir($file) ? $this->rrmdir($file) : unlink($file);
        }
        rmdir($dir);
    }
}
