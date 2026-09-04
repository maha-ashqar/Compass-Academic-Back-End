<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
</head>

<body style="margin:0;padding:0;background:#f4f8fb;font-family:Arial,Helvetica,sans-serif;color:#0b3553;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f8fb;padding:40px 15px;">
    <tr>
        <td align="center">

            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 30px rgba(11,53,83,0.08);">

                <tr>
                    <td style="background:#07566f;padding:28px 40px;text-align:center;">
                        <div style="font-size:26px;font-weight:700;color:#ffffff;">
                            C<span style="color:#16a8df;">o</span>mpass
                            <span style="color:#16a8df;">Academy</span>
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:42px 45px;">

                        <h1 style="margin:0 0 18px;font-size:28px;line-height:1.3;color:#082f4b;">
                            Reset your password
                        </h1>

                        <p style="margin:0 0 18px;font-size:16px;line-height:1.7;color:#5b7083;">
                            Hi {{ $name }},
                        </p>

                        <p style="margin:0 0 28px;font-size:16px;line-height:1.7;color:#5b7083;">
                            We received a request to reset your Compass Academy password.
                            Use the verification code below to continue.
                        </p>

                        <div style="background:#eef8fc;border:1px solid #ccecf7;border-radius:14px;padding:25px;text-align:center;margin-bottom:24px;">

                            <div style="font-size:13px;text-transform:uppercase;letter-spacing:1.5px;color:#60869a;margin-bottom:12px;">
                                Verification code
                            </div>

                            <div style="font-size:38px;font-weight:700;letter-spacing:9px;color:#07566f;">
                                {{ $code }}
                            </div>

                        </div>

                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ $resetUrl }}"
                                       style="display:inline-block;background:#0798df;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 32px;border-radius:10px;">
                                        Use this code
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#5b7083;">
                            This code will expire in
                            <strong style="color:#082f4b;">15 minutes</strong>.
                        </p>

                        <div style="background:#fff8e8;border-radius:10px;padding:16px 18px;margin-bottom:28px;">
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#7b622d;">
                                If you did not request a password reset, you can safely ignore this email.
                                Your password will remain unchanged.
                            </p>
                        </div>

                        <p style="margin:0;font-size:15px;line-height:1.7;color:#5b7083;">
                            Best regards,<br>
                            <strong style="color:#082f4b;">Compass Academy Team</strong>
                        </p>

                    </td>
                </tr>

                <tr>
                    <td style="background:#f7fafc;padding:22px 30px;text-align:center;border-top:1px solid #e8f0f4;">
                        <p style="margin:0;font-size:12px;line-height:1.6;color:#8ba0ad;">
                            © {{ date('Y') }} Compass Academy. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
