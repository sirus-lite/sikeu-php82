<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Bagan & Peta Akun" — kelompok Data Induk (urutan & judul: _kelompok.php).
 * Lihat = membuka halamannya; mengubah isi = aksi khusus akun.kelola.
 * Format & pedoman: App\Support\ProgramAkses, CLAUDE.md § Hak akses.
 */

return [
    'nama' => 'Bagan & Peta Akun',
    'menu' => [
        ['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 1, 'route' => 'master.format-kode', 'title' => 'Format Kode Akun', 'desc' => 'Jumlah tingkat, digit per tingkat, pemisah & nomor akun kontra', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'FK'],
        ['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 2, 'route' => 'master.kelompok-akun', 'title' => 'Kelompok Akun', 'desc' => 'Pembagian akun Neraca & Laba Rugi per kelompok (tingkat 1 kode akun)', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'KA'],
        ['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 3, 'route' => 'master.bagan-akun', 'title' => 'Bagan Akun', 'desc' => 'Daftar akun buku besar (SAK EP): tambah, ubah, nonaktifkan akun', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'BA'],
        ['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 4, 'route' => 'master.peta-akun', 'title' => 'Peta Akun SIRUS', 'desc' => 'Pasangan akun SIRUS ke akun SIKEU - pengesahan ditolak bila ada yang belum dipetakan', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'PA'],
        ['group' => 'Data Induk', 'groupOrder' => 3, 'order' => 5, 'route' => 'master.susunan-laporan', 'title' => 'Susunan Laporan', 'desc' => 'Template baris Laba Rugi & Neraca: Umum / Internal, salin, atur baris & akun, pemeriksaan akun yatim / dobel', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'SL'],
    ],
    'aksiKhusus' => [
        'akun.kelola' => ['label' => 'Ubah format kode, kelompok, bagan akun, peta akun SIRUS & susunan laporan', 'roles' => HakAkses::AWAL_PENGESAH],
    ],
];
