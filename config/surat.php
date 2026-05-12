<?php

return [
    'global_paraf_path' => resource_path('system/paraf.png'),

    'template_beasiswa_id' => env('TEMPLATE_BEASISWA_ID', '1wnQYvwVO45M3LDDLEitsfjMFgkwj9S7f'),

    'template_beasiswa_cache_path' => env('TEMPLATE_BEASISWA_CACHE_PATH', storage_path('app/templates/beasiswa_template.docx')),

    'types' => [
        [
            'key' => 'surat-keterangan-aktif',
            'label' => 'Surat Keterangan Aktif',
            'category' => 'administrasi',
            'legacy_keys' => ['aktif'],
        ],
        [
            'key' => 'surat-pengantar-magang',
            'label' => 'Surat Pengantar Magang',
            'category' => 'administrasi',
            'legacy_keys' => ['magang'],
        ],
        [
            'key' => 'surat-permohonan-beasiswa',
            'label' => 'Surat Permohonan Beasiswa',
            'category' => 'administrasi',
            'legacy_keys' => [
                'beasiswa',
                'Beasiswa',
                'Surat Beasiswa',
                'Surat Permohonan Beasiswa',
            ],
        ],
        [
            'key' => 'proses-luar-negeri',
            'label' => 'Proses Luar Negeri',
            'category' => 'administrasi',
            'legacy_keys' => ['luar_negeri'],
        ],
    ],
];
