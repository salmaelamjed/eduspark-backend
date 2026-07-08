<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Code de vérification EduSpark</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6;">
    <div style="max-width: 600px; margin: 0 auto;">
        <div style="background: #4F46E5; color: white; padding: 20px; text-align: center;">
            <h1 style="margin: 0;">EduSpark</h1>
        </div>
        
        <div style="padding: 30px; background: #f9f9f9;">
            <h2>Bonjour,</h2>
            
            <p>Voici votre code de vérification pour activer votre compte EduSpark :</p>
            
            <div style="font-size: 32px; letter-spacing: 10px; text-align: center; margin: 30px 0; 
                        padding: 20px; background: white; border: 2px dashed #4F46E5; border-radius: 10px;">
                {{ $code }}
            </div>
            
            <p>Ce code est valable pendant <strong>30 minutes</strong>.</p>
            
            <p>Si vous n'avez pas créé de compte sur EduSpark, vous pouvez ignorer cet email.</p>
            
            <p>Cordialement,<br>L'équipe EduSpark</p>
        </div>
        
        <div style="text-align: center; margin-top: 30px; color: #666; font-size: 12px;">
            <p>© {{ date('Y') }} EduSpark. Tous droits réservés.</p>
        </div>
    </div>
</body>
</html>