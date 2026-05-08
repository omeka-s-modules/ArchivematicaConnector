<?php
namespace ArchivematicaConnector\Job;

use Omeka\Job\AbstractJob;

class Undo extends AbstractJob
{
    public function perform()
    {
        $jobId = $this->getArg('jobId');
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        foreach ($api->search('archivematica_items', ['job_id' => $jobId])->getContent() as $archivematicaItem) {
            $api->delete('archivematica_items', $archivematicaItem->id());
            $api->delete('items', $archivematicaItem->item()->id());
        }
    }
}
