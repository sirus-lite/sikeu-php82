<?php

use App\Support\HakAkses;

/*
 * Program "Panduan" — kelompok Sistem. Terbuka untuk semua user login (HakAkses::SEMUA_ROLE): tak ada centang.
 * Format & pedoman: App\Support\ProgramAkses, CLAUDE.md § Hak akses.
 */

return [
    'nama' => 'Panduan',
    'menu' => [
        ['group' => 'Panduan', 'groupOrder' => 4, 'order' => 1, 'route' => 'panduan.cara-pakai', 'title' => 'Cara Pakai SIKEU', 'desc' => 'Langkah tutup bulan, aturan kunci / buka kunci berurutan, SIRUS berubah setelah dikunci, arti warna tombol, siapa boleh apa', 'roles' => [HakAkses::SEMUA_ROLE], 'badge' => '?'],
        ['group' => 'Panduan', 'groupOrder' => 4, 'order' => 2, 'route' => 'panduan.istilah-keuangan', 'title' => 'Istilah Keuangan', 'desc' => 'Glosarium: debit/kredit, aset, liabilitas, ekuitas, laba rugi, akrual, istilah SIKEU - dengan contoh rumah sakit', 'roles' => [HakAkses::SEMUA_ROLE], 'badge' => '?'],
        ['group' => 'Panduan', 'groupOrder' => 4, 'order' => 3, 'route' => 'panduan.struktur-data', 'title' => 'Struktur Data', 'desc' => 'Gambar tabel SIKEU dan relasinya (foreign key) + kolom per tabel, dibaca langsung dari database', 'roles' => [HakAkses::SEMUA_ROLE], 'badge' => 'SD'],
    ],
];
