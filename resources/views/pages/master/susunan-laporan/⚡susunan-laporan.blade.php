<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\DB;
use App\Support\LaporanKeuangan;
use App\Support\SusunanLaporan;

/**
 * SUSUNAN LAPORAN — kelola template Laba Rugi & Neraca tanpa SQL (App\Support\SusunanLaporan).
 * Banyak template per jenis (mis. Umum SAK EP untuk pihak luar, Internal per instalasi); satu bawaan per jenis.
 * Atas: daftar template + pemeriksaan (akun yatim / dobel / galat rumus). Bawah: susunan baris template terpilih —
 * naik/turun, ubah, sisip di bawah, hapus. Lihat = master.susunan-laporan.lihat (menu); ubah = akun.kelola.
 */
new class extends Component {
    #[Url(as: 'template', except: '')]
    public string $lapIdPilih = '';

    /** Jendela template: baru / salin / ubah */
    public string $modeTemplate = '';
    public ?int $templateSumber = null;
    public string $formJenis = LaporanKeuangan::JENIS_LABARUGI;
    public string $formNama = '';
    public string $formKeterangan = '';
    public bool $formAktif = true;

    /** Jendela baris */
    public ?int $barisId = null;
    public ?int $barisSesudah = null;
    public string $formKode = '';
    public string $formLabel = '';
    public string $formTipe = 'A';
    public string $formTanda = '1';
    public bool $formTebal = false;
    public string $formInduk = '';
    public string $formRumus = '';
    /** @var array<int, array{akun_id: string, termasuk_turunan: bool, sisi: string}> */
    public array $formTautan = [];

    private function mesin(): SusunanLaporan
    {
        return new SusunanLaporan();
    }

    private function wajibKelola(): void
    {
        abort_unless(auth()->user()?->can('akun.kelola'), 403);
    }

    private function jalankan(callable $aksi, string $pesan): bool
    {
        try {
            $aksi();
            $this->dispatch('toast', type: 'success', message: $pesan);
            $this->segarkan();

            return true;
        } catch (\Throwable $exception) {
            $this->dispatch('toast', type: 'error', message: $exception->getMessage());

            return false;
        }
    }

    private function segarkan(): void
    {
        unset($this->daftar, $this->header, $this->barisList, $this->pemeriksaan);
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function daftar(): array
    {
        return array_map(function (array $template) {
            $cek = $this->mesin()->periksa((int) $template['lap_id']);

            return $template + ['masalah' => count($cek['galat']) + count($cek['yatim']) + count($cek['dobel'])];
        }, $this->mesin()->daftar());
    }

    #[Computed]
    public function header(): ?object
    {
        return $this->lapIdPilih !== '' ? DB::table('sikeumst_lap_hdrs')->where('lap_id', (int) $this->lapIdPilih)->first() : null;
    }

    #[Computed]
    public function barisList(): array
    {
        return $this->header ? LaporanKeuangan::barisTemplate((int) $this->header->lap_id) : [];
    }

    #[Computed]
    public function pemeriksaan(): ?array
    {
        return $this->header ? $this->mesin()->periksa((int) $this->header->lap_id) : null;
    }

    /** Pilihan akun (header & detail aktif) untuk tautan baris. */
    #[Computed]
    public function akunList(): array
    {
        return DB::table('sikeumst_akuns')->where('aktif', '1')->orderBy('akun_id')->get(['akun_id', 'nama', 'tipe'])
            ->mapWithKeys(fn ($row) => [$row->akun_id => $row->akun_id . ' ' . $row->nama . ($row->tipe === 'H' ? ' (header)' : '')])->all();
    }

    public function pilih(int $lapId): void
    {
        $this->lapIdPilih = (string) $lapId;
        $this->segarkan();
    }

    // ─── Template ─────────────────────────────────────────────────────────────
    public function bukaTemplate(string $mode, ?int $lapId = null): void
    {
        $this->wajibKelola();
        $this->resetValidation();
        $this->modeTemplate = $mode;
        $this->templateSumber = $lapId;
        $sumber = $lapId ? DB::table('sikeumst_lap_hdrs')->where('lap_id', $lapId)->first() : null;
        $this->formJenis = $sumber->jenis ?? LaporanKeuangan::JENIS_LABARUGI;
        $this->formNama = match ($mode) {
            'salin' => mb_substr(($sumber->nama ?? '') . ' (salinan)', 0, 100),
            'ubah' => (string) ($sumber->nama ?? ''),
            default => '',
        };
        $this->formKeterangan = $mode === 'ubah' ? (string) ($sumber->keterangan ?? '') : '';
        $this->formAktif = $mode === 'ubah' ? ($sumber->aktif ?? '1') === '1' : true;
        $this->dispatch('open-modal', name: 'form-template');
    }

    public function simpanTemplate(): void
    {
        $this->wajibKelola();
        try {
            $lapId = match ($this->modeTemplate) {
                'baru' => $this->mesin()->buat($this->formJenis, $this->formNama, $this->formKeterangan),
                'salin' => $this->mesin()->salin((int) $this->templateSumber, $this->formNama, $this->formKeterangan),
                'ubah' => (function () {
                    $this->mesin()->simpanHeader((int) $this->templateSumber, $this->formNama, $this->formKeterangan, $this->formAktif);

                    return (int) $this->templateSumber;
                })(),
            };
        } catch (\Throwable $exception) {
            $this->addError('formNama', $exception->getMessage());
            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Template disimpan.');
        $this->lapIdPilih = (string) $lapId;
        $this->tutupTemplate();
        $this->segarkan();
    }

    public function tutupTemplate(): void
    {
        $this->reset(['modeTemplate', 'templateSumber', 'formNama', 'formKeterangan', 'formAktif']);
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'form-template');
    }

    public function jadikanBawaan(int $lapId): void
    {
        $this->wajibKelola();
        $this->jalankan(fn () => $this->mesin()->jadikanBawaan($lapId), 'Template dijadikan bawaan — laporan memakai template ini bila tidak dipilih lain.');
    }

    public function hapusTemplate(int $lapId): void
    {
        $this->wajibKelola();
        if ($this->jalankan(fn () => $this->mesin()->hapus($lapId), 'Template dihapus.') && (int) $this->lapIdPilih === $lapId) {
            $this->lapIdPilih = '';
        }
    }

    // ─── Baris ────────────────────────────────────────────────────────────────
    public function bukaBaris(?int $barisId = null, ?int $sesudah = null): void
    {
        $this->wajibKelola();
        $this->resetValidation();
        $baris = $barisId ? collect($this->barisList)->firstWhere('baris_id', $barisId) : null;
        $this->barisId = $barisId;
        $this->barisSesudah = $sesudah;
        $this->formKode = (string) ($baris['kode'] ?? '');
        $this->formLabel = (string) ($baris['label'] ?? '');
        $this->formTipe = (string) ($baris['tipe'] ?? 'A');
        $this->formTanda = (string) ($baris['tanda'] ?? '1');
        $this->formTebal = ($baris['tebal'] ?? '0') === '1';
        $this->formInduk = (string) ($baris['induk_baris_id'] ?? '');
        $this->formRumus = (string) ($baris['rumus'] ?? '');
        $this->formTautan = $baris
            ? array_map(fn (array $tautan) => ['akun_id' => $tautan['akun_id'], 'termasuk_turunan' => $tautan['termasuk_turunan'] === '1', 'sisi' => $tautan['sisi']], $baris['tautan'])
            : [];
        if ($this->formTautan === []) {
            $this->tambahTautan();
        }
        $this->dispatch('open-modal', name: 'form-baris');
    }

    public function tambahTautan(): void
    {
        $this->formTautan[] = ['akun_id' => '', 'termasuk_turunan' => true, 'sisi' => ($this->header->jenis ?? '') === LaporanKeuangan::JENIS_NERACA ? 'S' : 'M'];
    }

    public function hapusTautan(int $indeks): void
    {
        unset($this->formTautan[$indeks]);
        $this->formTautan = array_values($this->formTautan);
    }

    public function simpanBaris(): void
    {
        $this->wajibKelola();
        $this->resetValidation();
        try {
            $this->mesin()->simpanBaris((int) $this->lapIdPilih, $this->barisId, [
                'kode' => $this->formKode, 'label' => $this->formLabel, 'tipe' => $this->formTipe, 'rumus' => $this->formRumus,
                'tanda' => (int) $this->formTanda, 'tebal' => $this->formTebal, 'induk_baris_id' => $this->formInduk !== '' ? (int) $this->formInduk : null,
            ], $this->formTipe === 'A' ? $this->formTautan : [], $this->barisSesudah);
        } catch (\Throwable $exception) {
            $this->addError('formKode', $exception->getMessage());
            return;
        }
        $this->dispatch('toast', type: 'success', message: "Baris {$this->formKode} disimpan.");
        $this->tutupBaris();
        $this->segarkan();
    }

    public function tutupBaris(): void
    {
        $this->reset(['barisId', 'barisSesudah', 'formKode', 'formLabel', 'formTipe', 'formTanda', 'formTebal', 'formInduk', 'formRumus', 'formTautan']);
        $this->resetValidation();
        $this->dispatch('close-modal', name: 'form-baris');
    }

    public function hapusBaris(int $barisId): void
    {
        $this->wajibKelola();
        $this->jalankan(fn () => $this->mesin()->hapusBaris((int) $this->lapIdPilih, $barisId), 'Baris dihapus.');
    }

    public function geser(int $barisId, int $arah): void
    {
        $this->wajibKelola();
        $this->jalankan(fn () => $this->mesin()->geserBaris((int) $this->lapIdPilih, $barisId, $arah), 'Urutan diubah.');
    }
};
?>

<div>
    <x-page-title title="Susunan Laporan"
        subtitle="Template baris Laba Rugi & Neraca — bisa lebih dari satu (mis. Umum untuk pihak luar, Internal untuk manajemen); totalnya tetap sama" />

    <div class="w-full min-h-[calc(100vh-5rem)] bg-surface-soft dark:bg-gray-900">
        <div class="px-6 pt-2 pb-6 space-y-4">
            @php $bolehKelola = auth()->user()?->can('akun.kelola'); @endphp

            {{-- DAFTAR TEMPLATE --}}
            <div class="bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-hairline dark:border-gray-700">
                    <p class="text-sm text-body dark:text-gray-300">Laporan memakai template <b>bawaan</b>; template lain dipilih di layar laporan (kolom <i>Template</i>).</p>
                    @if ($bolehKelola)
                        <x-secondary-button type="button" wire:click="bukaTemplate('baru')"><x-ikon nama="tambah" /> Template Kosong</x-secondary-button>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="ds-table ds-table-entri">
                        <thead><tr><th>Laporan</th><th>Template</th><th class="ds-c">Status</th><th class="ds-c">Baris</th><th class="ds-c">Pemeriksaan</th><th class="ds-c">Aksi</th></tr></thead>
                        <tbody>
                            @foreach ($this->daftar as $template)
                                <tr wire:key="tpl-{{ $template['lap_id'] }}" class="{{ (int) $lapIdPilih === (int) $template['lap_id'] ? 'bg-brand-green/5' : '' }}">
                                    <td class="whitespace-nowrap">{{ SusunanLaporan::JENIS_LIST[$template['jenis']] }}</td>
                                    <td>
                                        <span class="block font-medium text-ink dark:text-gray-100">{{ $template['nama'] }}</span>
                                        @if ($template['keterangan'])<span class="block text-xs text-muted dark:text-gray-400">{{ $template['keterangan'] }}</span>@endif
                                    </td>
                                    <td class="ds-c">
                                        @if ($template['bawaan'] === '1')
                                            <x-badge variant="success">Bawaan</x-badge>
                                        @elseif ($template['aktif'] === '1')
                                            <x-badge variant="info">Aktif</x-badge>
                                        @else
                                            <x-badge variant="gray">Nonaktif</x-badge>
                                        @endif
                                    </td>
                                    <td class="ds-c tabular-nums">{{ $template['jumlah_baris'] }}</td>
                                    <td class="ds-c">
                                        @if ($template['masalah'] === 0)
                                            <x-badge variant="success">OK</x-badge>
                                        @else
                                            <x-badge variant="danger">{{ $template['masalah'] }} masalah</x-badge>
                                        @endif
                                    </td>
                                    <td class="ds-c">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @if ((int) $lapIdPilih === (int) $template['lap_id'])
                                                <x-outline-button type="button" kecil disabled><x-ikon nama="lihat" /> Dibuka</x-outline-button>
                                            @else
                                                <x-outline-button type="button" kecil wire:click="pilih({{ $template['lap_id'] }})"><x-ikon nama="lihat" /> Buka</x-outline-button>
                                            @endif
                                            @if ($bolehKelola)
                                                <x-secondary-button type="button" kecil wire:click="bukaTemplate('salin', {{ $template['lap_id'] }})"><x-ikon nama="salin" /> Salin</x-secondary-button>
                                                @if ($template['bawaan'] !== '1')
                                                    <x-confirm-button variant="primary" action="jadikanBawaan({{ $template['lap_id'] }})" kecil
                                                        title="Jadikan Bawaan" message="{{ $template['nama'] }} dipakai laporan bila pemakai tidak memilih template lain. Bawaan lama tetap ada sebagai template biasa.">
                                                        <x-ikon nama="setujui" /> Jadikan Bawaan
                                                    </x-confirm-button>
                                                    <x-confirm-button variant="danger-soft" action="hapusTemplate({{ $template['lap_id'] }})" kecil
                                                        title="Hapus Template" message="Template {{ $template['nama'] }} dan semua barisnya dihapus. Angka laporan tidak berubah.">
                                                        <x-ikon nama="hapus" /> Hapus
                                                    </x-confirm-button>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- SUSUNAN BARIS TEMPLATE TERPILIH --}}
            @if ($this->header)
                @php $cek = $this->pemeriksaan; $judulList = collect($this->barisList)->where('tipe', 'J')->keyBy('baris_id'); @endphp
                <div class="bg-canvas border border-hairline shadow-sm rounded-2xl dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-hairline dark:border-gray-700">
                        <div>
                            <p class="font-semibold text-ink dark:text-gray-100">{{ $this->header->nama }}</p>
                            <p class="text-xs text-muted dark:text-gray-400">{{ SusunanLaporan::JENIS_LIST[$this->header->jenis] }} · {{ count($this->barisList) }} baris ·
                                <a href="{{ route($this->header->jenis === LaporanKeuangan::JENIS_NERACA ? 'laporan.neraca' : 'laporan.laba-rugi', ['template' => $this->header->lap_id]) }}" wire:navigate class="underline">lihat laporannya</a></p>
                        </div>
                        @if ($bolehKelola)
                            <div class="flex flex-wrap gap-2">
                                <x-secondary-button type="button" wire:click="bukaTemplate('ubah', {{ $this->header->lap_id }})"><x-ikon nama="ubah" /> Nama &amp; Status</x-secondary-button>
                                <x-secondary-button type="button" wire:click="bukaBaris"><x-ikon nama="tambah" /> Baris di Akhir</x-secondary-button>
                            </div>
                        @endif
                    </div>

                    {{-- Pemeriksaan --}}
                    <div class="px-4 py-3 space-y-2 text-sm border-b border-hairline dark:border-gray-700">
                        @if ($cek['galat'] === [] && $cek['yatim'] === [] && $cek['dobel'] === [])
                            <p class="text-fg-success-strong" role="status">Semua akun tertampung tepat sekali dan semua rumus sah — total laporan sama dengan template lain.</p>
                        @endif
                        @foreach ($cek['galat'] as $galat)
                            <p class="flex gap-2 px-3 py-2 rounded-lg bg-danger-soft text-fg-danger-strong" role="alert"><x-ikon-peringatan /> {{ $galat }}</p>
                        @endforeach
                        @if ($cek['yatim'] !== [])
                            <p class="flex gap-2 px-3 py-2 rounded-lg bg-danger-soft text-fg-danger-strong" role="alert"><x-ikon-peringatan />
                                <span><b>{{ count($cek['yatim']) }} akun yatim</b> (tidak masuk baris mana pun — angkanya hilang dari laporan):
                                    {{ collect($cek['yatim'])->map(fn ($akun) => $akun['akun_id'] . ' ' . $akun['nama'])->implode(', ') }}</span></p>
                        @endif
                        @if ($cek['dobel'] !== [])
                            <p class="flex gap-2 px-3 py-2 rounded-lg bg-danger-soft text-fg-danger-strong" role="alert"><x-ikon-peringatan />
                                <span><b>{{ count($cek['dobel']) }} akun dobel</b> (masuk lebih dari satu baris — terhitung dua kali): {{ implode(', ', $cek['dobel']) }}</span></p>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="ds-table ds-table-entri">
                            <thead><tr><th class="w-20">Kode</th><th>Label</th><th class="ds-c">Tipe</th><th>Isi (akun / rumus)</th><th class="ds-c">Tanda</th>@if ($bolehKelola)<th class="ds-c">Aksi</th>@endif</tr></thead>
                            <tbody>
                                @forelse ($this->barisList as $indeks => $baris)
                                    <tr wire:key="baris-{{ $baris['baris_id'] }}" class="{{ $baris['tipe'] === 'J' ? 'bg-surface-card dark:bg-gray-800' : '' }}">
                                        <td class="ds-td-token">{{ $baris['kode'] }}</td>
                                        <td class="{{ $baris['induk_baris_id'] ? 'pl-8' : '' }} {{ $baris['tebal'] === '1' ? 'font-semibold text-ink dark:text-gray-100' : '' }}">{{ $baris['label'] }}</td>
                                        <td class="ds-c">
                                            <x-badge :variant="['J' => 'gray', 'A' => 'info', 'R' => 'purple'][$baris['tipe']]">{{ SusunanLaporan::TIPE_LIST[$baris['tipe']] }}</x-badge>
                                        </td>
                                        <td>
                                            @if ($baris['tipe'] === 'R')
                                                <span class="font-mono text-ink dark:text-gray-200">{{ $baris['rumus'] }}</span>
                                            @elseif ($baris['tipe'] === 'A')
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach ($baris['tautan'] as $tautan)
                                                        <span class="px-1.5 py-0.5 font-mono rounded bg-surface-card dark:bg-gray-800" title="{{ $this->akunList[$tautan['akun_id']] ?? $tautan['akun_id'] }}">{{ $tautan['akun_id'] }}{{ $tautan['termasuk_turunan'] === '1' ? '*' : '' }}{{ $tautan['sisi'] !== 'M' && $tautan['sisi'] !== 'S' ? ' ' . $tautan['sisi'] : '' }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td class="ds-c font-mono">{{ $baris['tipe'] === 'J' ? '' : ((int) $baris['tanda'] === -1 ? '−' : '+') }}</td>
                                        @if ($bolehKelola)
                                            <td class="ds-c">
                                                <div class="flex justify-end gap-1">
                                                    <x-icon-button color="gray" type="button" wire:click="geser({{ $baris['baris_id'] }}, -1)" :disabled="$indeks === 0" title="Naik">
                                                        <span class="sr-only">Naik</span><x-ikon nama="naik" />
                                                    </x-icon-button>
                                                    <x-icon-button color="gray" type="button" wire:click="geser({{ $baris['baris_id'] }}, 1)" :disabled="$indeks === count($this->barisList) - 1" title="Turun">
                                                        <span class="sr-only">Turun</span><x-ikon nama="turun" />
                                                    </x-icon-button>
                                                    <x-icon-button color="gray" type="button" wire:click="bukaBaris(null, {{ $baris['baris_id'] }})" title="Sisip baris di bawahnya">
                                                        <span class="sr-only">Sisip di bawah</span><x-ikon nama="tambah" />
                                                    </x-icon-button>
                                                    <x-action-edit wire:click="bukaBaris({{ $baris['baris_id'] }})" />
                                                    <x-action-delete :action="'hapusBaris(' . $baris['baris_id'] . ')'" title="Hapus Baris" message="Hapus baris {{ $baris['kode'] }} — {{ $baris['label'] }}?" />
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-6 py-8 text-center text-muted">Template masih kosong — tambah baris pertama.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="px-4 py-2 text-xs text-muted dark:text-gray-400">
                        Tipe: <b>Judul</b> tanpa angka · <b>Jumlah akun</b> = jumlah akun yang ditautkan (<span class="font-mono">*</span> = termasuk semua turunannya) · <b>Rumus</b> = <span class="font-mono">[KODE]+[KODE]-[KODE]</span> atas baris di atasnya.
                        Tanda <b>−</b> membalik nilai (pendapatan / akun bersaldo kredit ditampilkan positif). Laba Rugi: baris rumus terakhir = laba bersih. Neraca: wajib ada kode <span class="font-mono">TA</span> &amp; <span class="font-mono">TLE</span>.
                    </p>
                </div>
            @else
                <p class="px-6 py-8 text-center text-muted dark:text-gray-400">Klik <b>Buka</b> pada salah satu template untuk melihat dan mengubah susunan barisnya.</p>
            @endif
        </div>
    </div>

    {{-- JENDELA TEMPLATE --}}
    <x-modal name="form-template" size="xl">
        <div class="flex flex-col">
            <x-modul-dokumen.header judul="{{ ['baru' => 'Template Kosong', 'salin' => 'Salin Template', 'ubah' => 'Nama & Status Template'][$modeTemplate] ?? 'Template' }}"
                ikon="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" tutupAlpine="$wire.tutupTemplate()">
                {{ $modeTemplate === 'salin' ? 'Semua baris & tautan akun ikut tersalin — tinggal disesuaikan.' : '' }}
            </x-modul-dokumen.header>
            <div class="p-5 space-y-4 text-sm bg-canvas dark:bg-gray-900">
                @if ($modeTemplate === 'baru')
                    <div>
                        <x-input-label for="formJenis" value="Laporan *" />
                        <x-select-input id="formJenis" wire:model="formJenis" class="mt-1">
                            @foreach (SusunanLaporan::JENIS_LIST as $kodeJenis => $namaJenis)
                                <option value="{{ $kodeJenis }}">{{ $namaJenis }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                @endif
                <div>
                    <x-input-label for="formNama" value="Nama template *" />
                    <x-text-input id="formNama" wire:model="formNama" maxlength="100" placeholder="mis. Laba Rugi - Internal (Manajemen)" :error="$errors->has('formNama')" class="mt-1" />
                    <x-input-error :messages="$errors->get('formNama')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="formKeterangan" value="Keterangan pemakaian" />
                    <x-text-input id="formKeterangan" wire:model="formKeterangan" maxlength="500" placeholder="mis. untuk rapat direksi, rinci per instalasi" class="mt-1" />
                </div>
                @if ($modeTemplate === 'ubah')
                    <x-toggle wire:model.live="formAktif" :trueValue="true" :falseValue="false" label="Aktif (bisa dipilih di layar laporan)" />
                @endif
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="tutupTemplate"><x-ikon nama="batal" /> Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="simpanTemplate" wire:loading.attr="disabled" wire:target="simpanTemplate"><x-ikon nama="simpan" /> Simpan</x-primary-button>
                </div>
            </div>
        </div>
    </x-modal>

    {{-- JENDELA BARIS --}}
    <x-modal name="form-baris" size="3xl">
        <div class="flex flex-col">
            <x-modul-dokumen.header judul="{{ $barisId ? 'Ubah Baris ' . $formKode : 'Baris Baru' }}"
                ikon="M4 6h16M4 12h16M4 18h7" tutupAlpine="$wire.tutupBaris()">
                {{ $this->header?->nama }}
            </x-modul-dokumen.header>
            <div class="p-5 space-y-4 text-sm bg-canvas dark:bg-gray-900">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div>
                        <x-input-label for="formKode" value="Kode *" />
                        <x-text-input id="formKode" wire:model="formKode" maxlength="10" placeholder="mis. PU1" :error="$errors->has('formKode')" class="mt-1 font-mono uppercase" />
                    </div>
                    <div class="sm:col-span-3">
                        <x-input-label for="formLabel" value="Label *" />
                        <x-text-input id="formLabel" wire:model="formLabel" maxlength="200" placeholder="mis. Pendapatan Rawat Jalan" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="formTipe" value="Tipe *" />
                        <x-select-input id="formTipe" wire:model.live="formTipe" class="mt-1">
                            @foreach (SusunanLaporan::TIPE_LIST as $kodeTipe => $namaTipe)
                                <option value="{{ $kodeTipe }}">{{ $namaTipe }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                    <div>
                        <x-input-label for="formTanda" value="Tanda" />
                        <x-select-input id="formTanda" wire:model="formTanda" :disabled="$formTipe === 'J'" class="mt-1">
                            <option value="1">+ apa adanya</option>
                            <option value="-1">− dibalik (pendapatan / kredit)</option>
                        </x-select-input>
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="formInduk" value="Di bawah judul (dilipat)" />
                        <x-select-input id="formInduk" wire:model="formInduk" class="mt-1">
                            <option value="">- tidak -</option>
                            @foreach (collect($this->barisList)->where('tipe', 'J')->where('baris_id', '<>', $barisId) as $judul)
                                <option value="{{ $judul['baris_id'] }}">{{ $judul['kode'] }} — {{ $judul['label'] }}</option>
                            @endforeach
                        </x-select-input>
                    </div>
                </div>
                <x-toggle wire:model.live="formTebal" :trueValue="true" :falseValue="false" label="Tebal (baris jumlah / judul)" />

                @if ($formTipe === 'R')
                    <div>
                        <x-input-label for="formRumus" value="Rumus *" />
                        <x-text-input id="formRumus" wire:model="formRumus" maxlength="500" placeholder="mis. [PU]-[BP]" class="mt-1 font-mono uppercase" />
                        <p class="mt-1 text-xs text-muted dark:text-gray-400">Hanya [KODE] baris di atasnya, dengan + dan −. Kode tersedia:
                            {{ collect($this->barisList)->where('tipe', '<>', 'J')->where('baris_id', '<>', $barisId)->pluck('kode')->implode(', ') }}</p>
                    </div>
                @endif

                @if ($formTipe === 'A')
                    <div class="space-y-2">
                        <x-input-label value="Akun yang dijumlahkan *" />
                        @foreach ($formTautan as $indeksTautan => $tautan)
                            <div wire:key="tautan-{{ $indeksTautan }}" class="grid items-center grid-cols-1 gap-2 sm:grid-cols-12">
                                <div class="sm:col-span-6">
                                    <x-select-input wire:model="formTautan.{{ $indeksTautan }}.akun_id" aria-label="Akun {{ $indeksTautan + 1 }}">
                                        <option value="">- pilih akun -</option>
                                        @foreach ($this->akunList as $idAkun => $labelAkun)
                                            <option value="{{ $idAkun }}">{{ $labelAkun }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                                <div class="sm:col-span-3">
                                    <x-select-input wire:model="formTautan.{{ $indeksTautan }}.sisi" aria-label="Sisi {{ $indeksTautan + 1 }}">
                                        @foreach (SusunanLaporan::SISI_LIST as $kodeSisi => $namaSisi)
                                            <option value="{{ $kodeSisi }}">{{ $namaSisi }}</option>
                                        @endforeach
                                    </x-select-input>
                                </div>
                                <div class="sm:col-span-2">
                                    <x-toggle wire:model.live="formTautan.{{ $indeksTautan }}.termasuk_turunan" :trueValue="true" :falseValue="false" label="+ turunan" />
                                </div>
                                <div class="sm:col-span-1">
                                    <x-icon-button color="gray" type="button" wire:click="hapusTautan({{ $indeksTautan }})" title="Lepas akun ini">
                                        <span class="sr-only">Lepas</span><x-ikon nama="kurangi" />
                                    </x-icon-button>
                                </div>
                            </div>
                        @endforeach
                        <x-secondary-button type="button" wire:click="tambahTautan" kecil><x-ikon nama="tambah" /> Tambah Akun</x-secondary-button>
                        <p class="text-xs text-muted dark:text-gray-400">Pilih akun header + <b>turunan</b> untuk menjumlahkan satu kelompok sekaligus (mis. 4.1.01 = semua pendapatan rawat jalan). Laba Rugi biasanya sisi <i>Mutasi bersih</i>, Neraca <i>Saldo akhir</i>.</p>
                    </div>
                @endif

                <x-input-error :messages="$errors->get('formKode')" />
                <div class="flex justify-end gap-2">
                    <x-secondary-button type="button" wire:click="tutupBaris"><x-ikon nama="batal" /> Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="simpanBaris" wire:loading.attr="disabled" wire:target="simpanBaris"><x-ikon nama="simpan" /> Simpan Baris</x-primary-button>
                </div>
            </div>
        </div>
    </x-modal>
</div>
