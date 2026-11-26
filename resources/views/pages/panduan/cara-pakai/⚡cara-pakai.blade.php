<?php

use Livewire\Component;
use Spatie\Permission\Models\Role;
use App\Support\AksesHalaman;
use App\Support\HakAkses;
use App\Support\ProgramAkses;

/**
 * PANDUAN — CARA PAKAI SIKEU (statis, tanpa mutasi). Isinya sama dengan skill keuangan-rs §5 (alur tutup bulan, aturan
 * kunci/buka kunci, warna tombol) — ubah keduanya bersamaan. Bagian "Siapa boleh apa" dibaca langsung dari peran &
 * permission (Spatie) supaya tidak basi bila hak akses diubah di Sistem › Peran & Hak Akses. Terbuka untuk semua user login.
 */
new class extends Component {
    /**
     * Hak per program (berkas app/Akses/Program), urut seperti Role Control: lihat halaman lalu aksi khusus.
     *
     * @return array<string, array<string, array{label: string, peran: array<int, string>}>> judul "Kelompok › Program" => hak
     */
    public function hakAkses(): array
    {
        $perIzin = [];
        foreach (Role::with('permissions')->orderBy('name')->get() as $peran) {
            foreach ($peran->permissions as $izin) {
                $perIzin[$izin->name][] = $peran->name;
            }
        }
        $peranDari = function (string $izin) use ($perIzin): array {
            $peranList = $perIzin[$izin] ?? [];
            if (!in_array(HakAkses::PERAN_SUPER, $peranList, true)) {
                array_unshift($peranList, HakAkses::PERAN_SUPER);
            }

            return array_values(array_unique($peranList));
        };

        $hasil = [];
        foreach (ProgramAkses::kelompok() as $kelompok) {
            foreach ($kelompok['program'] as $namaProgram => $definisi) {
                $hak = [];
                foreach ($definisi['menu'] ?? [] as $m) {
                    if (in_array(HakAkses::SEMUA_ROLE, $m['roles'], true)) {
                        continue;
                    }
                    $izin = AksesHalaman::namaPermission($m['route'], 'lihat');
                    $hak[$izin] = ['label' => 'Lihat halaman ' . $m['title'], 'peran' => $peranDari($izin)];
                }
                foreach ($definisi['aksiKhusus'] ?? [] as $izin => $aksi) {
                    $hak[$izin] = ['label' => $aksi['label'], 'peran' => $peranDari($izin)];
                }
                if ($hak) {
                    $hasil[$kelompok['judul'] . ' › ' . $namaProgram] = $hak;
                }
            }
        }

        return $hasil;
    }
};
?>

@php
    $tautan = fn (string $route, string $label, array $parameter = []) => \Illuminate\Support\Facades\Route::has($route)
        ? '<a href="' . route($route, $parameter) . '" wire:navigate class="font-semibold underline text-brand-green hover:no-underline dark:text-brand-lime">' . e($label) . '</a>'
        : '<b>' . e($label) . '</b>';
    $bagian = [
        'alur' => 'Gambaran alur',
        'awal' => '1. Pemasangan awal (sekali)',
        'bulanan' => '2. Tutup bulan (rutin)',
        'kunci' => '3. Kunci & buka kunci',
        'setelah-kunci' => '4. SIRUS berubah setelah dikunci?',
        'tutup-buku' => '4a. Tutup buku tahunan',
        'set-ulang' => '4b. Memulai dari tahun lain',
        'kasus' => '4c. Kasus khusus (bertahap)',
        'warna' => '5. Arti warna tombol',
        'hak' => '6. Siapa boleh apa',
        'tanya' => 'Tanya jawab',
    ];
@endphp

