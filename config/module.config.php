<?php
return [
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => OMEKA_PATH . '/modules/ArchivematicaConnector/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'archivematica_items' => 'ArchivematicaConnector\Api\Adapter\ArchivematicaItemAdapter',
            'archivematica_imports' => 'ArchivematicaConnector\Api\Adapter\ArchivematicaImportAdapter',
        ],
    ],
    'controllers' => [
        'invokables' => [
            'ArchivematicaConnector\Controller\Index' => 'ArchivematicaConnector\Controller\IndexController',
        ],
        'factories' => [
            'ArchivematicaConnector\Controller\Sword' => 'ArchivematicaConnector\Service\Controller\SwordControllerFactory',
        ],
    ],
    'service_manager' => [
        'factories' => [
            'ArchivematicaConnector\Sword\MetsImporter' => 'ArchivematicaConnector\Service\Sword\MetsImporterFactory',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            OMEKA_PATH . '/modules/ArchivematicaConnector/view',
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            OMEKA_PATH . '/modules/ArchivematicaConnector/src/Entity',
        ],
        'proxy_paths' => [
            OMEKA_PATH . '/modules/ArchivematicaConnector/data/doctrine-proxies',
        ],
    ],
    'exports_module' => [
        'exporters' => [
            'factories' => [
                'archivematica' => 'ArchivematicaConnector\Service\Exporter\ArchivematicaFactory',
            ],
        ],
    ],
    'form_elements' => [
        'factories' => [
            'ArchivematicaConnector\Form\ImportForm' => 'ArchivematicaConnector\Service\Form\ImportFormFactory',
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'archivematica-connector' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/archivematica-connector',
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'import' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/import',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'ArchivematicaConnector\Controller',
                                        'controller' => 'Index',
                                        'action' => 'import',
                                    ],
                                ],
                            ],
                            'past-imports' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/past-imports',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'ArchivematicaConnector\Controller',
                                        'controller' => 'Index',
                                        'action' => 'past-imports',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'archivematica-sword' => [
            'type' => 'Segment',
            'options' => [
                'route'    => '/sword/deposit[/:target]',
                'defaults' => [
                    'controller' => 'ArchivematicaConnector\Controller\Sword',
                    'action'     => 'deposit',
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Archivematica Connector', // @translate
                'route' => 'admin/archivematica-connector/past-imports',
                'resource' => 'ArchivematicaConnector\Controller\Index',
                'pages' => [
                    [
                        'label' => 'Import', // @translate
                        'route' => 'admin/archivematica-connector',
                        'resource' => 'ArchivematicaConnector\Controller\Index',
                    ],
                    [
                        'label' => 'Past Imports', // @translate
                        'route' => 'admin/archivematica-connector/past-imports',
                        'controller' => 'Index',
                        'action' => 'past-imports',
                        'resource' => 'ArchivematicaConnector\Controller\Index',
                    ],
                ],
            ],
        ],
    ],
];
