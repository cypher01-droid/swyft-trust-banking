<?php 
require_once 'includes/db.php';
include 'includes/header.php'; 
?>

<style>
/* Elite Zeus Security Gateway Styles */
.auth-section {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: radial-gradient(circle at top right, rgba(157, 80, 255, 0.1), transparent),
                radial-gradient(circle at bottom left, rgba(110, 44, 242, 0.05), transparent);
    padding: 100px 20px;
}

.auth-container {
    width: 100%;
    max-width: 480px;
    background: rgba(15, 23, 42, 0.85);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid rgba(157, 80, 255, 0.3);
    border-radius: 35px;
    padding: 40px;
    box-shadow: 0 40px 100px rgba(0, 0, 0, 0.7);
    color: white;
}

/* Purple Progress Line */
.auth-progress-container {
    width: 100%;
    height: 6px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    margin-bottom: 40px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #9d50ff, #ff00ff);
    box-shadow: 0 0 15px rgba(157, 80, 255, 0.6);
    transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

.auth-step { display: none; }
.auth-step.active { display: block; animation: fadeInUp 0.5s ease forwards; }

.purple-text { color: #9d50ff; font-weight: 800; }

/* Force Neomorphic Zeus Inputs */
.input-group { margin-bottom: 25px; }
.input-group label {
    display: block;
    margin-bottom: 10px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.neo-input {
    width: 100% !important;
    background: #0a0a0c !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    padding: 18px !important;
    border-radius: 16px !important;
    color: white !important;
    font-size: 1rem !important;
    box-shadow: inset 4px 4px 10px rgba(0, 0, 0, 0.5) !important;
    outline: none;
    transition: 0.3s;
}

.neo-input:focus { border-color: #9d50ff !important; box-shadow: inset 4px 4px 10px rgba(0, 0, 0, 0.5), 0 0 15px rgba(157, 80, 255, 0.2) !important; }

/* Buttons */
.btn-primary {
    background: #9d50ff !important;
    border: none;
    padding: 18px;
    border-radius: 16px;
    color: white !important;
    font-weight: 800;
    width: 100%;
    cursor: pointer;
    box-shadow: 0 10px 20px rgba(157, 80, 255, 0.3);
    transition: 0.3s;
}

.btn-secondary {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 18px 25px;
    border-radius: 16px;
    color: white;
    font-weight: 600;
    cursor: pointer;
}

/* PIN Interface */
.pin-display { margin-bottom: 25px; text-align: center; }
.pin-field {
    text-align: center !important;
    font-size: 2.2rem !important;
    letter-spacing: 12px;
    background: rgba(0, 0, 0, 0.4) !important;
}

.pin-pad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.pin-btn {
    height: 65px;
    background: #1e293b;
    border: none;
    border-radius: 18px;
    color: white;
    font-size: 1.4rem;
    font-weight: 700;
    box-shadow: 5px 5px 10px rgba(0,0,0,0.3);
    cursor: pointer;
}

.pin-btn:active { transform: scale(0.92); background: #334155; }
.submit-pin { background: #9d50ff !important; }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<section class="auth-section">
    <div class="auth-container">
        
        <div class="auth-progress-container">
            <div class="progress-bar" id="authProgress" style="width: 33.3%;"></div>
        </div>

        <form id="regForm" action="process-register.php" method="POST">
            <!-- STEP 1 -->
            <div class="auth-step active" id="step1">
                <h2>Create <span class="purple-text">Identity</span></h2>
                <p>Ensure your name matches your government ID.</p>
                <div class="input-group">
                    <label>Full Legal Name</label>
                    <input type="text" name="full_name" class="neo-input" placeholder="John Doe" required>
                </div>
                <div class="input-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="neo-input" placeholder="john@example.com" required>
                </div>
                <button type="button" class="btn-primary" onclick="nextStep(2)">Continue</button>
            </div>

            <!-- STEP 2 -->
            <div class="auth-step" id="step2">
                <h2>Secure <span class="purple-text">Vault</span></h2>
                <p>Establish your primary account credentials.</p>
                <div class="input-group">
                    <label>Access Password</label>
                    <input type="password" name="password" class="neo-input" placeholder="••••••••" required>
                </div>
                <div class="input-group">
                    <label>Base Currency</label>
                    <select name="base_currency" class="neo-input">
                        <option value="USD">USD - US Dollar</option>
<option value="BTC">BTC - Bitcoin</option>
<option value="EUR">EUR - Euro</option>
<option value="GBP">GBP - British Pound</option>
<option value="JPY">JPY - Japanese Yen</option>
<option value="AUD">AUD - Australian Dollar</option>
<option value="CAD">CAD - Canadian Dollar</option>
<option value="CHF">CHF - Swiss Franc</option>
<option value="CNY">CNY - Chinese Yuan</option>
<option value="HKD">HKD - Hong Kong Dollar</option>
<option value="NZD">NZD - New Zealand Dollar</option>
<option value="SEK">SEK - Swedish Krona</option>
<option value="KRW">KRW - South Korean Won</option>
<option value="SGD">SGD - Singapore Dollar</option>
<option value="NOK">NOK - Norwegian Krone</option>
<option value="MXN">MXN - Mexican Peso</option>
<option value="INR">INR - Indian Rupee</option>
<option value="RUB">RUB - Russian Ruble</option>
<option value="ZAR">ZAR - South African Rand</option>
<option value="TRY">TRY - Turkish Lira</option>
<option value="BRL">BRL - Brazilian Real</option>
<option value="TWD">TWD - New Taiwan Dollar</option>
<option value="DKK">DKK - Danish Krone</option>
<option value="PLN">PLN - Polish Złoty</option>
<option value="THB">THB - Thai Baht</option>
<option value="IDR">IDR - Indonesian Rupiah</option>
<option value="HUF">HUF - Hungarian Forint</option>
<option value="CZK">CZK - Czech Koruna</option>
<option value="ILS">ILS - Israeli New Shekel</option>
<option value="CLP">CLP - Chilean Peso</option>
<option value="PHP">PHP - Philippine Peso</option>
<option value="AED">AED - UAE Dirham</option>
<option value="COP">COP - Colombian Peso</option>
<option value="SAR">SAR - Saudi Riyal</option>
<option value="MYR">MYR - Malaysian Ringgit</option>
<option value="RON">RON - Romanian Leu</option>
<option value="ARS">ARS - Argentine Peso</option>
<option value="VND">VND - Vietnamese Đồng</option>
<option value="NGN">NGN - Nigerian Naira</option>
<option value="BDT">BDT - Bangladeshi Taka</option>
<option value="EGP">EGP - Egyptian Pound</option>
<option value="PKR">PKR - Pakistani Rupee</option>
<option value="UAH">UAH - Ukrainian Hryvnia</option>
<option value="IQD">IQD - Iraqi Dinar</option>
<option value="QAR">QAR - Qatari Riyal</option>
<option value="KWD">KWD - Kuwaiti Dinar</option>
<option value="OMR">OMR - Omani Rial</option>
<option value="BHD">BHD - Bahraini Dinar</option>
<option value="JOD">JOD - Jordanian Dinar</option>
<option value="LBP">LBP - Lebanese Pound</option>
<option value="PEN">PEN - Peruvian Sol</option>
<option value="CRC">CRC - Costa Rican Colón</option>
<option value="UYU">UYU - Uruguayan Peso</option>
<option value="BOB">BOB - Bolivian Boliviano</option>
<option value="PYG">PYG - Paraguayan Guaraní</option>
<option value="GTQ">GTQ - Guatemalan Quetzal</option>
<option value="HNL">HNL - Honduran Lempira</option>
<option value="NIO">NIO - Nicaraguan Córdoba</option>
<option value="DOP">DOP - Dominican Peso</option>
<option value="JMD">JMD - Jamaican Dollar</option>
<option value="TTD">TTD - Trinidad and Tobago Dollar</option>
<option value="BSD">BSD - Bahamian Dollar</option>
<option value="BBD">BBD - Barbadian Dollar</option>
<option value="XCD">XCD - East Caribbean Dollar</option>
<option value="AWG">AWG - Aruban Florin</option>
<option value="ANG">ANG - Netherlands Antillean Guilder</option>
<option value="BZD">BZD - Belize Dollar</option>
<option value="GYD">GYD - Guyanaese Dollar</option>
<option value="SRD">SRD - Surinamese Dollar</option>
<option value="FJD">FJD - Fijian Dollar</option>
<option value="SBD">SBD - Solomon Islands Dollar</option>
<option value="TOP">TOP - Tongan Paʻanga</option>
<option value="WST">WST - Samoan Tala</option>
<option value="VUV">VUV - Vanuatu Vatu</option>
<option value="PGK">PGK - Papua New Guinean Kina</option>
<option value="KID">KID - Kiribati Dollar</option>
<option value="SOS">SOS - Somali Shilling</option>
<option value="TZS">TZS - Tanzanian Shilling</option>
<option value="KES">KES - Kenyan Shilling</option>
<option value="UGX">UGX - Ugandan Shilling</option>
<option value="RWF">RWF - Rwandan Franc</option>
<option value="BIF">BIF - Burundian Franc</option>
<option value="CDF">CDF - Congolese Franc</option>
<option value="GMD">GMD - Gambian Dalasi</option>
<option value="GHS">GHS - Ghanaian Cedi</option>
<option value="SLL">SLL - Sierra Leonean Leone</option>
<option value="LRD">LRD - Liberian Dollar</option>
<option value="GNF">GNF - Guinean Franc</option>
<option value="MGA">MGA - Malagasy Ariary</option>
<option value="MUR">MUR - Mauritian Rupee</option>
<option value="SCR">SCR - Seychellois Rupee</option>
<option value="DJF">DJF - Djiboutian Franc</option>
<option value="ETB">ETB - Ethiopian Birr</option>
<option value="SDG">SDG - Sudanese Pound</option>
<option value="LYD">LYD - Libyan Dinar</option>
<option value="TND">TND - Tunisian Dinar</option>
<option value="DZD">DZD - Algerian Dinar</option>
<option value="MAD">MAD - Moroccan Dirham</option>
<option value="MRU">MRU - Mauritanian Ouguiya</option>
<option value="XOF">XOF - West African CFA Franc</option>
<option value="XAF">XAF - Central African CFA Franc</option>
<option value="CVE">CVE - Cape Verdean Escudo</option>
<option value="STN">STN - São Tomé and Príncipe Dobra</option>
<option value="GIP">GIP - Gibraltar Pound</option>
<option value="FKP">FKP - Falkland Islands Pound</option>
<option value="SHP">SHP - Saint Helena Pound</option>
<option value="IMP">IMP - Manx Pound</option>
<option value="JEP">JEP - Jersey Pound</option>
<option value="GGP">GGP - Guernsey Pound</option>
<option value="BMD">BMD - Bermudian Dollar</option>
<option value="KYD">KYD - Cayman Islands Dollar</option>
<option value="SZL">SZL - Swazi Lilangeni</option>
<option value="LSL">LSL - Lesotho Loti</option>
<option value="NAD">NAD - Namibian Dollar</option>
<option value="MWK">MWK - Malawian Kwacha</option>
<option value="ZMW">ZMW - Zambian Kwacha</option>
<option value="ZWL">ZWL - Zimbabwean Dollar</option>
<option value="AOA">AOA - Angolan Kwanza</option>
<option value="MZN">MZN - Mozambican Metical</option>
<option value="MOP">MOP - Macanese Pataca</option>
<option value="MMK">MMK - Myanmar Kyat</option>
<option value="LAK">LAK - Laotian Kip</option>
<option value="KHR">KHR - Cambodian Riel</option>
<option value="MNT">MNT - Mongolian Tögrög</option>
<option value="BND">BND - Brunei Dollar</option>
<option value="LKR">LKR - Sri Lankan Rupee</option>
<option value="MVR">MVR - Maldivian Rufiyaa</option>
<option value="NPR">NPR - Nepalese Rupee</option>
<option value="AFN">AFN - Afghan Afghani</option>
<option value="IRR">IRR - Iranian Rial</option>
<option value="YER">YER - Yemeni Rial</option>
<option value="SYP">SYP - Syrian Pound</option>
<option value="RSD">RSD - Serbian Dinar</option>
<option value="BAM">BAM - Bosnia-Herzegovina Convertible Mark</option>
<option value="MKD">MKD - Macedonian Denar</option>
<option value="ALL">ALL - Albanian Lek</option>
<option value="GEL">GEL - Georgian Lari</option>
<option value="AMD">AMD - Armenian Dram</option>
<option value="AZN">AZN - Azerbaijani Manat</option>
<option value="KZT">KZT - Kazakhstani Tenge</option>
<option value="UZS">UZS - Uzbekistani Som</option>
<option value="TJS">TJS - Tajikistani Somoni</option>
<option value="TMT">TMT - Turkmenistani Manat</option>
<option value="KGS">KGS - Kyrgyzstani Som</option>
<option value="MDL">MDL - Moldovan Leu</option>
<option value="BYN">BYN - Belarusian Ruble</option>
<option value="ISK">ISK - Icelandic Króna</option>
<option value="HRK">HRK - Croatian Kuna</option>
<option value="BGN">BGN - Bulgarian Lev</option>
<option value="EEK">EEK - Estonian Kroon</option>
<option value="LVL">LVL - Latvian Lats</option>
<option value="LTL">LTL - Lithuanian Litas</option>
<option value="MTL">MTL - Maltese Lira</option>
<option value="CYP">CYP - Cypriot Pound</option>
<option value="SIT">SIT - Slovenian Tolar</option>
<option value="SKK">SKK - Slovak Koruna</option>
<option value="TRL">TRL - Turkish Lira (old)</option>
<option value="CSD">CSD - Serbian Dinar (old)</option>
<option value="ROL">ROL - Romanian Leu (old)</option>
<option value="ZMK">ZMK - Zambian Kwacha (old)</option>
<option value="VEB">VEB - Venezuelan Bolívar (old)</option>
<option value="YUM">YUM - Yugoslavian Dinar</option>
<option value="DEM">DEM - German Mark</option>
<option value="FRF">FRF - French Franc</option>
<option value="ITL">ITL - Italian Lira</option>
<option value="ESP">ESP - Spanish Peseta</option>
<option value="PTE">PTE - Portuguese Escudo</option>
<option value="GRD">GRD - Greek Drachma</option>
<option value="ATS">ATS - Austrian Schilling</option>
<option value="IEP">IEP - Irish Pound</option>
<option value="FIM">FIM - Finnish Markka</option>
<option value="BEF">BEF - Belgian Franc</option>
<option value="LUF">LUF - Luxembourgish Franc</option>
<option value="NLG">NLG - Dutch Guilder</option>
<option value="XAG">XAG - Silver Ounce</option>
<option value="XAU">XAU - Gold Ounce</option>
<option value="XPT">XPT - Platinum Ounce</option>
<option value="XPD">XPD - Palladium Ounce</option>
<option value="XDR">XDR - IMF Special Drawing Rights</option>
<option value="XTS">XTS - Testing Currency Code</option>
<option value="XXX">XXX - No Currency</option>
<option value="ETH">ETH - Ethereum</option>
<option value="LTC">LTC - Litecoin</option>
<option value="XRP">XRP - Ripple</option>
<option value="ADA">ADA - Cardano</option>
<option value="DOGE">DOGE - Dogecoin</option>
<option value="BNB">BNB - Binance Coin</option>
<option value="SOL">SOL - Solana</option>
<option value="DOT">DOT - Polkadot</option>
<option value="AVAX">AVAX - Avalanche</option>
<option value="MATIC">MATIC - Polygon</option>
<option value="SHIB">SHIB - Shiba Inu</option>
<option value="USDT">USDT - Tether</option>
<option value="USDC">USDC - USD Coin</option>
<option value="DAI">DAI - Dai</option>
<option value="BUSD">BUSD - Binance USD</option>
<option value="UNI">UNI - Uniswap</option>
<option value="LINK">LINK - Chainlink</option>
<option value="ATOM">ATOM - Cosmos</option>
<option value="ALGO">ALGO - Algorand</option>
<option value="XLM">XLM - Stellar</option>
<option value="VET">VET - VeChain</option>
<option value="TRX">TRX - TRON</option>
<option value="ICP">ICP - Internet Computer</option>
<option value="FIL">FIL - Filecoin</option>
<option value="ETC">ETC - Ethereum Classic</option>
<option value="XMR">XMR - Monero</option>
<option value="ZEC">ZEC - Zcash</option>
<option value="EOS">EOS - EOS</option>
<option value="AAVE">AAVE - Aave</option>
<option value="CAKE">CAKE - PancakeSwap</option>
<option value="AXS">AXS - Axie Infinity</option>
<option value="SAND">SAND - The Sandbox</option>
<option value="MANA">MANA - Decentraland</option>
<option value="ENJ">ENJ - Enjin Coin</option>
<option value="GALA">GALA - Gala</option>
<option value="THETA">THETA - Theta Network</option>
<option value="XTZ">XTZ - Tezos</option>
<option value="NEAR">NEAR - NEAR Protocol</option>
<option value="FLOW">FLOW - Flow</option>
<option value="KLAY">KLAY - Klaytn</option>
<option value="MIOTA">MIOTA - IOTA</option>
<option value="HNT">HNT - Helium</option>
<option value="CHZ">CHZ - Chiliz</option>
<option value="BTT">BTT - BitTorrent</option>
<option value="WAVES">WAVES - Waves</option>
<option value="NEO">NEO - NEO</option>
<option value="ONE">ONE - Harmony</option>
<option value="QTUM">QTUM - Qtum</option>
<option value="ZIL">ZIL - Zilliqa</option>
<option value="RVN">RVN - Ravencoin</option>
<option value="SC">SC - Siacoin</option>
<option value="BAT">BAT - Basic Attention Token</option>
<option value="OMG">OMG - OMG Network</option>
<option value="ZRX">ZRX - 0x</option>
<option value="SNX">SNX - Synthetix</option>
<option value="CRV">CRV - Curve DAO Token</option>
<option value="COMP">COMP - Compound</option>
<option value="MKR">MKR - Maker</option>
<option value="YFI">YFI - yearn.finance</option>
<option value="SUSHI">SUSHI - SushiSwap</option>
<option value="1INCH">1INCH - 1inch Network</option>
<option value="UMA">UMA - UMA</option>
<option value="BAL">BAL - Balancer</option>
<option value="REN">REN - Ren</option>
<option value="ANKR">ANKR - Ankr</option>
<option value="CKB">CKB - Nervos Network</option>
<option value="OCEAN">OCEAN - Ocean Protocol</option>
<option value="NU">NU - NuCypher</option>
<option value="STORJ">STORJ - Storj</option>
<option value="KSM">KSM - Kusama</option>
<option value="DCR">DCR - Decred</option>
<option value="LSK">LSK - Lisk</option>
<option value="AR">AR - Arweave</option>
<option value="CELO">CELO - Celo</option>
<option value="ICX">ICX - ICON</option>
<option value="VGX">VGX - Voyager Token</option>
<option value="NEXO">NEXO - Nexo</option>
<option value="CEL">CEL - Celsius</option>
<option value="CRO">CRO - Crypto.com Coin</option>
<option value="FTT">FTT - FTX Token</option>
<option value="LEO">LEO - UNUS SED LEO</option>
<option value="HT">HT - Huobi Token</option>
<option value="OKB">OKB - OKB</option>
<option value="KCS">KCS - KuCoin Token</option>
<option value="GT">GT - GateToken</option>
<option value="MX">MX - MX Token</option>
<option value="BNT">BNT - Bancor</option>
<option value="RUNE">RUNE - THORChain</option>
<option value="RAY">RAY - Raydium</option>
<option value="SRM">SRM - Serum</option>
<option value="FRAX">FRAX - Frax</option>
<option value="FEI">FEI - Fei USD</option>
<option value="UST">UST - TerraUSD</option>
<option value="LUNA">LUNA - Terra</option>
<option value="ANC">ANC - Anchor Protocol</option>
<option value="MIR">MIR - Mirror Protocol</option>
                    </select>
                </div>
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" class="btn-secondary" onclick="nextStep(1)">Back</button>
                    <button type="button" class="btn-primary" onclick="nextStep(3)">Next</button>
                </div>
            </div>

            <!-- STEP 3 -->
            <div class="auth-step" id="step3">
                <h2>Wallet <span class="purple-text">PIN</span></h2>
                <p>6-digit code for quick secure access.</p>
                <div class="pin-display">
                    <input type="password" id="pinInput" name="wallet_pin" maxlength="6" class="neo-input pin-field" readonly placeholder="••••••">
                </div>
                <div class="pin-pad">
                    <?php for($i=1; $i<=9; $i++): ?>
                        <button type="button" class="pin-btn" onclick="pressPin('<?php echo $i; ?>')"><?php echo $i; ?></button>
                    <?php endfor; ?>
                    <button type="button" class="pin-btn" onclick="pressPin('clear')">C</button>
                    <button type="button" class="pin-btn" onclick="pressPin('0')">0</button>
                    <button type="submit" class="pin-btn submit-pin">✓</button>
                </div>
                <button type="button" class="btn-secondary" style="margin-top:20px;" onclick="nextStep(2)">Back</button>
            </div>
        </form>
    </div>
</section>

<script>
let currentStep = 1;
const pinField = document.getElementById('pinInput');

function nextStep(step) {
    document.querySelectorAll('.auth-step').forEach(el => el.classList.remove('active'));
    document.getElementById('step' + step).classList.add('active');
    
    const progress = document.getElementById('authProgress');
    progress.style.width = (step / 3 * 100) + '%';
    currentStep = step;
}

function pressPin(value) {
    if (value === 'clear') {
        pinField.value = '';
    } else if (pinField.value.length < 6) {
        pinField.value += value;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
