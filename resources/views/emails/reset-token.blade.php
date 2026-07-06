<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kode Verifikasi Reset Kata Sandi</title>
</head>
<body style="margin:0;background:#f3f6f5;color:#17332f;font-family:Arial,Helvetica,sans-serif;">
    <div style="padding:32px 12px;">
        <div style="max-width:600px;margin:0 auto;overflow:hidden;border:1px solid #dce7e4;border-radius:16px;background:#ffffff;">
            <div style="padding:24px;background:#075e54;color:#ffffff;text-align:center;">
                <div style="font-size:20px;font-weight:700;">Sistem Persuratan DTEDI</div>
                <div style="margin-top:6px;font-size:13px;line-height:1.5;color:#d7f3ee;">
                    Departemen Teknik Elektro dan Informatika
                </div>
            </div>

            <div style="padding:28px;">
                <p style="margin:0 0 16px;">Yth. Pengguna Sistem Persuratan DTEDI,</p>
                <p style="margin:0 0 20px;line-height:1.65;">
                    Kami menerima permintaan untuk mereset kata sandi akun Anda pada
                    Sistem Persuratan Departemen Teknik Elektro dan Informatika.
                </p>

                <div style="padding:22px;border-radius:12px;background:#eef8f6;text-align:center;">
                    <div style="margin-bottom:10px;font-size:13px;font-weight:700;color:#43625d;">
                        KODE VERIFIKASI
                    </div>
                    <div style="font-size:34px;font-weight:800;letter-spacing:8px;color:#075e54;">
                        {{ $code }}
                    </div>
                </div>

                <p style="margin:20px 0 0;line-height:1.65;">
                    Kode ini berlaku selama <strong>{{ $expiryMinutes }} menit</strong>.
                </p>

                <div style="margin-top:20px;padding:16px;border-left:4px solid #d97706;background:#fff8e7;color:#704b08;line-height:1.6;">
                    Jangan membagikan kode ini kepada siapa pun, termasuk pihak yang
                    mengatasnamakan administrator.
                </div>

                <p style="margin:20px 0 0;line-height:1.65;">
                    Jika Anda tidak meminta reset kata sandi, abaikan email ini.
                    Kata sandi akun Anda tidak akan berubah selama kode ini tidak digunakan.
                </p>

                <p style="margin:24px 0 0;line-height:1.6;">
                    Hormat kami,<br>
                    <strong>Sistem Persuratan DTEDI</strong><br>
                    Departemen Teknik Elektro dan Informatika
                </p>
            </div>

            <div style="padding:16px 24px;background:#edf3f1;color:#58706c;text-align:center;font-size:12px;line-height:1.5;">
                Pesan otomatis ini tidak memerlukan balasan.
            </div>
        </div>
    </div>
</body>
</html>
