<?php
/**
 * WorkPay.uz Marketing Page
 * Designed for Apple App Store guidelines
 */
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WorkPay.uz - Professional Davomat Boshqaruvi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #007AFF;
            --secondary: #5856D6;
            --accent: #EBF5FF;
            --bg-body: #FFFFFF;
            --text-main: #1D1D1F;
            --text-muted: #86868B;
            --border: #D2D2D7;
            --radius: 20px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-body: #000000;
                --text-main: #F5F5F7;
                --text-muted: #A1A1A6;
                --border: #38383A;
                --accent: #1C1C1E;
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
            overflow-x: hidden;
        }

        h1, h2, h3 {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        /* Navbar */
        nav {
            padding: 1.5rem 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logo span {
            color: var(--text-main);
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 0.95rem;
            margin-left: 2rem;
            transition: color 0.2s;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        /* Hero */
        .hero {
            padding: 10rem 0 5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            background: radial-gradient(circle at top, var(--accent) 0%, var(--bg-body) 70%);
        }

        .badge {
            background: var(--accent);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 100px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .hero h1 {
            font-size: 4rem;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            max-width: 800px;
        }

        .hero p {
            font-size: 1.4rem;
            color: var(--text-muted);
            max-width: 650px;
            margin-bottom: 3rem;
        }

        .cta-group {
            display: flex;
            gap: 1rem;
            margin-bottom: 4rem;
        }

        .btn {
            padding: 1rem 2rem;
            border-radius: 100px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 10px 20px -5px rgba(0, 122, 255, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -10px rgba(0, 122, 255, 0.4);
        }

        /* Features */
        .features {
            padding: 5rem 0;
            background: var(--bg-body);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            padding: 2.5rem;
            border-radius: var(--radius);
            background: var(--bg-body);
            border: 1px solid var(--border);
            transition: transform 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            display: block;
        }

        .feature-card h3 {
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
        }

        .feature-card p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Mockup */
        .mockup-container {
            width: 100%;
            max-width: 900px;
            margin: 0 auto 5rem;
            position: relative;
        }

        .mockup-img {
            width: 100%;
            border-radius: 30px;
            box-shadow: 0 30px 60px -15px rgba(0,0,0,0.2);
        }

        /* Testimonials/Trust */
        .trust-section {
            text-align: center;
            padding: 5rem 0;
            border-top: 1px solid var(--border);
        }

        .trust-section h2 {
            font-size: 2.5rem;
            margin-bottom: 3rem;
        }

        /* Footer */
        footer {
            padding: 5rem 0 3rem;
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .footer-links {
            margin-bottom: 2rem;
        }

        .footer-links a {
            color: var(--text-muted);
            text-decoration: none;
            margin: 0 1rem;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        @media (max-width: 768px) {
            .hero h1 { font-size: 2.5rem; }
            .hero p { font-size: 1.1rem; }
            .cta-group { flex-direction: column; width: 100%; }
            .btn { justify-content: center; }
        }

        /* Animations */
        [data-aos] {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .visible {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>

    <div class="container">
        <nav>
            <a href="#" class="logo">WorkPay<span>.uz</span></a>
            <div class="nav-links">
                <a href="#features">Xususiyatlar</a>
                <a href="support.php">Qo'llab-quvvatlash</a>
            </div>
        </nav>
    </div>

    <section class="hero">
        <div class="container">
            <span class="badge">Yangi: Versiya 1.0.0 endi App Store'da!</span>
            <h1>Ish vaqtini boshqarishning eng oson yo'li.</h1>
            <p>WorkPay.uz — bu korxonangiz uchun mukammal davomat va ish stajini hisoblash tizimi. GPS, foto-fiksatsiya va real vaqtda hisobotlar bitta ilovada.</p>
            
            <div class="cta-group">
                <a href="#" class="btn btn-primary">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M17.5,12c0,2.1-1.4,3.9-3.3,4.6c-0.2,0.1-0.2,0.4,0,0.5c0.5,0.2,1,0.3,1.6,0.3c2.5,0,4.5-2,4.5-4.5c0-2.3-1.7-4.2-3.9-4.5 c-0.2,0-0.4,0.2-0.4,0.4C16.1,9.8,17.5,10.7,17.5,12z"/></svg> 
                    App Store'dan yuklash
                </a>
            </div>

            <div class="mockup-container">
                <img src="web_assets/hero.png" alt="WorkPay.uz Dashboard" class="mockup-img">
            </div>
        </div>
    </section>

    <section id="features" class="features">
        <div class="container">
            <div class="features-grid">
                <div class="feature-card">
                    <img src="web_assets/gps.png" alt="GPS" style="width: 100%; border-radius: 12px; margin-bottom: 20px;">
                    <h3>GPS Tekshiruvi</h3>
                    <p>Xodimlar faqat belgilangan filial hududida ishga kelishlari mumkin. Masofani aniq radius bo'yicha nazorat qiling.</p>
                </div>
                <div class="feature-card">
                    <img src="web_assets/photo.png" alt="Photo ID" style="width: 100%; border-radius: 12px; margin-bottom: 20px;">
                    <h3>Foto-fiksatsiya</h3>
                    <p>Har bir check-in vaqtida suratga olish orqali xodimning shaxsini tasdiqlang. Firibgarlikning oldini oling.</p>
                </div>
                <div class="feature-card">
                    <img src="web_assets/reports.png" alt="Reports" style="width: 100%; border-radius: 12px; margin-bottom: 20px;">
                    <h3>To'liq Hisobotlar</h3>
                    <p>Oylik ish vaqti, kechikishlar va uzoqlashishlar bo'yicha PDF/Excel hisobotlarni avtomatik oling.</p>
                </div>
                <div class="feature-card">
                    <span class="feature-icon">☁️</span>
                    <h3>Bulutli Saqlash</h3>
                    <p>Barcha ma'lumotlar xavfsiz serverda saqlanadi va ularga dunyoning istalgan nuqtasidan kirish imkoniyati mavjud.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="trust-section">
        <div class="container">
            <h2>Minglab xodimlar ishonchi.</h2>
            <p style="color: var(--text-muted); font-size: 1.2rem;">Sizning biznesingiz uchun ishonchli va shaffof davomat tizimi.</p>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-links">
                <a href="support.php">Yordam</a>
                <a href="privacy-policy.html">Maxfiylik Siyosati</a>
            </div>
            <p>&copy; <?php echo date("Y"); ?> WorkPay.uz by Vita Forever. Barcha huquqlar himoyalangan.</p>
            <p style="margin-top: 1rem; font-size: 0.8rem;">Uzbekistan, Tashkent</p>
        </div>
    </footer>

    <script>
        // Simple scroll animation trigger
        window.addEventListener('scroll', () => {
            const cards = document.querySelectorAll('.feature-card');
            cards.forEach(card => {
                const rect = card.getBoundingClientRect();
                if(rect.top < window.innerHeight - 50) {
                    card.classList.add('visible');
                }
            });
        });
    </script>
</body>
</html>
