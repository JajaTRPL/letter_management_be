<!DOCTYPE html>
<html>

<head>
    <title>Token Reset Password</title>
</head>

<body style="font-family: 'Inter', sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; rounded-xl">
        <h2 style="color: #002d2d; text-align: center;">Reset Kata Sandi</h2>
        <p>Halo,</p>
        <p>Anda menerima email ini karena kami menerima permintaan reset kata sandi untuk akun Anda.</p>
        <div style="background-color: #f4fbfb; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0;">
            <p style="margin-bottom: 10px; font-weight: bold; color: #666;">Berikut adalah token reset password Anda:
            </p>
            <h1 style="letter-spacing: 5px; color: #002d2d; margin: 0;">{{ $token }}</h1>
        </div>
        <p>Token ini akan kedaluwarsa dalam 15 menit.</p>
        <p>Jika Anda tidak meminta reset kata sandi, abaikan email ini.</p>
        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
        <p style="font-size: 12px; color: #999; text-align: center;">
            &copy; {{ date('Y') }} Departemen Teknik Elektro dan Informatika. All rights reserved.
        </p>
    </div>
</body>

</html>