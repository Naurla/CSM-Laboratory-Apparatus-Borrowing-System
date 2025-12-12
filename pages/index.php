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
        /* 1. GLOBAL RESET & FONT */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            line-height: 1.6;
        }

        /* 2. HERO SECTION STYLING (Background Image & Overlay) */
        .hero-section {
            height: 100vh;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            
            /* === FINAL IMAGE PATH FIX: USING '../uploads/' === */
            /* The file is in 'pages/', so it must go UP ONE LEVEL (..) to access 'uploads/'. */
            background: url("../uploads/Western_Mindanao_State_University_College_of_Teacher_Education_(Normal_Road,_Baliwasan,_Zamboanga_City;_10-06-2023).jpg") no-repeat center center / cover;
            /* =================================================== */
            
            /* Optional: Add a subtle overlay to improve text readability over the background image */
            box-shadow: inset 0 0 0 1000px rgba(0, 0, 0, 0.2); 
        }

        /* 3. HERO CONTENT BOX (Dark Overlay for Text) */
        .hero-content-box {
            background-color: rgba(164, 4, 4, 0.8); /* CHANGED FROM rgba(139, 0, 0, 0.8) */
            color: white;
            padding: 40px 50px;
            border-radius: 10px;
            max-width: 800px;
            margin: 20px;
            z-index: 10;
        }

        .hero-headline {
            font-size: 3rem;
            margin-bottom: 15px;
            line-height: 1.1;
        }

        .hero-subheadline {
            font-size: 1.25rem;
            margin-bottom: 30px;
            font-weight: 300;
        }

        .system-title {
            font-size: 1rem;
            margin-top: 20px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 600;
        }

        /* 4. CTA BUTTONS */
        .cta-group {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
        }

        .cta-button {
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 700;
            transition: background-color 0.3s, transform 0.2s;
            text-align: center;
        }

        /* Primary CTA (Sign Up) */
        .cta-primary {
            background-color: #A40404; /* CHANGED FROM #8B0000 */
            color: white;
            border: 2px solid #A40404; /* CHANGED FROM #8B0000 */
        }

        .cta-primary:hover {
            background-color: #820303; /* CHANGED FROM #6a0000 */
            transform: translateY(-2px);
        }
        
        /* Secondary CTA (Login) */
        .cta-secondary {
            background-color: white;
            color: #A40404; /* CHANGED FROM #8B0000 */
            border: 2px solid #A40404; /* CHANGED FROM #8B0000 */
        }

        .cta-secondary:hover {
            background-color: #f0f0f0;
            transform: translateY(-2px);
        }

        .cta-button i {
            margin-right: 8px;
        }

        /* 5. SECTIONS BELOW THE FOLD (Optional, Placeholder for content) */
        .features-section {
            padding: 60px 20px;
            background-color: #fff;
            text-align: center;
        }
        
        .features-section h2 {
            margin-bottom: 30px;
            color: #A40404; /* CHANGED FROM #8B0000 */
        }
        
        /* 6. MEDIA QUERIES for Mobile Responsiveness */
        @media (max-width: 768px) {
            .hero-headline {
                font-size: 2.2rem;
            }
            .hero-subheadline {
                font-size: 1rem;
            }
            .cta-group {
                flex-direction: column;
                gap: 15px;
            }
            .cta-button {
                padding: 12px 20px;
            }
        }
    </style>
</head>
<body>

    <section class="hero-section">
        <div class="hero-content-box">
            <h1 class="hero-headline">Say Goodbye to Paperwork. Hello to Instant Lab Access.</h1>
            <p class="hero-subheadline">The fastest, most reliable way for students and faculty to borrow, track, and return science equipment at the CSM Laboratory. Get what you need and get back to discovery.</p>
            
            <div class="cta-group">
                <a href="signup.php" class="cta-button cta-primary">
                    <i class="fas fa-arrow-right"></i> Get Started Now
                </a>
                <a href="login.php" class="cta-button cta-secondary">
                    Login to Account
                </a>
            </div>

            <p class="system-title">CSM Laboratory Borrowing Apparatus</p>
        </div>
    </section>

    <section class="features-section">
        <h2>Key Features: Built for Efficiency</h2>
        <p>
            Digital reservations, automated notifications, real-time inventory tracking, and dedicated staff oversight.
            The future of lab management starts here.
        </p>
        </section>

    <footer style="text-align: center; padding: 20px; background-color: #333; color: white;">
        &copy; <?php echo date("Y"); ?> CSM Laboratory Borrowing Apparatus
    </footer>

</body>
</html>