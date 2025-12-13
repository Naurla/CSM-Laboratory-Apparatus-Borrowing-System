<?php
// pages/index.php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSM Laboratory Borrowing Apparatus - Home</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* CSS VARIABLES for better theme management */
        :root {
            --primary-color: #A40404; /* Dark Red / Maroon (WMSU-inspired) */
            --secondary-color: #f4b400; /* Gold/Yellow Accent */
            --text-dark: #2c3e50;
            --text-light: #ecf0f1;
            --background-light: #f8f9fa;
            --background-dark: #34495e;
        }

        /* 1. GLOBAL RESET & FONT */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-dark);
            line-height: 1.6;
            background-color: var(--background-light);
            /* Important for parallax to work on the hero section */
            perspective: 1px;
            transform-style: preserve-3d;
        }

        /* 2. NAVIGATION BAR */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 50px;
            background-color: rgba(255, 255, 255, 0); /* [EFFECT] Starts transparent */
            box-shadow: none; /* [EFFECT] Starts with no shadow */
            transition: all 0.3s ease-in-out; /* [EFFECT] Transition for scroll effect */
            z-index: 1000;
        }

        /* [EFFECT] Navbar state when scrolled */
        .navbar.scrolled {
            background-color: rgba(255, 255, 255, 0.98); 
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .navbar .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .navbar .logo i {
            margin-right: 10px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-dark);
            margin-left: 25px;
            font-weight: 600;
            padding: 8px 15px;
            border-radius: 5px;
            transition: color 0.3s, background-color 0.3s;
        }

        .nav-links a:hover {
            color: var(--primary-color);
        }

        .nav-login-btn {
            border: 2px solid var(--primary-color);
            color: var(--primary-color) !important;
        }

        .nav-signup-btn {
            background-color: var(--primary-color);
            color: white !important;
            margin-left: 10px;
        }
        
        /* 3. HERO SECTION STYLING */
        .hero-section {
            height: 100vh;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            padding-top: 60px;
            
            /* [EFFECT] CSS Parallax */
            background: url("../uploads/Western_Mindanao_State_University_College_of_Teacher_Education_(Normal_Road,_Baliwasan,_Zamboanga_City;_10-06-2023).jpg") no-repeat center center / cover;
            background-attachment: fixed; /* THIS creates the Parallax effect */
            
            /* Softer Overlay for better text contrast and professional look */
            box-shadow: inset 0 0 0 1000px rgba(0, 0, 0, 0.3);
        }

        /* 4. HERO CONTENT BOX */
        .hero-content-box {
            background-color: rgba(164, 4, 4, 0.9);
            color: var(--text-light);
            padding: 50px 60px;
            border-radius: 12px;
            max-width: 900px;
            margin: 20px;
            z-index: 10;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            
            /* [EFFECT] Subtle initial animation */
            animation: fadeIn 1.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .system-title {
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 10px;
        }

        .hero-headline {
            font-size: 3.5rem;
            margin-bottom: 20px;
            line-height: 1.1;
        }

        .hero-subheadline {
            font-size: 1.35rem;
            margin-bottom: 40px;
            font-weight: 300;
        }

        /* 5. CTA BUTTONS */
        .cta-group {
            display: flex;
            justify-content: center;
            gap: 25px;
            margin-top: 30px;
        }

        .cta-button {
            text-decoration: none;
            padding: 18px 35px;
            border-radius: 50px;
            font-size: 1.15rem;
            font-weight: 700;
            transition: all 0.3s ease;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        /* Primary CTA (Get Started/Signup) */
        .cta-primary {
            background-color: white;
            color: var(--primary-color);
            border: 2px solid white;
        }

        .cta-primary:hover {
            background-color: var(--background-light);
            transform: translateY(-3px) scale(1.02); /* [EFFECT] Slight scale on hover */
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }
        
        /* Secondary CTA (Login) */
        .cta-secondary {
            background-color: transparent;
            color: white;
            border: 2px solid white;
        }

        .cta-secondary:hover {
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        .cta-button i {
            margin-right: 10px;
        }

        /* 6. FEATURES SECTION (Expanded) */
        .features-section {
            padding: 80px 20px;
            background-color: var(--background-light);
            text-align: center;
        }
        
        .features-section h2 {
            font-size: 2.2rem;
            margin-bottom: 50px;
            color: var(--primary-color);
        }

        .feature-grid {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            max-width: 1200px;
            margin: 0 auto;
        }

        .feature-item {
            flex-basis: calc(33% - 20px);
            padding: 30px;
            border-radius: 10px;
            background-color: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.4s, box-shadow 0.4s;
            overflow: hidden;

            /* [EFFECT] Initial state for Scroll Fade-In */
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 0.6s ease-out, transform 0.6s ease-out;
        }

        /* [EFFECT] Final state for Scroll Fade-In */
        .feature-item.show {
            opacity: 1;
            transform: translateY(0);
        }

        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1), 0 0 15px var(--secondary-color); /* [EFFECT] Subtle glow on hover */
        }

        .feature-item i {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 15px;
            transition: color 0.3s;
        }

        .feature-item:hover i {
            color: var(--primary-color); /* [EFFECT] Icon color change on hover */
        }

        .feature-item h3 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        /* 7. FOOTER */
        footer {
            text-align: center;
            padding: 30px 20px;
            background-color: var(--background-dark);
            color: var(--text-light);
            font-size: 0.9rem;
        }


        /* 8. MEDIA QUERIES */
        @media (max-width: 992px) {
            .navbar {
                padding: 15px 20px;
            }
            .hero-headline {
                font-size: 2.8rem;
            }
            .hero-subheadline {
                font-size: 1.1rem;
            }
            .feature-item {
                flex-basis: calc(50% - 15px);
            }
            .nav-links a:not(.nav-login-btn, .nav-signup-btn) {
                display: none;
            }
        }
        
        @media (max-width: 576px) {
            .hero-content-box {
                padding: 30px;
            }
            .hero-headline {
                font-size: 2rem;
            }
            .cta-group {
                flex-direction: column;
                gap: 15px;
            }
            .cta-button {
                padding: 15px 20px;
            }
            .navbar .logo {
                font-size: 1.2rem;
            }
            .feature-item {
                flex-basis: 100%;
            }
        }
    </style>
</head>
<body>

    <header class="navbar">
        <div class="logo">
            <span><i class="fas fa-flask"></i> CSM Lab Apparatus</span>
        </div>
        <nav class="nav-links">
            <a href="#features">Features</a>
            <a href="login.php" class="nav-login-btn">Login</a>
            <a href="signup.php" class="nav-signup-btn">Sign Up</a>
        </nav>
    </header>


    <section class="hero-section">
        <div class="hero-content-box">
            <p class="system-title">CSM LABORATORY APPARATUS BORROWING SYSTEM</p>
            <h1 class="hero-headline">Seamless Access. Instant Tracking. Maximize Lab Efficiency.</h1>
            <p class="hero-subheadline">The fastest, most reliable way for students and faculty to borrow, track, and return science equipment at the CSM Laboratory. Get what you need and get back to discovery.</p>
            
            <div class="cta-group">
                <a href="signup.php" class="cta-button cta-primary">
                    <i class="fas fa-arrow-right"></i> Get Started Now
                </a>
                <a href="login.php" class="cta-button cta-secondary">
                    <i class="fas fa-sign-in-alt"></i> Login to Account
                </a>
            </div>
        </div>
    </section>

    <section class="features-section" id="features">
        <h2>Key Features: Built for Efficiency</h2>
        <div class="feature-grid">
            
            <div class="feature-item">
                <i class="fas fa-calendar-check"></i>
                <h3>Digital Reservations</h3>
                <p>Reserve apparatuses online before your class starts to ensure availability, eliminating long queues and paperwork.</p>
            </div>
            
            <div class="feature-item">
                <i class="fas fa-bell"></i>
                <h3>Automated Notifications</h3>
                <p>Receive email and system alerts for overdue items, approval status, and upcoming return deadlines.</p>
            </div>
            
            <div class="feature-item">
                <i class="fas fa-chart-bar"></i>
                <h3>Real-Time Inventory</h3>
                <p>View the current stock level and condition of all lab equipment instantly. No more guessing what's available.</p>
            </div>
        </div>
    </section>

    <footer>
        <p>CSM Laboratory Borrowing Apparatus is proudly a service of WMSU College of Science and Mathematics.</p>
        <p>&copy; <?php echo date("Y"); ?> CSM Laboratory Borrowing Apparatus</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const navbar = document.querySelector('.navbar');
            const featureItems = document.querySelectorAll('.feature-item');
            
            // 1. Sticky Header Effect
            const handleScroll = () => {
                if (window.scrollY > 50) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            };
            
            window.addEventListener('scroll', handleScroll);
            handleScroll(); // Check on load in case the user reloads while scrolled down

            // 2. Scroll Fade-In Effect (using Intersection Observer)
            const observerOptions = {
                root: null,
                rootMargin: '0px',
                threshold: 0.2 // Trigger when 20% of the item is visible
            };

            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('show');
                        observer.unobserve(entry.target); // Stop observing once it's visible
                    }
                });
            }, observerOptions);

            featureItems.forEach(item => {
                observer.observe(item);
            });
        });
    </script>

</body>
</html>