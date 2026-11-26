<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\Format;
use App\Support\LaporanKeuangan;
use App\Support\SaldoAwal;
use App\Support\SnapshotJurnal;

/**
 * NERACA (Laporan Posisi Keuangan) — susunan dari template (sikeumst_lap_*, DDL 07), saldo dari jurnal terposting
 * termasuk saldo awal sah. Kolom: akhir periode terpilih & 1 Januari. Laba tahun berjalan = akun Laba Rugi sejak
 * Januari (sama dengan Laba Rugi YTD). Pemeriksaan: Aset = Liabilitas + Ekuitas, akun yatim/dobel, periode belum
 * diposting, saldo awal belum sah. Logika di App\Support\LaporanKeuangan::neraca (skill keuangan-rs §2).
 * Bentuk: BERTINGKAT (staffel, atas-bawah) atau KANAN-KIRI (skontro): kiri = baris sampai kode TA, kanan = sesudahnya
 * sampai TLE; JUMLAH kedua sisi sejajar di bawah, cetak mendatar. Kolom tidak seimbang → baris TA/TLE merah + baris SELISIH.
 */
new class extends Component {
    #[Url(as: 'periode', except: '')]
    public string $periode = '';

    /** Template laporan (Umum / Internal / ...); kosong = bawaan. Dikelola di Data Induk › Susunan Laporan. */
    #[Url(as: 'template', except: '')]
    public string $lapId = '';

    /** staffel = bertingkat (atas-bawah), skontro = kanan-kiri */
    #[Url(as: 'bentuk', except: 'staffel')]
    public string $bentuk = 'staffel';

    #[Computed]
    public function templateList(): array
    {
        return LaporanKeuangan::daftarTemplate(LaporanKeuangan::JENIS_NERACA);
    }

    public function mount(): void
    {
        if (!SnapshotJurnal::periodeValid($this->periode)) {
            $this->periode = (string) (DB::table('sikeumst_periodes')->whereNotNull('snap_id_sah')->max('periode')
                ?? DB::table('sikeumst_periodes')->max('periode') ?? now()->format('Ym'));
        }
    }

    #[Computed]
    public function periodeList(): array
    {
        return DB::table('sikeumst_periodes')->orderByDesc('periode')->get(['periode', 'status', 'snap_id_sah'])->keyBy('periode')->all();
    }

    #[Computed]
    public function laporan(): array
    {
        return LaporanKeuangan::neraca($this->periode, $this->lapId !== '' ? (int) $this->lapId : null);
    }

    #[Computed]
    public function periodeBelumPosting(): array
    {
        $belumList = [];
        $tahun = substr($this->periode, 0, 4);
        for ($bulan = 1; $bulan <= (int) substr($this->periode, 4, 2); $bulan++) {
            $kode = sprintf('%s%02d', $tahun, $bulan);
            if (!isset($this->periodeList[$kode]) || !$this->periodeList[$kode]->snap_id_sah) {
                $belumList[] = SnapshotJurnal::labelPeriode($kode);
            }
        }

        return $belumList;
    }

    #[Computed]
    public function saldoAwalSah(): bool
    {
        return (bool) (new SaldoAwal())->jurnal((int) substr($this->periode, 0, 4))['sah'];
    }

    /** Kolom yang tidak seimbang (Aset ≠ Liabilitas + Ekuitas, atau buku besar sendiri tidak seimbang): [kunci => selisih] */
    #[Computed]
    public function tidakSeimbang(): array
    {
        $hasil = [];
        foreach ($this->laporan['pemeriksaan']['seimbang'] ?? [] as $kunci => $cek) {
            if (($cek['selisih_laporan'] ?? 0) != 0.0 || $cek['selisih_buku'] != 0.0) {
                $hasil[$kunci] = (float) ($cek['selisih_laporan'] ?? $cek['selisih_buku']);
            }
        }

        return $hasil;
    }

    /**
     * Baris per sisi untuk bentuk kanan-kiri: kiri = sebelum TA, kanan = sesudah TA sebelum TLE, total = [TA, TLE].
     * Template tanpa TA/TLE → semua di kiri (pemeriksaan sudah memperingatkan).
     * @return array{kiri: array, kanan: array, totalKiri: ?array, totalKanan: ?array}
     */
    #[Computed]
    public function sisi(): array
    {
        $kiri = [];
        $kanan = [];
        $totalKiri = null;
        $totalKanan = null;
        $sudahTa = false;
        foreach ($this->laporan['baris'] as $baris) {
            if ($baris['kode'] === 'TA') {
                $totalKiri = $baris;
                $sudahTa = true;
                continue;
            }
            if ($baris['kode'] === 'TLE') {
                $totalKanan = $baris;
                continue;
            }
            if ($sudahTa) {
                $kanan[] = $baris;
            } else {
                $kiri[] = $baris;
            }
        }

        return compact('kiri', 'kanan', 'totalKiri', 'totalKanan');
    }

    public function teksNilai(?float $nilai): string
    {
        if ($nilai === null) {
            return '';
        }

        return $nilai < 0 ? '(' . Format::uang(-$nilai) . ')' : Format::uang($nilai);
    }
};
?>

