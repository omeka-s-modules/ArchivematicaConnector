<?php
namespace ArchivematicaConnector\Job;

use Omeka\Job\AbstractJob;

class Undo extends AbstractJob
{
    public function perform()
    {
        $jobId = $this->getArg('previous_job');
        $comment = $this->getArg('comment');
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        $archivematicaItems = $api->search('archivematica_items', ['job_id' => $jobId])->getContent();
        $deletedItemCount = 0;
        $deletedFileCount = 0;
        foreach ($archivematicaItems as $archivematicaItem) {
            $deletedFileCount += count($archivematicaItem->item()->media());
            $api->delete('archivematica_items', $archivematicaItem->id());
            $api->delete('items', $archivematicaItem->item()->id());
            $deletedItemCount++;
        }

        $commentParts = array_filter([
            $comment,
            $deletedItemCount ? $deletedItemCount . ' items deleted' : null,
            $deletedFileCount ? $deletedFileCount . ' files deleted' : null,
        ]);
        $comment = implode('; ', $commentParts);

        $api->create('archivematica_imports', [
            'o:job' => ['o:id' => $this->job->getId()],
            'comment' => $comment,
            'added_count' => 0,
            'updated_count' => 0,
        ]);
        $jobArgs = $this->job->getArgs();
        $jobArgs['comment'] = $comment;
        $this->job->setArgs($jobArgs);
    }
}
