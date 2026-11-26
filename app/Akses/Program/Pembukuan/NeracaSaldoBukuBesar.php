<?php

use App\Support\HakAkses;

/*
 * Hak akses program "Neraca Saldo & Buku Besar" — kelompok Pembukuan (urutan & judul: _kelompok.php).
 * Buku Besar ikut dibuka untuk pembaca laporan: rincian akun di Laba Rugi / Neraca menautkan ke sini.
 * Format & pedoman: App\Support\ProgramAkses, CLAUDE.md § Hak akses.
 */

return [
    'nama' => 'Neraca Saldo & Buku Besar',
    'menu' => [
        ['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 6, 'route' => 'buku-besar.neraca-saldo', 'title' => 'Neraca Saldo', 'desc' => 'Saldo awal, mutasi & saldo akhir tiap akun per periode - cek debit = kredit', 'roles' => HakAkses::AWAL_PEMBUKUAN, 'badge' => 'NS'],
        ['group' => 'Pembukuan', 'groupOrder' => 1, 'order' => 7, 'route' => 'buku-besar.buku-besar', 'title' => 'Buku Besar', 'desc' => 'Mutasi & saldo berjalan per akun, rinci sampai transaksi SIRUS', 'roles' => HakAkses::AWAL_LAPORAN, 'badge' => 'BB'],
    ],
];
