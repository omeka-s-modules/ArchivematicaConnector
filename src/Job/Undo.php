<?php
namespace ArchivematicaConnector\Job;

use Omeka\Job\AbstractJob;

class Undo extends AbstractJob
{
    public function perform()
    {
        $jobId = $this->getArg('jobId');
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $response = $api->search('archivematica_items', ['job_id' => $jobId]);
        $archivematicaItems = $response->getContent();
        if ($archivematicaItems) {
            foreach ($archivematicaItems as $archivematicaItem) {
                $api->delete('archivematica_items', $archivematicaItem->id());
                $api->delete('items', $archivematicaItem->item()->id());
            }
        }
    }
}
