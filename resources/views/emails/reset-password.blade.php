<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation de mot de passe</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #4f46e5; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background-color: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; background-color: #4f46e5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 14px; }
        .code { background-color: #f3f4f6; padding: 10px; border-radius: 6px; font-family: monospace; font-size: 18px; text-align: center; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>EduSpark</h1>
            <p>Réinitialisation de mot de passe</p>
        </div>
        
        <div class="content">
            <h2>Bonjour,</h2>
            
            <p>Nous avons reçu une demande de réinitialisation de mot de passe pour votre compte EduSpark.</p>
            
            <p>Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email en toute sécurité.</p>
            
            <p><strong>Pour réinitialiser votre mot de passe, cliquez sur le bouton ci-dessous :</strong></p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ url(config('app.url') . '/reset-password?token=' . $token . '&email=' . urlencode($email)) }}" 
                   class="button">
                   Réinitialiser mon mot de passe
                </a>
            </div>
            
            <p>Ou copiez-collez ce lien dans votre navigateur :</p>
            
            <div class="code">
                {{ url(config('app.url') . '/reset-password?token=' . $token . '&email=' . urlencode($email)) }}
            </div>
            
            <p><strong>Ce lien expire dans 60 minutes.</strong></p>
            
            <div class="footer">
                <p>Merci,<br>L'équipe EduSpark</p>
                <p><small>Si vous rencontrez des problèmes, contactez-nous à : support@eduspark.ma</small></p>
                <p><small>© {{ date('Y') }} EduSpark. Tous droits réservés.</small></p>
            </div>
        </div>
    </div>
</body>
</html>