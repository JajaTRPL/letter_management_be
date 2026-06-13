<?php

return [
    'global_paraf_path' => resource_path('system/paraf.png'),

    // Template IDs rotated after the previous Google Docs template folder was
    // exposed. Old IDs are deprecated. NOTE: rotating an ID has no runtime effect
    // until the cache DOCX is refreshed (Super Admin → templates refresh), which
    // re-exports from the new Google Doc. Generation reads the cached DOCX.
    'template_beasiswa_id' => env('TEMPLATE_BEASISWA_ID', '1QeM5eAy2KaNiAS-q6jiD88rme2iPnomh'),

    'template_beasiswa_cache_path' => env('TEMPLATE_BEASISWA_CACHE_PATH', storage_path('app/templates/beasiswa_template.docx')),

    'template_surat_keterangan_aktif_id' => env('TEMPLATE_SURAT_KETERANGAN_AKTIF_ID', '15PyIqVO1X1xkLT1HwaFqrRdC7xm2NObu'),

    'template_surat_keterangan_aktif_cache_path' => env('TEMPLATE_SURAT_KETERANGAN_AKTIF_CACHE_PATH', storage_path('app/templates/surat_keterangan_aktif_template.docx')),

    'template_proses_luar_negeri_id' => env('TEMPLATE_PROSES_LUAR_NEGERI_ID', '1oO7gq579l-PlKaNfo53Wh6iPPBLeCEhD'),

    'template_proses_luar_negeri_cache_path' => env('TEMPLATE_PROSES_LUAR_NEGERI_CACHE_PATH', storage_path('app/templates/proses_luar_negeri_template.docx')),

    'template_surat_pengantar_magang_id' => env('TEMPLATE_SURAT_PENGANTAR_MAGANG_ID', '1LMT7SqOB7rMKVI0ceOIe8Tu7cjB6zCa2'),

    'template_surat_pengantar_magang_cache_path' => env('TEMPLATE_SURAT_PENGANTAR_MAGANG_CACHE_PATH', storage_path('app/templates/surat_pengantar_magang_template.docx')),

    // DORMANT — Surat Tugas template ID, captured here so the rotated ID lives in
    // one canonical place ahead of the S2 standalone Surat Tugas build. NOT wired
    // into MANAGED_TEMPLATES, any service, or runtime, and NOT exported/cached yet.
    // Do not consume this key until S2 implements the Surat Tugas pipeline.
    'template_surat_tugas_id' => env('TEMPLATE_SURAT_TUGAS_ID', '1BHIzQotYzavkmvikVysuzGXWdggY_GBW'),

    'template_surat_tugas_cache_path' => env('TEMPLATE_SURAT_TUGAS_CACHE_PATH', storage_path('app/templates/surat_tugas_template.docx')),

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
        [
            'key' => 'surat-tugas',
            'label' => 'Surat Tugas',
            'category' => 'administrasi',
            'legacy_keys' => [],
        ],
    ],
];
