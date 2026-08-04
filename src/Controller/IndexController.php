<?php
namespace ArchivematicaConnector\Controller;

use ArchivematicaConnector\Form\ImportForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

class IndexController extends AbstractActionController
{
    public function indexAction()
    {
        $view = new ViewModel;
        $form = $this->getForm(ImportForm::class);
        $view->setVariable('form', $form);

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $files = $this->getRequest()->getFiles()->toArray();

            $form->setData(array_merge($post, $files));
            if ($form->isValid()) {
                $file = $files['dip_file'] ?? null;

                if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                    $this->messenger()->addError('File upload failed. Please select a valid Archivematica DIP file.'); // @translate
                    return $view;
                }

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                if (strtolower($ext) !== 'tar') {
                    $this->messenger()->addError('The selected file is not a TAR file. Please select a DIP TAR file exported from Archivematica.'); // @translate
                    return $view;
                }

                $destPath = sys_get_temp_dir() . '/omeka_archivematica_' . uniqid() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    $this->messenger()->addError('Could not save the uploaded file.'); // @translate
                    return $view;
                }

                $jobArgs = $post;
                $jobArgs['dip_path'] = $destPath;
                $jobArgs['original_filename'] = $file['name'];

                $job = $this->jobDispatcher()->dispatch('ArchivematicaConnector\Job\Import', $jobArgs);
                $message = new Message('Importing in Job ID %s', $job->getId()); // @translate
                $this->messenger()->addSuccess($message);
                return $this->redirect()->toRoute('admin/archivematica-connector/past-imports');
            } else {
                $this->messenger()->addError('There was an error during validation.'); // @translate
            }
        }

        return $view;
    }

    public function pastImportsAction()
    {
        $view = new ViewModel;
        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            if (isset($data['undoJobs'])) {
                $undoJobIds = [];
                foreach ($data['undoJobs'] as $jobId) {
                    $this->undoJob($jobId);
                    $undoJobIds[] = $jobId;
                }
                $message = new Message('Undo in progress on the following jobs: %s', // @translate
                    implode(', ', $undoJobIds));
                $this->messenger()->addSuccess($message);
            } else {
                $this->messenger()->addError('Error: no jobs selected'); // @translate
            }
            return $this->redirect()->toRoute('admin/archivematica-connector/past-imports');
        }

        $page = $this->params()->fromQuery('page', 1);
        $query = $this->params()->fromQuery() + [
            'page' => $page,
            'sort_by' => $this->params()->fromQuery('sort_by', 'id'),
            'sort_order' => $this->params()->fromQuery('sort_order', 'desc'),
        ];
        $response = $this->api()->search('archivematica_imports', $query);
        $this->paginator($response->getTotalResults(), $page);
        $view->setVariable('imports', $response->getContent());
        return $view;
    }

    protected function undoJob($jobId)
    {
        $response = $this->api()->search('archivematica_imports', ['job_id' => $jobId]);
        $archivematicaImport = $response->getContent()[0];
        // Get original import job args
        $deleteData = $archivematicaImport->job()->args();
        $deleteData['previous_job'] = $jobId;
        $job = $this->jobDispatcher()->dispatch('ArchivematicaConnector\Job\Undo', $deleteData);
        $this->api()->update('archivematica_imports', $archivematicaImport->id(), [
            'o:undo_job' => ['o:id' => $job->getId()],
        ]);
        return $job;
    }
}
