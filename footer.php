<style>
    footer {
        background-color: #3d7f8c;
        background-image: linear-gradient(135deg, #3d7f8c 0%, #2e6875 60%, #265a65 100%);
        color: #ffffff;
        padding: 60px 0 0 0;
        margin-top: auto;
        font-family: 'Poppins', sans-serif;
    }

    .footer-content-wrapper {
        max-width: 1100px;
        margin: 0 auto;
        padding: 0 40px;
        display: grid;
        grid-template-columns: 1.1fr 1.6fr 1.2fr;
        gap: 50px;
        align-items: start;
    }

    .footer-col {
        display: flex;
        flex-direction: column;
    }

    .brand-col {
        align-items: flex-start;
    }

    .footer-logo-icon {
        font-size: 3rem;
        margin-bottom: 8px;
        color: rgba(255,255,255,0.9);
    }

    .footer-brand-name {
        font-size: 2rem;
        font-weight: 600;
        line-height: 1;
        margin-bottom: 8px;
        color: white;
        letter-spacing: -0.5px;
    }

    .footer-brand-sub {
        font-size: 0.75rem;
        letter-spacing: 4px;
        text-transform: uppercase;
        color: rgba(255,255,255,0.65);
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 400;
    }

    .footer-brand-sub::before,
    .footer-brand-sub::after {
        content: "";
        display: block;
        width: 28px;
        height: 1px;
        background: rgba(255,255,255,0.4);
    }

    .footer-tagline {
        margin-top: 18px;
        font-size: 0.85rem;
        color: rgba(255,255,255,0.6);
        line-height: 1.6;
        font-weight: 300;
        max-width: 200px;
    }

    .footer-heading {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 3px;
        color: rgba(255,255,255,0.55);
        margin-bottom: 20px;
        margin-top: 0;
    }

    .location-block {
        margin-bottom: 20px;
    }

    .location-title {
        font-size: 0.95rem;
        font-weight: 700;
        margin-bottom: 4px;
        color: white;
    }

    .footer-text {
        font-size: 0.875rem;
        line-height: 1.6;
        font-weight: 300;
        color: rgba(255,255,255,0.7);
        margin: 0;
    }

    .footer-divider {
        width: 32px;
        height: 1px;
        background: rgba(255,255,255,0.25);
        margin: 18px 0;
    }

    .contact-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .contact-list li {
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 0.9rem;
        color: rgba(255,255,255,0.8);
    }

    .contact-icon {
        width: 32px;
        height: 32px;
        background: rgba(255,255,255,0.12);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.85rem;
        transition: background 0.2s;
    }

    .contact-list li:hover .contact-icon {
        background: rgba(255,255,255,0.22);
    }

    .contact-list a {
        color: rgba(255,255,255,0.85);
        text-decoration: none;
        transition: color 0.2s;
    }

    .contact-list a:hover {
        color: white;
        text-decoration: underline;
    }

    /* Bottom bar */
    .footer-bottom {
        margin-top: 48px;
        border-top: 1px solid rgba(255,255,255,0.12);
        padding: 18px 40px;
        max-width: 1100px;
        margin-left: auto;
        margin-right: auto;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.8rem;
        color: rgba(255,255,255,0.4);
    }

    .footer-bottom-bar {
        background: rgba(0,0,0,0.1);
        padding: 0;
    }

    @media (max-width: 900px) {
        .footer-content-wrapper {
            grid-template-columns: 1fr 1fr;
            gap: 36px;
        }
        .brand-col {
            grid-column: 1 / -1;
        }
    }

    @media (max-width: 600px) {
        .footer-content-wrapper {
            grid-template-columns: 1fr;
            padding: 0 24px;
            text-align: center;
        }
        .brand-col {
            align-items: center;
            grid-column: auto;
        }
        .footer-tagline { max-width: 100%; }
        .footer-bottom {
            flex-direction: column;
            gap: 6px;
            text-align: center;
            padding: 18px 24px;
        }
    }
</style>

<footer>
    <div class="footer-content-wrapper">

        <!-- Brand -->
        <div class="footer-col brand-col">
            <i class="fas fa-water footer-logo-icon"></i>
            <div class="footer-brand-name">CheckMates</div>
            <div class="footer-brand-sub">AGOS</div>
            <p class="footer-tagline">We create unforgettable experiences for our guests.</p>
        </div>

        <!-- Locations -->
        <div class="footer-col">
            <h4 class="footer-heading">Our Locations</h4>
            <div class="location-block">
                <div class="location-title">Hacienda Emiart</div>
                <p class="footer-text">Purok 7, Barangay Tibangan,<br>Bustos, Bulacan</p>
            </div>
            <div class="footer-divider"></div>
            <div class="location-block">
                <div class="location-title">Emiart Resorts I, II &amp; III</div>
                <p class="footer-text">106–114 Pasig St, Maypajo,<br>Caloocan, Metro Manila</p>
            </div>
        </div>

        <!-- Contact -->
        <div class="footer-col">
            <h4 class="footer-heading">Reservations</h4>
            <ul class="contact-list">
                <li>
                    <span class="contact-icon"><i class="fas fa-phone-alt"></i></span>
                    <span>09331766862</span>
                </li>
                <li>
                    <span class="contact-icon"><i class="fas fa-phone-alt"></i></span>
                    <span>09327815012</span>
                </li>
                <li>
                    <span class="contact-icon"><i class="fas fa-envelope"></i></span>
                    <span>emiartresort@yahoo.com</span>
                </li>
                <li>
                    <span class="contact-icon"><i class="fab fa-facebook-square"></i></span>
                    <a href="https://www.facebook.com/emiartprivateresort" target="_blank" rel="noopener noreferrer">Emiart Private Resorts</a>
                </li>
            </ul>
        </div>

    </div>
</footer>
</body>
</html>