<div>

    {{-- Topbar: nama template yang dipakai (wire:key → judul ikut berganti saat template diganti) --}}
    {{-- Topbar (sebelah logo): nama laporan · periode · template. wire:key ikut periode & template → judul berganti. --}}
    <div wire:key="judul-{{ $periode }}-{{ $this->laporan['template']->lap_id ?? 0 }}">
        <x-page-title title="Laporan Posisi Keuangan (Neraca)"
            subtitle="Per {{ LaporanKeuangan::tanggalAkhir($periode) }} · Template: {{ $this->laporan['template']->nama ?? '-' }}" />
    </div>

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">

            {{-- TOOLBAR --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between print:hidden">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-44">
                        <x-input-label for="periode" value="Per akhir periode" />
                        <x-select-input id="periode" wire:model.live="periode" class="mt-1">
                            @forelse ($this->periodeList as $kodePeriode => $infoPeriode)
                                <option value="{{ $kodePeriode }}">{{ SnapshotJurnal::labelPeriode((string) $kodePeriode) }}{{ $infoPeriode->status === 'K' ? ' (kunci)' : '' }}</option>
                            @empty
                                <option value="{{ $periode }}">{{ SnapshotJurnal::labelPeriode($periode) }}</option>
                            @endforelse
                        </x-select-input>
                    </div>
                    @if (count($this->templateList) > 0)
                        <div class="w-72">
                            <x-input-label for="lapId" value="Template" />
                            <x-select-input id="lapId" wire:model.live="lapId" class="mt-1">
                                {{-- urutan: bawaan dulu → tanpa pilihan (lapId kosong) browser menampilkan bawaan --}}
                                @foreach ($this->templateList as $idTemplate => $namaTemplate)
                                    <option value="{{ $idTemplate }}">{{ $namaTemplate }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                    @endif
                    <div class="w-44">
                        <x-input-label for="bentuk" value="Bentuk" />
                        <x-select-input id="bentuk" wire:model.live="bentuk" class="mt-1">
                            <option value="staffel">Bertingkat</option>
                            <option value="skontro">Kanan-kiri</option>
                        </x-select-input>
                    </div>
                    @php
                        $statusPeriode = $this->periodeList[$periode]->status ?? null;
                        $pemeriksaan = $this->laporan['pemeriksaan'];
                        $seimbangSemua = collect($pemeriksaan['seimbang'] ?? [])->every(fn ($cek) => ($cek['selisih_laporan'] ?? 0) == 0.0 && $cek['selisih_buku'] == 0.0);
                    @endphp
                    @if ($statusPeriode === 'K' || $statusPeriode === 'T')
                        <x-badge variant="success" class="mb-2">Final — periode dikunci</x-badge>
                    @else
                        <x-badge variant="warning" class="mb-2">Sementara — periode belum dikunci</x-badge>
                    @endif
                    @if ($seimbangSemua)
                        <x-badge variant="success" class="mb-2">Aset = Liabilitas + Ekuitas</x-badge>
                    @else
                        <x-badge variant="danger" class="mb-2">TIDAK seimbang</x-badge>
                    @endif
                </div>
                <x-cetak-button x-on:click="window.print()" label="Cetak" />
            </div>

            {{-- PEMERIKSAAN --}}
            <div class="space-y-2 print:hidden">
                @foreach ($pemeriksaan['galat'] as $galat)
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">{{ $galat }}</div>
                @endforeach
                @if (!$this->saldoAwalSah)
                    <div role="status" class="px-4 py-2 text-sm rounded-lg bg-warning-soft text-fg-warning">
                        Saldo awal {{ substr($periode, 0, 4) }} belum disahkan — Neraca hanya berisi mutasi, bukan posisi keuangan sebenarnya.
                        <x-tautan-halaman route="buku-besar.saldo-awal" :params="['tahun' => substr($periode, 0, 4)]" class="font-medium underline">Saldo Awal</x-tautan-halaman>
                    </div>
                @endif
                @if ($this->periodeBelumPosting !== [])
                    <div role="status" class="px-4 py-2 text-sm rounded-lg bg-warning-soft text-fg-warning">
                        Belum diposting: {{ implode(', ', $this->periodeBelumPosting) }} — saldo akhir {{ SnapshotJurnal::labelPeriode($periode) }} belum lengkap.
                        <x-tautan-halaman route="buku-besar.periode" :params="['tahun' => substr($periode, 0, 4)]" class="font-medium underline">Tarik Data SIRUS</x-tautan-halaman>
                    </div>
                @endif
                @if (($pemeriksaan['yatim'] ?? []) !== [])
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                        {{ count($pemeriksaan['yatim']) }} akun tidak masuk baris Neraca mana pun:
                        {{ collect($pemeriksaan['yatim'])->map(fn ($akun) => $akun['akun_id'] . ($akun['bernilai'] ? ' (ada saldo!)' : ''))->implode(', ') }}.
                    </div>
                @endif
                @if (($pemeriksaan['dobel'] ?? []) !== [])
                    <div role="alert" class="px-4 py-2 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">Akun terhitung di lebih dari satu baris: {{ implode(', ', $pemeriksaan['dobel']) }}.</div>
                @endif
                @if ($this->tidakSeimbang !== [])
                    @php $bukuTidakSeimbang = collect($pemeriksaan['seimbang'])->contains(fn ($cek) => $cek['selisih_buku'] != 0.0); @endphp
                    <div role="alert" class="flex gap-3 px-4 py-3 text-sm rounded-lg bg-danger-soft text-fg-danger-strong">
                        <x-ikon-peringatan />
                        <div class="space-y-1">
                            <p class="font-semibold">Neraca TIDAK seimbang —
                                @foreach ($this->tidakSeimbang as $kunciKolom => $selisih)
                                    {{ $this->laporan['kolom'][$kunciKolom]['judul'] }}: selisih {{ $this->teksNilai($selisih) }}{{ $loop->last ? '' : ';' }}
                                @endforeach
                            </p>
                            @if ($bukuTidakSeimbang)
                                <p><b>Penyebab: buku besar sendiri tidak seimbang</b> (total debit ≠ kredit) — ada jurnal bermasalah. Periksa
                                    <x-tautan-halaman route="buku-besar.neraca-saldo" :params="['periode' => $periode]" class="font-semibold underline">Neraca Saldo</x-tautan-halaman> dan jurnal yang baru diposting di
                                    <x-tautan-halaman route="buku-besar.jurnal-umum" :params="['periode' => $periode]" class="font-semibold underline">Jurnal Umum</x-tautan-halaman>.</p>
                            @else
                                <p><b>Penyebab: susunan template</b> — buku besar seimbang, tetapi ada akun yang tidak masuk baris mana pun (yatim) / masuk dua baris (dobel), atau rumus total keliru.
                                    @if (($pemeriksaan['yatim'] ?? []) !== []) Akun yatim: {{ collect($pemeriksaan['yatim'])->pluck('akun_id')->implode(', ') }}. @endif
                                    @if (($pemeriksaan['dobel'] ?? []) !== []) Akun dobel: {{ implode(', ', $pemeriksaan['dobel']) }}. @endif
                                    Betulkan di <x-tautan-halaman route="master.susunan-laporan" :params="['template' => $this->laporan['template']->lap_id ?? '']" class="font-semibold underline">Susunan Laporan</x-tautan-halaman>.</p>
                            @endif
                        </div>
                    </div>
                @endif
                @if (($pemeriksaan['laba_tahun_lalu'] ?? 0) != 0.0)
                    <div role="status" class="px-4 py-2 text-sm rounded-lg bg-info-tint text-info-deep dark:bg-blue-900/30 dark:text-blue-200">
                        Laba (rugi) tahun lalu yang belum ditutup buku {{ $this->teksNilai($pemeriksaan['laba_tahun_lalu']) }} sementara ditampilkan di Saldo laba.
                    </div>
                @endif
            </div>

            {{-- LAPORAN --}}
            @php $kolomList = $this->laporan['kolom']; $merah = $this->tidakSeimbang; @endphp
            {{-- Bertingkat: satu kartu. Kanan-kiri: tanpa kartu luar — dua kartu sisi selebar mungkin, jeda hanya di tengah --}}
            <div class="{{ $bentuk === 'skontro' ? 'cetak-mendatar' : 'bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900' }} print:border-0 print:shadow-none">
                {{-- Kop laporan hanya saat DICETAK (di layar judul ada di topbar) --}}
                <div class="hidden px-6 pt-5 pb-2 text-center print:block">
                    <p class="text-sm font-semibold uppercase text-ink dark:text-gray-100">{{ \App\Support\Identitas::get()->nama ?: config('app.name') }}</p>
                    <p class="text-base font-bold text-ink dark:text-gray-100">LAPORAN POSISI KEUANGAN (NERACA)</p>
                    <p class="text-sm text-muted dark:text-gray-400">Per {{ LaporanKeuangan::tanggalAkhir($periode) }}</p>
                </div>

                @if ($bentuk === 'skontro')
                    {{-- KANAN-KIRI: dua sisi sejajar, JUMLAH di dasar masing-masing sisi (grid meratakan tinggi) --}}
                    {{-- jeda antar sisi: dua kotak terpisah (gap), bukan satu tabel menempel --}}
                    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2 print:grid-cols-2 print:gap-4">
                        @foreach (['kiri' => ['ASET', 'totalKiri'], 'kanan' => ['LIABILITAS DAN EKUITAS', 'totalKanan']] as $kunciSisi => [$judulSisi, $kunciTotal])
                            <div class="flex flex-col overflow-x-auto border shadow-sm bg-canvas border-hairline rounded-2xl dark:border-gray-700 dark:bg-gray-900 print:shadow-none" wire:key="sisi-{{ $kunciSisi }}">
                                <table class="ds-table ds-table-entri [&_td]:text-base">
                                    <thead>
                                        <tr>
                                            <th>{{ $judulSisi }}</th>
                                            @foreach ($kolomList as $kolom)
                                                <th class="text-right w-56">{{ $kolom['judul'] }}@if (!empty($kolom['sub']))<span class="block mt-0.5 text-xs font-normal normal-case tracking-normal">{{ $kolom['sub'] }}</span>@endif</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($this->sisi[$kunciSisi] as $baris)
                                            <x-laporan.baris-neraca :baris="$baris" :kolomList="$kolomList" :periode="$periode" :merahKolom="$merah" />
                                        @endforeach
                                    </tbody>
                                </table>
                                @if ($this->sisi[$kunciTotal])
                                    <table class="mt-auto border-t-2 ds-table ds-table-entri [&_td]:text-base border-ink dark:border-gray-400">
                                        <tbody>
                                            <x-laporan.baris-neraca :baris="$this->sisi[$kunciTotal]" :kolomList="$kolomList" :periode="$periode" :merahKolom="$merah" />
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    {{-- BERTINGKAT --}}
                    <div class="overflow-x-auto">
                        <table class="ds-table ds-table-entri [&_td]:text-base">
                            <thead>
                                <tr>
                                    <th>Keterangan</th>
                                    @foreach ($kolomList as $kolom)
                                        <th class="text-right w-56">{{ $kolom['judul'] }}@if (!empty($kolom['sub']))<span class="block mt-0.5 text-xs font-normal normal-case tracking-normal">{{ $kolom['sub'] }}</span>@endif</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->laporan['baris'] as $baris)
                                    <x-laporan.baris-neraca :baris="$baris" :kolomList="$kolomList" :periode="$periode" :merahKolom="$merah" />
                                @empty
                                    <tr><td colspan="3" class="px-6 py-10 text-center text-muted">Template Neraca kosong.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- SELISIH: hanya bila ada kolom tidak seimbang --}}
                @if ($merah !== [])
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 font-bold border-t-2 bg-danger-soft text-fg-danger-strong border-fg-danger-strong {{ $bentuk === 'skontro' ? 'mt-4 rounded-2xl' : '' }}">
                        <span class="inline-flex items-center gap-2"><x-ikon-peringatan /> SELISIH (Aset − Liabilitas − Ekuitas)</span>
                        <span class="flex gap-6 tabular-nums">
                            @foreach ($kolomList as $kunciKolom => $kolom)
                                <span>{{ $kolom['judul'] }}: {{ isset($merah[$kunciKolom]) ? $this->teksNilai($merah[$kunciKolom]) : Format::uang(0) }}</span>
                            @endforeach
                        </span>
                    </div>
                @endif

                <p class="{{ $bentuk === 'skontro' ? 'px-1 pt-3' : 'px-6 py-3' }} text-xs text-muted dark:text-gray-400">
                    Angka dalam kurung = negatif; pos bernilai negatif ditandai merah dengan segitiga peringatan (mis. persediaan bersaldo kredit) — telusuri lewat rincian akun.
                    "Akun sementara" harus nol sebelum laporan dianggap final. Jumlah Aset merah = tidak sama dengan Jumlah Liabilitas dan Ekuitas di kolom itu.
                </p>
            </div>
        </div>
    </div>
    <livewire:pages::buku-besar.mutasi-akun.mutasi-akun-modal wire:key="mutasi-akun-modal" />
</div>

