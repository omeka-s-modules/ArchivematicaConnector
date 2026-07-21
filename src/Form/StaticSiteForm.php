<?php
namespace ArchivematicaConnector\Form;

use Laminas\Form\Element\Checkbox;

class StaticSiteForm extends \StaticSiteExport\Form\StaticSiteForm
{
    public function init()
    {
        parent::init();
        $this->add([
            'type' => Checkbox::class,
            'name' => 'package_as_archivematica_sip',
            'options' => [
                'label' => 'Also package as Archivematica SIP', // @translate
                'info' => 'After static site export completes, package as an Archivematica Submission Information Package (AM_SIP.zip) and save alongside site archive .zip.', // @translate
            ],
            'attributes' => [
                'id' => 'package-as-archivematica-sip',
            ],
        ]);
    }
}
