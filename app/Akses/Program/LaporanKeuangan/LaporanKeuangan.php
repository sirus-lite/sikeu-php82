<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Laporan Keuangan" — kelompok Laporan Keuangan (urutan & judul: _kelompok.php).
 * Format & pedoman: App\Support\ProgramAkses, CLAUDE.md § Hak akses.
 */

return [
    'nama' => 'Laporan Keuangan',
    'menu' => [
        ['group' => 'Laporan Keuangan', 'groupOrder' => 2, 'order' => 1, 'route' => 'laporan.laba-rugi', 'title' => 'Laba Rugi', 'desc' => 'Laporan laba rugi bulan berjalan & kumulatif - susunan dari template laporan', 'roles' => HakAkses::AWAL_LAPORAN, 'badge' => 'LR'],
        ['group' => 'Laporan Keuangan', 'groupOrder' => 2, 'order' => 2, 'route' => 'laporan.neraca', 'title' => 'Neraca', 'desc' => 'Laporan posisi keuangan per akhir bulan vs 1 Januari - cek Aset = Liabilitas + Ekuitas', 'roles' => HakAkses::AWAL_LAPORAN, 'badge' => 'NRC'],
    ],
];