<div>
    <x-page-title title="Cara Pakai SIKEU" subtitle="Alur pembukuan dari data SIRUS sampai laporan keuangan — langkah demi langkah, aturan kunci periode, arti warna tombol" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-6 px-6 py-6 mx-auto max-w-7xl lg:grid-cols-4">

            {{-- DAFTAR ISI --}}
            <nav class="lg:sticky lg:top-24 lg:self-start" aria-label="Daftar isi panduan">
                <div class="p-4 border bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <p class="mb-2 text-xs font-semibold tracking-wide uppercase text-muted">Daftar isi</p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($bagian as $id => $judul)
                            <li><a href="#{{ $id }}" class="block px-2 py-1 rounded-lg text-body hover:bg-surface-soft hover:text-brand-green dark:text-gray-300">{{ $judul }}</a></li>
                        @endforeach
                    </ul>
                    <p class="pt-3 mt-3 text-xs border-t border-hairline text-muted">
                        Lihat juga: {!! $tautan('panduan.istilah-keuangan', 'Istilah Keuangan') !!}
                    </p>
                </div>
            </nav>

            {{-- ISI --}}
            <article class="space-y-6 lg:col-span-3 text-body dark:text-gray-300 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-ink dark:[&_h2]:text-gray-100 [&_h3]:mt-4 [&_h3]:font-semibold [&_h3]:text-ink dark:[&_h3]:text-gray-100 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:space-y-1.5 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">

                {{-- ALUR --}}
                <section id="alur" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Gambaran alur</h2>
                    <p class="mt-2 text-sm">Transaksi pelayanan tetap dikerjakan di SIRUS (kasir, apotek, penerimaan PBF). SIKEU menarik jurnalnya, membukukannya, lalu menyusun laporan keuangan.</p>
                    <ol class="grid grid-cols-1 gap-2 mt-4 !list-none !pl-0 text-sm sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ([
                            ['Tarik dari SIRUS', 'Salinan jurnal SIRUS satu bulan (versi)', 'buku-besar.periode'],
                            ['Sahkan & Posting', 'Versi yang dipakai masuk buku besar', 'buku-besar.periode'],
                            ['Jurnal Umum', 'Penyesuaian & koreksi akhir bulan', 'buku-besar.jurnal-umum'],
                            ['Neraca Saldo', 'Periksa debit = kredit & saldo janggal', 'buku-besar.neraca-saldo'],
                            ['Kunci', 'Bulan dinyatakan final', 'buku-besar.periode'],
                            ['Laporan', 'Neraca & Laba Rugi', 'laporan.neraca'],
                        ] as $nomor => [$judul, $isi, $route])
                            <li class="flex gap-3 p-3 border rounded-xl border-hairline dark:border-gray-700">
                                <span class="inline-flex items-center justify-center w-7 h-7 text-sm font-bold rounded-full shrink-0 bg-surface-strong text-brand-green dark:bg-gray-800 dark:text-brand-lime">{{ $nomor + 1 }}</span>
                                <span>
                                    <span class="block font-semibold text-ink dark:text-gray-100">{!! $tautan($route, $judul) !!}</span>
                                    <span class="block text-xs text-muted dark:text-gray-400">{{ $isi }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </section>

                {{-- AWAL --}}
                <section id="awal" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>1. Pemasangan awal (sekali)</h2>
                    <ol class="mt-3 text-sm">
                        <li>Periksa {!! $tautan('master.peta-akun', 'Data Induk › Peta Akun SIRUS') !!}: status <b>Perlu perhatian</b> harus 0 — setiap akun SIRUS punya pasangan akun SIKEU.</li>
                        <li>{!! $tautan('buku-besar.pengaturan', 'Pembukuan › Pengaturan Pembukuan') !!} (menu paling atas): periksa <b>tahun pertama SIKEU</b> dan akun saldo laba, lalu <b>Buka Tahun Buku</b> tahun pertama (membuat 12 periode).</li>
                        <li>Isi {!! $tautan('buku-besar.saldo-awal', 'Pembukuan › Saldo Awal') !!} <b>hanya untuk tahun pertama SIKEU</b>: ketik saldo tiap akun per 1 Januari dari neraca penutup / audit, rekening koran, dan opname → <b>Simpan Draft</b> (boleh bertahap) → <b>Sahkan</b> (wajib seimbang). Saldo awal <b>tidak</b> diambil dari SIRUS — SIKEU sumber kebenaran keuangan.</li>
                        <li>Tahun sebelum tahun pertama (mis. 2025) <b>hanya untuk dicek</b>: boleh Tarik dari SIRUS &amp; Bandingkan Versi, tetapi tidak bisa disahkan, diberi jurnal umum, atau dikunci — saldonya sudah tercakup di saldo awal tahun pertama.</li>
                        <li>Tahun-tahun berikutnya <b>tidak diisi</b>: saldo akhir Desember diteruskan otomatis jadi saldo awal Januari. Mengisinya lagi = terhitung dua kali, jadi ditolak.</li>
                        <li>Pekerjaan yang dikerjakan sendiri di SIKEU (mis. HPP taksiran, penyusutan, utang di luar SIRUS): matikan <b>Masuk pembukuan</b> akun SIRUS-nya di {!! $tautan('master.peta-akun', 'Peta Akun SIRUS') !!} supaya tidak tercatat dua kali. Seluruh transaksi akun itu (kedua sisinya) tidak diposting — layar menampilkan dampaknya sebelum dimatikan.</li>
                    </ol>
                </section>

                {{-- BULANAN --}}
                <section id="bulanan" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>2. Tutup bulan (rutin, tiap awal bulan)</h2>
                    <ol class="mt-3 text-sm">
                        <li>{!! $tautan('buku-besar.periode', 'Tarik Data SIRUS') !!} → baris bulan lalu → <b>Tarik dari SIRUS</b> (sekitar 40–70 detik; biarkan halaman terbuka). Banyak bulan sekaligus: <b>Tarik &amp; Sahkan Setahun</b>.</li>
                        <li>Bila bulan itu sudah pernah ditarik, lihat kolom <b>Versi Disahkan</b>: <i>sama</i> = SIRUS tidak berubah; <i>berbeda</i> = klik tandanya untuk melihat transaksi yang berubah di {!! $tautan('cutoff.banding-versi', 'Bandingkan Versi') !!}.</li>
                        <li><b>Sahkan &amp; Posting</b> versi yang dipakai (pemeriksaan peta akun tampil di jendela pengesahan).</li>
                        <li>Buat jurnal penyesuaian di {!! $tautan('buku-besar.jurnal-umum', 'Jurnal Umum') !!}: jasa medis dokter terutang (rekap SIAP), pendapatan pasien rawat inap yang masih dirawat, listrik/air terutang, penyusutan, koreksi salah akun. Staf menyimpan <b>draft</b>, manajer <b>memposting</b>.</li>
                        <li>Periksa {!! $tautan('buku-besar.neraca-saldo', 'Neraca Saldo') !!}: badge <b>Seimbang</b>, dan klik badge <b>saldo berlawanan</b> untuk melihat akun yang janggal (piutang/persediaan bersaldo kredit). Klik akun untuk melihat mutasinya sampai transaksi SIRUS.</li>
                        <li>Rekonsiliasi: kas & bank dengan rekening koran, persediaan dengan kartu stok SIRUS, utang PBF per supplier.</li>
                        <li>Baca {!! $tautan('laporan.laba-rugi', 'Laba Rugi') !!} dan {!! $tautan('laporan.neraca', 'Neraca') !!} (status <i>Sementara</i>) → review manajer keuangan. Pilih <b>Template</b> di atas laporan: <i>Umum (SAK EP)</i> untuk pihak luar, <i>Internal (Manajemen)</i> rinci per instalasi untuk rapat direksi — totalnya selalu sama. Susunan barisnya diatur di {!! $tautan('master.susunan-laporan', 'Data Induk › Susunan Laporan') !!}. Neraca bisa ditampilkan <b>Bertingkat</b> atau <b>Kanan-kiri</b> (dicetak mendatar). Baris jumlah yang <b>merah</b> = tidak seimbang / laba tidak cocok — kotak merah di atas laporan menyebut penyebabnya (jurnal bermasalah atau susunan template).</li>
                        <li><b>Kunci</b> bulan itu di Tarik Data SIRUS — laporan menjadi <i>Final</i>.</li>
                    </ol>
                </section>

                {{-- KUNCI --}}
                <section id="kunci" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>3. Kunci &amp; buka kunci — selalu berurutan</h2>
                    <div class="grid grid-cols-1 gap-4 mt-4 sm:grid-cols-2">
                        <div class="p-4 border rounded-xl border-hairline dark:border-gray-700">
                            <p class="flex items-center gap-2 font-semibold text-ink dark:text-gray-100"><x-ikon nama="kunci" /> Kunci: dari atas ke bawah</p>
                            <p class="mt-2 font-mono text-sm">01 → 02 → 03 → … → 12</p>
                            <p class="mt-2 text-xs text-muted dark:text-gray-400">Hanya bulan Buka <b>terlama</b> yang bisa dikunci, dan harus sudah diposting. Berlaku lintas tahun (Desember 2025 sebelum Januari 2026).</p>
                        </div>
                        <div class="p-4 border rounded-xl border-hairline dark:border-gray-700">
                            <p class="flex items-center gap-2 font-semibold text-ink dark:text-gray-100"><x-ikon nama="buka-kunci" /> Buka kunci: dari bawah ke atas</p>
                            <p class="mt-2 font-mono text-sm">12 → 11 → 10 → … → 01</p>
                            <p class="mt-2 text-xs text-muted dark:text-gray-400">Hanya bulan terkunci <b>terbaru</b> yang bisa dibuka, dengan alasan yang tercatat di log audit.</p>
                        </div>
                    </div>
                    <h3>Kenapa harus berurutan?</h3>
                    <p class="text-sm">Saldo awal tiap bulan adalah saldo akhir bulan sebelumnya. Bila Juli dikunci sementara Juni masih bisa berubah, saldo Juli yang sudah "final" ikut bergeser — laporan final tidak bisa dipercaya.</p>
                    <h3>Kenapa tombol Kunci saya abu-abu?</h3>
                    <p class="text-sm">Belum gilirannya. Keterangan di bawah tombol menjelaskan bulan mana yang harus dikunci / diposting lebih dulu, mis. <i>"kunci 01/2026 dulu"</i> atau <i>"02/2026 belum diposting"</i>.</p>
                    <h3>Setelah dikunci</h3>
                    <ul class="text-sm">
                        <li>Tidak bisa Sahkan / Ganti Versi / jurnal umum baru di bulan itu.</li>
                        <li>Masih bisa <b>Tarik ulang</b> untuk mengecek perubahan di SIRUS (buku tidak berubah) dan melihat laporan.</li>
                    </ul>
                </section>

                {{-- SETELAH KUNCI --}}
                <section id="setelah-kunci" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4. SIRUS berubah setelah bulan dikunci?</h2>
                    <ol class="mt-3 text-sm">
                        <li>Di Tarik Data SIRUS, baris bulan terkunci → <b>Tarik ulang</b>.</li>
                        <li>Kolom Versi Disahkan: <span class="font-medium text-fg-success-strong">sama — SIRUS tidak berubah</span> (selesai), atau <span class="inline-flex items-center gap-1 px-1.5 rounded bg-warning-soft text-fg-warning"><x-ikon-peringatan /> berbeda</span> → klik untuk melihat transaksi yang berubah.</li>
                        <li>Bila berbeda, pilih salah satu:
                            <ul class="mt-1">
                                <li><b>Disarankan</b> — biarkan terkunci; bukukan selisihnya lewat {!! $tautan('buku-besar.jurnal-umum', 'Jurnal Umum') !!} di bulan yang masih Buka (jurnal koreksi). Laporan yang sudah final tetap utuh.</li>
                                <li>Bila memang harus diganti — <b>Buka Kunci</b> (dari bulan terbaru, beralasan) → <b>Ganti Versi</b> → <b>Kunci</b> lagi berurutan.</li>
                            </ul>
                        </li>
                    </ol>
                </section>

                {{-- TUTUP BUKU --}}
                <section id="tutup-buku" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4a. Tutup buku tahunan</h2>
                    <p class="mt-2 text-sm">Mengunci bulan <b>tidak</b> memindahkan laba. Selama tahun belum ditutup, laba tahun lalu dihitung otomatis: di Neraca Saldo tampil baris <i>laba tahun lalu belum ditutup buku</i>, dan di Neraca angkanya ditambahkan ke akun laba ditahan. Tutup buku menjadikannya jurnal sungguhan.</p>
                    <ol class="mt-3 text-sm">
                        <li>Selesaikan penyesuaian akhir tahun (penyusutan, akrual, pajak, koreksi audit) di {!! $tautan('buku-besar.jurnal-umum', 'Jurnal Umum') !!}, lalu kunci 01 s/d 12.</li>
                        <li>{!! $tautan('buku-besar.pengaturan', 'Pengaturan Pembukuan') !!} › Tahun buku → baris tahun itu (status <i>Terkunci penuh</i>) → <b>Tutup Buku</b> (hijau). Jendelanya menampilkan laba/rugi setahun dan akun laba ditahan tujuannya.</li>
                        <li>SIKEU memposting <b>jurnal penutup 31 Desember</b> (nomor <span class="font-mono">PENUTUP-YYYY12-NN</span>): tiap akun Laba Rugi dinolkan, laba/rugi masuk ke akun laba ditahan (bagian 3 Pengaturan Pembukuan). Tahun itu berstatus <b>Tutup</b>.</li>
                    </ol>
                    <ul class="mt-3 text-sm">
                        <li><b>Laporan tahun itu tidak berubah.</b> Laba Rugi, Neraca, Neraca Saldo, dan Buku Besar sampai Desember tetap menampilkan keadaan sebelum penutupan. Jurnal penutup baru terbaca mulai Januari tahun berikutnya, seperti saldo awal: baris <i>laba tahun lalu belum ditutup</i> hilang dan angkanya pindah ke akun laba ditahan. Total Neraca tidak berubah.</li>
                        <li><b>Berurutan:</b> tahun pertama SIKEU ditutup dulu, baru tahun berikutnya. Bulan di tahun berikutnya boleh sudah dikunci; saldo tersimpannya dihitung ulang otomatis.</li>
                        <li><b>Sesudah ditutup</b> bulan-bulan tahun itu tidak bisa dibuka kuncinya, diposting ulang, atau diberi jurnal umum. Koreksi sesudah tutup buku sebaiknya dibukukan di tahun berjalan.</li>
                        <li><b>Batal Tutup Buku</b> (kuning, wajib alasan, hanya tahun yang terakhir ditutup): jurnal penutup berstatus batal (tetap tersimpan), 12 bulan kembali Kunci. Setelah koreksi, jalankan Tutup Buku lagi.</li>
                    </ul>
                </section>

                {{-- SET ULANG --}}
                <section id="set-ulang" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4b. Memulai pembukuan dari tahun lain</h2>
                    <p class="mt-2 text-sm">Pembukuan bisa dimulai dari tahun berapa pun (mis. 2023 atau 2024) lewat {!! $tautan('buku-besar.pengaturan', 'Pengaturan Pembukuan') !!} › <b>Set Ulang</b> (tombol kuning, wajib alasan). Jendelanya menampilkan dampak sebelum disetujui.</p>
                    <ul class="mt-3 text-sm">
                        <li><b>Mundur</b> (mis. 2026 → 2024): saldo awal 2026 dibatalkan — 2026 nanti diteruskan dari Desember 2025. Posting yang ada tetap. Isi saldo awal 1 Januari 2024, buka &amp; proses 2024 dan 2025.</li>
                        <li><b>Maju</b> (mis. 2024 → 2026): posting &amp; saldo awal sebelum 2026 dibatalkan (hasil tarikan tetap, tahun itu jadi "hanya dicek"). <b>Ditolak</b> bila masih ada bulan terkunci sebelum 2026 — buka kuncinya dulu dari bulan terbaru.</li>
                        <li><b>Reset Tahun</b> (Pengaturan Pembukuan › Tahun buku, kuning, wajib alasan): mengulang pembukuan satu tahun — posting SIRUS dilepas (hasil tarikan tetap, tinggal Sahkan lagi), jurnal umum terposting kembali jadi draft, saldo awal tidak disentuh. Ditolak bila ada bulan terkunci di tahun itu atau sesudahnya. Tahun yang belum dipakai bisa <b>Hapus Tahun Buku</b> (kembali "Belum dibuka").</li>
                        <li><b>Kunci per tahun berurutan:</b> 01/2024 … 12/2024 harus terkunci dulu, baru 2025, baru 2026. Tahun yang belum dibuka tidak dilewati — "Kunci berikutnya" di Tarik Data SIRUS selalu menunjukkan gilirannya.</li>
                    </ul>
                </section>

                {{-- KASUS KHUSUS --}}
                <section id="kasus" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>4c. Kasus khusus — yang belum / tidak dicatat SIRUS (diperbaiki bertahap)</h2>
                    <p class="mt-2 text-sm">Aturan memutuskan: matikan akun SIRUS (sakelar <b>Masuk pembukuan</b> di {!! $tautan('master.peta-akun', 'Peta Akun SIRUS') !!}) <b>hanya bila SIKEU akan mencatat kejadian yang sama dengan cara lain</b>. Bila SIRUS memang tidak mencatatnya, jangan matikan apa pun — cukup tambahkan lewat {!! $tautan('buku-besar.jurnal-umum', 'Jurnal Umum') !!}.</p>

                    <h3>1. Dimatikan — dikerjakan sendiri di SIKEU</h3>
                    <div class="mt-2 overflow-x-auto border rounded-xl border-hairline dark:border-gray-700">
                        <table class="ds-table ds-table-entri">
                            <thead><tr><th>Akun SIRUS</th><th>Alasan &amp; cara di SIKEU</th></tr></thead>
                            <tbody>
                                <tr><td class="whitespace-nowrap">5.1K / 5.1K1 HPP RJ/UGD/Resep, 5.1K2 RI, 5.1K3 Klinik</td>
                                    <td>HPP ditaksir sendiri: tiap akhir bulan Jurnal Umum D HPP Obat / K Persediaan Apotek (penjualan obat × (1 − margin)), dikoreksi saat opname sampai saldo persediaan = hasil opname. Saldo awal persediaan wajib dari opname 31 Desember.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h3>2. Tetap dari SIRUS — JANGAN dimatikan (lawannya kas / piutang nyata)</h3>
                    <div class="mt-2 overflow-x-auto border rounded-xl border-hairline dark:border-gray-700">
                        <table class="ds-table ds-table-entri">
                            <thead><tr><th>Akun SIRUS</th><th>Lawannya</th><th>Bila dimatikan…</th></tr></thead>
                            <tbody>
                                <tr><td>1.1A07 / 1.1A08 Peralatan non medis / medis</td><td>Kas TU</td><td>Pembelian alat <b>dan uang keluarnya</b> hilang dari buku</td></tr>
                                <tr><td>5.1F01 Gaji Karyawan, 5.1F02* Gaji / Jasa Medis</td><td>Kas TU</td><td>Pembayaran gaji hilang, kas jadi terlalu besar</td></tr>
                                <tr><td>4.1I1 Keuntungan / 5.1K6 Kerugian Layanan BPJS</td><td>Kas + Piutang RJ/UGD/RI</td><td>Pelunasan klaim BPJS hilang, piutang tidak pernah turun</td></tr>
                                <tr><td>Pendapatan 4.1A–4.1G, persediaan, utang supplier, beban operasional</td><td>Kas / piutang / utang</td><td>Inti transaksi RS</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h3>3. Tidak ada di SIRUS — tambahkan lewat Jurnal Umum</h3>
                    <div class="mt-2 overflow-x-auto border rounded-xl border-hairline dark:border-gray-700">
                        <table class="ds-table ds-table-entri">
                            <thead><tr><th>Yang tidak ada di SIRUS</th><th>Cara di SIKEU</th></tr></thead>
                            <tbody>
                                <tr><td><b>Penyusutan aset</b> — tidak ada satu pun jurnal penyusutan di SIRUS</td><td>Jurnal bulanan D Beban Penyusutan / K Akumulasi Penyusutan (nanti modul Aset Tetap)</td></tr>
                                <tr><td><b>Pendapatan pasien RI yang masih dirawat</b> — SIRUS baru mengakui saat pasien pulang</td><td>Jurnal akrual akhir bulan (D Piutang RI / K Pendapatan RI), dibalik di bulan berikutnya</td></tr>
                                <tr><td><b>Jasa medis / tagihan yang belum dibayar</b> (listrik, air, jasa dokter bulan ini)</td><td>Jurnal akrual: D Beban / K Utang</td></tr>
                                <tr><td><b>Bunga &amp; biaya admin bank, PPh badan, cicilan pinjaman</b></td><td>Jurnal Umum (akun 7.x / 8.x / 9.x)</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h3>4. Perlu diputuskan nanti — jangan dimatikan sekarang</h3>
                    <ul class="text-sm">
                        <li><b>Keuntungan / Kerugian BPJS (4.1I1 &amp; 5.1K6):</b> SIRUS mencatat selisih tarif saat klaim dibayar, bukan saat pelayanan. Bila nanti SIKEU membukukan klaim BPJS per bulan pelayanan dari data E-Klaim, barulah dua akun ini dimatikan. Sebelum itu, biarkan.</li>
                        <li><b>Gaji &amp; jasa medis:</b> bila nanti SIKEU membaca rekap penggajian dari SIAP, pastikan tidak dobel dengan pembayaran gaji di SIRUS — matikan salah satu, jangan keduanya.</li>
                    </ul>
                </section>

                {{-- WARNA --}}
                <section id="warna" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>5. Arti warna tombol</h2>
                    <p class="mt-2 text-sm">Di tiap baris hanya langkah berikutnya yang berwarna hijau — ikuti tombol hijau.</p>
                    <div class="mt-4 space-y-3 text-sm">
                        <div class="flex flex-wrap items-center gap-3"><x-primary-button type="button" tabindex="-1" class="pointer-events-none"><x-ikon nama="setujui" /> Sahkan &amp; Posting</x-primary-button> <span>Hijau = langkah berikutnya yang perlu dikerjakan (Sahkan, Kunci di bulan gilirannya, Tarik &amp; Sahkan Setahun).</span></div>
                        <div class="flex flex-wrap items-center gap-3"><x-secondary-button type="button" tabindex="-1" class="pointer-events-none"><x-ikon nama="muat-ulang" /> Tarik ulang</x-secondary-button> <span>Abu = aksi rutin atau alternatif (Tarik dari SIRUS, Tarik ulang, Ganti Versi).</span></div>
                        <div class="flex flex-wrap items-center gap-3"><x-outline-button type="button" tabindex="-1" class="pointer-events-none"><x-ikon nama="lihat" /> Neraca Saldo</x-outline-button> <span>Garis = hanya melihat.</span></div>
                        <div class="flex flex-wrap items-center gap-3"><x-warning-button type="button" tabindex="-1" class="pointer-events-none"><x-ikon nama="buka-kunci" /> Buka Kunci</x-warning-button> <span>Kuning = peringatan, perlu alasan.</span></div>
                        <div class="flex flex-wrap items-center gap-3"><x-secondary-button type="button" disabled><x-ikon nama="kunci" /> Kunci</x-secondary-button> <span>Pudar = belum bisa dipakai; alasannya tertulis di bawah tombol.</span></div>
                    </div>
                </section>

                {{-- HAK AKSES --}}
                <section id="hak" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>6. Siapa boleh apa</h2>
                    <p class="mt-2 text-sm">Dibaca langsung dari pengaturan {!! $tautan('sistem.role-control', 'Sistem › Peran & Hak Akses') !!} saat ini. Peran {{ \App\Support\HakAkses::PERAN_SUPER }} selalu boleh semua. Halaman Panduan terbuka untuk semua pengguna.</p>
                    <div class="mt-4 overflow-x-auto">
                        <table class="ds-table ds-table-entri">
                            <thead><tr><th>Hak</th><th>Peran yang memiliki</th></tr></thead>
                            <tbody>
                                @foreach ($this->hakAkses() as $judulProgram => $daftarHak)
                                    <tr wire:key="hak-program-{{ \Illuminate\Support\Str::slug($judulProgram) }}">
                                        <td colspan="2" class="text-xs font-semibold tracking-wide uppercase bg-surface-soft text-muted dark:bg-gray-800 dark:text-gray-400">{{ $judulProgram }}</td>
                                    </tr>
                                    @foreach ($daftarHak as $izin => $hak)
                                        <tr wire:key="hak-{{ $izin }}">
                                            <td class="text-sm"><span class="block text-ink dark:text-gray-100">{{ $hak['label'] }}</span><span class="font-mono text-xs text-muted">{{ $izin }}</span></td>
                                            <td><div class="flex flex-wrap gap-1">@foreach ($hak['peran'] as $namaPeran)<x-badge variant="alternative">{{ $namaPeran }}</x-badge>@endforeach</div></td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- TANYA JAWAB --}}
                <section id="tanya" class="p-6 border scroll-mt-24 bg-canvas border-hairline rounded-2xl dark:bg-gray-900 dark:border-gray-700">
                    <h2>Tanya jawab</h2>
                    @php
                        $tanyaJawab = [
                            ['Laporan kosong / hanya sebagian bulan?', 'Laporan hanya membaca bulan yang sudah <b>diposting</b>. Peringatan kuning di atas laporan menyebut bulan yang belum diposting.'],
                            ['Kenapa tombol Kunci abu-abu / tidak bisa diklik?', 'Belum gilirannya: kunci berurutan dari bulan terlama. Keterangan di bawah tombol menyebut bulan yang harus dikunci atau diposting lebih dulu.'],
                            ['Neraca Saldo TIDAK seimbang?', 'Seharusnya tidak terjadi untuk data SIRUS (jurnalnya berpasangan). Periksa jurnal umum yang baru diposting, lalu hubungi admin.'],
                            ['Saya salah posting jurnal umum?', 'Bulan masih Buka: <b>Batalkan</b> jurnalnya (dengan alasan). Bulan sudah dikunci: <b>Salin sebagai pembalik</b> ke bulan yang masih Buka.'],
                            ['Pengesahan ditolak karena akun belum dipetakan?', 'Ada akun baru di SIRUS. Petakan di ' . $tautan('master.peta-akun', 'Peta Akun SIRUS') . ' (filter <i>Perlu perhatian</i>), lalu sahkan lagi.'],
                            ['Kenapa saldo awal tahun ini tidak bisa diisi?', 'Saldo awal hanya diisi untuk tahun pertama SIKEU. Tahun sesudahnya diteruskan otomatis dari saldo akhir Desember; koreksi lewat Jurnal Umum di tahun yang bersangkutan.'],
                            ['Akun SIRUS diabaikan, lalu apa?', 'Transaksinya (termasuk lawannya, mis. kas atau persediaan) tidak masuk buku. Catat penggantinya di SIKEU lewat Jurnal Umum; bulan yang sudah diposting baru berubah setelah Tarik ulang lalu Sahkan.'],
                            ['Halaman tertutup saat menarik data?', 'Buka lagi Tarik Data SIRUS di tahun yang sama — penarikan dilanjutkan otomatis.'],
                            ['Data SIRUS bulan lalu berubah setelah dikunci?', 'Lihat bagian 4: Tarik ulang, periksa tanda sama / berbeda, lalu koreksi lewat Jurnal Umum (disarankan) atau Buka Kunci.'],
                        ];
                    @endphp
                    <div class="mt-3 space-y-2">
                        @foreach ($tanyaJawab as [$tanya, $jawab])
                            <details class="p-3 border rounded-xl border-hairline dark:border-gray-700">
                                <summary class="font-semibold cursor-pointer text-ink dark:text-gray-100">{{ $tanya }}</summary>
                                <p class="mt-2 text-sm">{!! $jawab !!}</p>
                            </details>
                        @endforeach
                    </div>
                </section>
            </article>
        </div>
    </div>
</div>
