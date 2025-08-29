<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Plagiarism Receipt</title>
    <style>
        * {
            box-sizing: border-box
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #222;
            margin: 0;
            padding: 24px
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px
        }

        .brand {
            font-weight: 700;
            font-size: 18px;
            color: #0d6efd
        }

        .meta {
            font-size: 12px;
            line-height: 1.4;
            text-align: right
        }

        .card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 14px
        }

        h1 {
            font-size: 18px;
            margin: 0 0 8px
        }

        h2 {
            font-size: 15px;
            margin: 0 0 8px
        }

        .muted {
            color: #6c757d
        }

        .pill {
            display: inline-block;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 999px;
            color: #fff
        }

        .bar {
            background: #e9ecef;
            border-radius: 999px;
            height: 12px;
            width: 100%;
            overflow: hidden
        }

        .bar>span {
            display: block;
            height: 100%
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px
        }

        th,
        td {
            padding: 8px;
            border-bottom: 1px solid #f1f3f5;
            vertical-align: top
        }

        th {
            background: #f8f9fa;
            text-align: left
        }

        .small {
            font-size: 11px
        }

        .legend-box {
            display: flex;
            flex-wrap: wrap;
            gap: 8px
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px
        }

        .swatch {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            border: 1px solid #ddd
        }

        .doc-box {
            border: 1px dashed #cbd5e1;
            padding: 12px;
            border-radius: 8px;
            background: #fff
        }
    </style>
</head>

<body>
    <div class="header">
        <div>
            <div class="brand">Plagiarism Checker — Receipt</div>
            <div class="muted small">Gaya Turnitin • Sorot teks yang terindikasi</div>
        </div>
        <div class="meta">
            Dibuat: {{ $generated_at->format('d M Y H:i') }} WIB<br>
            Pengguna: {{ $user->name }}<br>
            Dokumen: <strong>{{ $document->title }}</strong>
        </div>
    </div>

    <div class="card">
        <h1>Ringkasan Similarity (Union Coverage)</h1>
        <div style="display:flex; gap:14px; align-items:center">
            <div>
                <div class="muted">Overall Similarity</div>
                <div style="font-size:34px; font-weight:700; color:{{ $risk['hex'] }}">{{ number_format($overall, 2) }}%
                </div>
                <span class="pill" style="background:{{ $risk['hex'] }}">{{ $risk['label'] }}</span>
                <div class="small muted" style="margin-top:6px">
                    Ditutup oleh gabungan sumber: {{ $covered_count }}/{{ $token_count }} token.
                </div>
            </div>
            <div style="flex:1">
                <div class="bar"><span
                        style="width: {{ min(100, max(0, $overall)) }}%; background: {{ $risk['hex'] }}"></span></div>
                <div class="small muted" style="margin-top:6px">
                    Warna di bawah menandai bagian teks yang terindikasi dan sumbernya.
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Legenda Sumber</h2>
        @if (empty($sources))
            <div class="small muted">Tidak ada sumber terdeteksi.</div>
        @else
            <div class="legend-box">
                @foreach ($sources as $i => $s)
                    <div class="legend-item">
                        <span class="swatch" style="background: {{ $s['color'] ?? '#ffd54f' }}"></span>
                        <div>
                            <div><strong>[S{{ $i + 1 }}]</strong> {{ $s['title'] }}</div>
                            <div class="small">
                                Kontribusi: <strong>{{ number_format($s['contrib_pct'], 2) }}%</strong>
                                · Jaccard {{ $s['jaccard'] ?? 0 }}% · Cosine {{ $s['cosine'] ?? 0 }}%<br>
                                @if (!empty($s['pdf_url']))
                                    <a href="{{ $s['pdf_url'] }}">PDF</a> ·
                                @endif
                                @if (!empty($s['url']))
                                    <a href="{{ $s['url'] }}">Halaman</a>
                                @endif
                            </div>
                            @if (!empty($s['snippets']))
                                <div class="small" style="margin-top:4px">
                                    @foreach ($s['snippets'] as $snip)
                                        <div>— {!! $snip !!}</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Dokumen yang Diunggah (Teks Disorot)</h2>
        <div class="doc-box">
            {!! $colored_html !!}
        </div>
        <div class="small muted" style="margin-top:6px">
            Setiap potongan berwarna menunjuk pada sumber di legenda. Area tanpa warna = tidak terindikasi.
        </div>
    </div>

    <div class="card">
        <h2>Catatan</h2>
        <div class="small">
            • Perhitungan overall berdasarkan cakupan token yang beririsan dengan sumber (union coverage).<br>
            • Penentuan potongan mengandalkan n-gram 3 kata (shingling) dan penggabungan interval kontigu.<br>
            • Angka Jaccard/Cosine ditampilkan sebagai pendamping, bukan penentu utama highlight.<br>
            • Verifikasi manual tetap disarankan.
        </div>
    </div>
</body>

</html>
