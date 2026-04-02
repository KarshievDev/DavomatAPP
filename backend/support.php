<?php
/**
 * WorkPay.uz Support Page
 * Designed for Apple App Store guidelines
 */
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Qo'llab-quvvatlash - WorkPay.uz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #007AFF;
            --secondary: #5856D6;
            --bg-body: #F5F5F7;
            --bg-card: #FFFFFF;
            --text-main: #1D1D1F;
            --text-muted: #86868B;
            --border: #D2D2D7;
            --radius: 12px;
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-body: #000000;
                --bg-card: #1C1C1E;
                --text-main: #F5F5F7;
                --text-muted: #A1A1A6;
                --border: #38383A;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3 {
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }

        /* Hero Section */
        .hero {
            text-align: center;
            padding: 4rem 0 3rem;
        }

        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero p {
            font-size: 1.25rem;
            color: var(--text-muted);
            max-width: 600px;
            margin: 0 auto;
        }

        /* FAQ Section */
        .section-title {
            font-size: 1.5rem;
            margin: 2rem 0 1.5rem;
            text-align: center;
        }

        .faq-grid {
            display: grid;
            gap: 1rem;
            margin-bottom: 4rem;
        }

        .faq-item {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            transition: transform 0.2s ease;
        }

        .faq-item:hover {
            transform: translateY(-2px);
        }

        .faq-item h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }

        .faq-item p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Contact Section */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 4rem;
        }

        .contact-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .contact-card .icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            display: block;
        }

        .contact-card h3 {
            margin-bottom: 0.5rem;
        }

        .contact-card p {
            margin-bottom: 1.5rem;
            color: var(--text-muted);
        }

        .btn {
            display: inline-block;
            background: var(--primary);
            color: white;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 20px;
            font-weight: 500;
            transition: opacity 0.2s;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .btn-secondary {
            background: #24292F;
        }

        /* Footer */
        footer {
            text-align: center;
            padding: 4rem 0 2rem;
            border-top: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .footer-links {
            margin-bottom: 1rem;
        }

        .footer-links a {
            color: var(--primary);
            text-decoration: none;
            margin: 0 10px;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-up {
            animation: fadeIn 0.6s ease-out forwards;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }

        @media (max-width: 600px) {
            .hero h1 { font-size: 2rem; }
            .hero p { font-size: 1rem; }
        }
    </style>
</head>
<body>

    <div class="container">
        <header class="hero">
            <h1 class="animate-up">Sizga qanday yordam bera olamiz?</h1>
            <p class="animate-up delay-1">WorkPay.uz jamoasi sizning har bir savolingiz va muammoingizga yechim topish uchun tayyor.</p>
        </header>

        <h2 class="section-title animate-up delay-2">Tez-tez so'raladigan savollar (FAQ)</h2>
        <div class="faq-grid animate-up delay-2">
            <div class="faq-item">
                <h3>Kirishda muammo bo'lsa nima qilish kerak?</h3>
                <p>Agar parolingizni esdan chiqargan bo'lsangiz yoki kira olmayotgan bo'lsangiz, tashkilotingiz administratoriga murojaat qiling. Ular parolingizni tiklab berishlari mumkin.</p>
            </div>
            <div class="faq-item">
                <h3>Joylashuv aniqlanmayapti?</h3>
                <p>Ilovaga joylashuv ma'lumotlaridan (GPS) foydalanishga ruxsat berganingizni tekshiring. Telefon sozlamalarida "Privacy -> Location Services" bo'limida WorkPay.uz uchun "Always" yoki "While using" ruxsatini yoqing.</p>
            </div>
            <div class="faq-item">
                <h3>Kamera bilan bog'liq muammo?</h3>
                <p>Check-in/Check-out vaqtida kamera ishlamasa, ilovaning kamera ruxsatlarini tekshiring. Shuningdek, kamera linzasi toza ekanligiga ishonch hosil qiling.</p>
            </div>
            <div class="faq-item">
                <h3>Internet o'chiq bo'lsa nima bo'ladi?</h3>
                <p>Ilova offlayn rejimda ishlay oladi. Ma'lumotlar qurilmada saqlanadi va internet paydo bo'lganda serverga yuboriladi.</p>
            </div>
        </div>

        <h2 class="section-title animate-up delay-3">Biz bilan bog'laning</h2>
        <div class="contact-grid animate-up delay-3">
            <div class="contact-card">
                <span class="icon">📧</span>
                <h3>Elektron pochta</h3>
                <p>Bizga xat yozing, 24 soat ichida javob beramiz.</p>
                <a href="mailto:karshievdev@gmail.com" class="btn">Xat yozish</a>
            </div>
            <div class="contact-card">
                <span class="icon">✈️</span>
                <h3>Telegram Support</h3>
                <p>Tezkor yordam olish uchun Telegram botimizga yozing.</p>
                <a href="https://t.me/karshiev_dev" class="btn btn-secondary">Telegramga o'tish</a>
            </div>
        </div>

        <footer>
            <div class="footer-links">
                <a href="privacy-policy.html">Maxfiylik Siyosati</a>
            </div>
            <p>&copy; <?php echo date("Y"); ?> WorkPay.uz by Vita Forever. Barcha huquqlar himoyalangan.</p>
            <p style="margin-top: 10px; font-size: 0.8rem;">Versiya: 1.0.0 (Build 20260319)</p>
        </footer>
    </div>

</body>
</html>
