<style>
    footer {
        background-color: #5e97a3 !important;
        color: #ffffff !important;
        padding: 60px 0 50px 0;
        margin-top: auto;
        backdrop-filter: none !important;
    }

    .footer-content-wrapper {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 40px;
        display: grid;
        grid-template-columns: 1.2fr 1.5fr 0.8fr 1.2fr;
        gap: 40px;
        text-align: left;
    }

    .footer-col {
        display: flex;
        flex-direction: column;
    }

    .brand-col {
        align-items: flex-start;
    }
    
    .footer-logo-icon {
        font-size: 3.5rem;
        margin-bottom: 5px;
        color: white;
    }
    
    .footer-brand-name {
        font-size: 2rem;
        font-weight: 400;
        line-height: 1;
        margin-bottom: 5px;
        color: white;
    }
    
    .footer-brand-sub {
        font-size: 0.9rem;
        letter-spacing: 3px;
        text-transform: uppercase;
        position: relative;
        width: 100%;
        text-align: center;
        color: white;
    }
    
    .footer-brand-sub::before, .footer-brand-sub::after {
        content: "";
        position: absolute;
        top: 50%;
        width: 30px;
        height: 1px;
        background: rgba(255,255,255,0.5);
    }
    .footer-brand-sub::before { left: 10px; }
    .footer-brand-sub::after { right: 10px; }

    .footer-heading {
        font-size: 1.4rem;
        font-weight: 700;
        text-transform: uppercase;
        margin-bottom: 25px;
        letter-spacing: 0.5px;
        color: white;
    }

    .location-title {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 5px;
        color: white;
    }

    .footer-text {
        font-size: 0.95rem;
        margin-bottom: 25px;
        line-height: 1.5;
        font-weight: 300;
        color: white;
    }

    .footer-links a {
        color: white;
        text-decoration: underline;
        margin-bottom: 12px;
        display: block;
        font-size: 0.95rem;
        font-weight: 400;
        transition: 0.3s;
    }
    .footer-links a:hover {
        opacity: 0.8;
        color: white;
    }

    .contact-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .contact-list li {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
        font-size: 0.95rem;
        color: white;
    }
    
    .contact-list i {
        font-size: 1.1rem;
    }
    
    .contact-list a {
        color: white;
        text-decoration: underline;
    }

    @media (max-width: 900px) {
        .footer-content-wrapper {
            grid-template-columns: 1fr 1fr;
        }
    }
    
    @media (max-width: 600px) {
        .footer-content-wrapper {
            grid-template-columns: 1fr;
            text-align: center;
        }
        .brand-col, .footer-col {
            align-items: center;
        }
        .footer-brand-sub::before, .footer-brand-sub::after { display: none; }
    }
</style>

<footer>
    <div class="footer-content-wrapper">
        
        <div class="footer-col brand-col">
            <i class="fas fa-water footer-logo-icon"></i>
            <div class="footer-brand-name">CheckMates</div>
            <div class="footer-brand-sub">AGOS</div>
        </div>

        <div class="footer-col">
            <div class="location-title">Hacienda Emiart</div>
            <p class="footer-text">Purok 7, Barangay Tibangan, Bustos Bulacan</p>

            <div class="location-title">Emiart Resorts I, II, III</div>
            <p class="footer-text">106-114 Pasig St, Maypajo, Caloocan, Metro Manila</p>
        </div>

        <div class="footer-col">
            <h3 class="footer-heading">PRIVACY</h3>
            <div class="footer-links">
                <a href="#">Terms of use</a>
                <a href="#">Privacy Policy</a>
                <a href="#">Cookies</a>
            </div>
        </div>

        <div class="footer-col">
            <h3 class="footer-heading">RESERVATIONS</h3>
            <ul class="contact-list">
                <li>
                    <i class="fas fa-phone-alt"></i> 
                    <span>09331766862</span>
                </li>
                <li>
                    <i class="fas fa-phone-alt"></i> 
                    <span>09327815012</span>
                </li>
                <li>
                    <i class="fas fa-envelope"></i> 
                    <span>emiartresort@yahoo.com</span>
                </li>
                <li>
                    <i class="fab fa-facebook-square"></i> 
                    <a href="#">Emiart Private Resorts</a>
                </li>
            </ul>
        </div>

    </div>
</footer>

</body>
</html>