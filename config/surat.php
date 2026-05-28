<?php

return [
    'global_paraf_path' => resource_path('system/paraf.png'),

    'template_beasiswa_id' => env('TEMPLATE_BEASISWA_ID', '1wnQYvwVO45M3LDDLEitsfjMFgkwj9S7f'),

    'template_beasiswa_cache_path' => env('TEMPLATE_BEASISWA_CACHE_PATH', storage_path('app/templates/beasiswa_template.docx')),

    'template_surat_keterangan_aktif_id' => env('TEMPLATE_SURAT_KETERANGAN_AKTIF_ID', '1cmq201-FtDBBCtxNMiRqtxxs1IHhH7mP'),

    'template_surat_keterangan_aktif_cache_path' => env('TEMPLATE_SURAT_KETERANGAN_AKTIF_CACHE_PATH', storage_path('app/templates/surat_keterangan_aktif_template.docx')),

    'template_proses_luar_negeri_id' => env('TEMPLATE_PROSES_LUAR_NEGERI_ID', '1eLZ_W2GM-eCeOsNUzVpOUp8encHAQixC'),

    'template_proses_luar_negeri_cache_path' => env('TEMPLATE_PROSES_LUAR_NEGERI_CACHE_PATH', storage_path('app/templates/proses_luar_negeri_template.docx')),

    'template_surat_pengantar_magang_id' => env('TEMPLATE_SURAT_PENGANTAR_MAGANG_ID', '1WiUn6DhUzNx74vLE_vfc2BEMx42tWaCn'),

    'template_surat_pengantar_magang_cache_path' => env('TEMPLATE_SURAT_PENGANTAR_MAGANG_CACHE_PATH', storage_path('app/templates/surat_pengantar_magang_template.docx')),

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
