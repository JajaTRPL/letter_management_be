<?php

return [
    'global_paraf_path' => resource_path('system/paraf.png'),

    // Template IDs rotated after the previous Google Docs template folder was
    // exposed. Old IDs are deprecated. NOTE: rotating an ID has no runtime effect
    // until the cache DOCX is refreshed (Super Admin → templates refresh), which
    // re-exports from the new Google Doc. Generation reads the cached DOCX.
    // Not read from env: a stale TEMPLATE_BEASISWA_ID on the server pinned the
    // old Google Doc. Change this ID in code to rotate the beasiswa template.
    'template_beasiswa_id' => '1ANPD3ITEV7scT4j6W6A3v1gJcjcAfD-Z',

    'template_beasiswa_cache_path' => env('TEMPLATE_BEASISWA_CACHE_PATH', storage_path('app/templates/beasiswa_template.docx')),

    'template_surat_keterangan_aktif_id' => env('TEMPLATE_SURAT_KETERANGAN_AKTIF_ID', '1b9wfBVcynlcXOGHVFSz2v__Koi1-QGvy'),

    'template_surat_keterangan_aktif_cache_path' => env('TEMPLATE_SURAT_KETERANGAN_AKTIF_CACHE_PATH', storage_path('app/templates/surat_keterangan_aktif_template.docx')),

    'template_proses_luar_negeri_id' => env('TEMPLATE_PROSES_LUAR_NEGERI_ID', '1qy27jELHgkY0K8KjNBLlb29-iX1Fs6ND'),

    'template_proses_luar_negeri_cache_path' => env('TEMPLATE_PROSES_LUAR_NEGERI_CACHE_PATH', storage_path('app/templates/proses_luar_negeri_template.docx')),

    'template_surat_pengantar_magang_id' => env('TEMPLATE_SURAT_PENGANTAR_MAGANG_ID', '13v_Xh0b2dwfmwwascc0dH77wJb87GzyK'),

    'template_surat_pengantar_magang_cache_path' => env('TEMPLATE_SURAT_PENGANTAR_MAGANG_CACHE_PATH', storage_path('app/templates/surat_pengantar_magang_template.docx')),

    // DORMANT — Surat Tugas template ID, captured here so the rotated ID lives in
    // one canonical place ahead of the S2 standalone Surat Tugas build. NOT wired
    // into MANAGED_TEMPLATES, any service, or runtime, and NOT exported/cached yet.
    // Do not consume this key until S2 implements the Surat Tugas pipeline.
    'template_surat_tugas_id' => env('TEMPLATE_SURAT_TUGAS_ID', '1wVckCGjgtH6I6tNHt_lN1A2y-NpbjuE1'),

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
