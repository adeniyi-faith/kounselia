<?php
/**
 * Kounselia shared design tokens and component styles.
 *
 * One stylesheet, required by every Kounselia front-end page
 * (index.php, talk.php, ...) so colors, fonts, and component
 * styles never drift out of sync between pages. Some selectors
 * here only apply to marketing-page elements (hero, partners,
 * footer) that a given page may not render, that is expected
 * and harmless, unused CSS rules do not cost anything at
 * runtime beyond a few extra KB.
 */
?>
<style>
/* --- ENHANCED CSS START --- */
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#F8F6F2;
  --surface:#FFFFFF;
  --surface2:#F2EFE9;
  --surface3:#EDEAE3;
  --text:#18160F;
  --text2:#5B574D; /* Slightly darkened for better readability */
  --text3:#A8A49A;
  --border:#E8E4DB; /* Softened border */
  --accent:#1E3A5F;
  --accent2:#284B7A; /* Lighter accent for gradients */
  --accent-light:#E8EEF6;
  --gold:#B07D3A;
  --gold-light:#FBF5EA;
  --rose:#8B3A52;
  --rose-light:#F7EBF0;
  --sage:#2E5C3E;
  --sage-light:#EAF2EC;
  --teal:#1E5C5C;
  --teal-light:#E6F2F2;
  --plum:#4A3070;
  --plum-light:#EEE9F8;
  --sienna:#7A3D1E;
  --sienna-light:#F5EBE5;
  --navy:#162B4A;
  --navy-light:#E6EBF2;
  
  /* NEW: Softer, larger border radiuses for a friendlier feel */
  --r:24px; 
  --r-sm:16px;

  /* NEW: Shadow tokens for depth */
  --shadow-sm: 0 4px 12px rgba(24, 22, 15, 0.03);
  --shadow-md: 0 8px 24px rgba(24, 22, 15, 0.06);
  --shadow-hover: 0 16px 32px rgba(24, 22, 15, 0.08);
  --shadow-btn: 0 4px 14px rgba(30, 58, 95, 0.25);
}
html,body{height:100%;background:var(--bg)}
body{font-family:'Outfit',sans-serif;color:var(--text);overflow-x:hidden; -webkit-font-smoothing: antialiased;}

/* SMOOTH ENTRANCE ANIMATIONS */
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUpFade { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

.screen{display:none;position:fixed;inset:0;background:var(--bg);overflow:hidden;flex-direction:column}
.screen.active{display:flex; animation: fadeIn 0.4s ease-out;}
#landing{overflow-y:auto;display:none} /* FIX: changed to display:none so it properly hides behind the chat */
#landing.active{display:block}

/* DESKTOP CONTAINER UTILITY */
.container {
  max-width: 1200px;
  margin: 0 auto;
  width: 100%;
}

/* NAV */
.land-nav{
  position:sticky;top:0;z-index:50;
  background:rgba(255,255,255,0.85);
  backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  border-bottom:1px solid rgba(232, 228, 219, 0.6);
  padding:16px 24px;
  display:flex;align-items:center;justify-content:space-between;
  box-shadow: 0 2px 10px rgba(0,0,0,0.02);
}

.logo-link{display:flex;align-items:center;text-decoration:none;outline:none;}
.site-logo{max-height:32px;width:auto;object-fit:contain;transition:transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);}
.logo-link:hover .site-logo{transform:scale(1.03);}
@media (min-width: 768px) {
  .site-logo{max-height:40px;}
  .land-nav { padding: 20px 48px; }
}

.logo{font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:500;color:var(--accent);letter-spacing:0.2px}
.logo-dot{color:var(--gold)}
.nav-right{display:flex;gap:10px;align-items:center}
.btn-ghost{background:none;border:1px solid var(--border);padding:8px 18px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:14px;cursor:pointer;color:var(--text);transition:all .3s ease}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent); background: var(--surface); box-shadow: var(--shadow-sm);}
.btn-nav-primary{background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);border:none;padding:9px 20px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;cursor:pointer;color:#fff; transition:all .3s ease; box-shadow: var(--shadow-btn);}
.btn-nav-primary:hover{transform: translateY(-1px); box-shadow: 0 6px 20px rgba(30, 58, 95, 0.35);}

/* HERO */
.hero{padding:64px 24px 50px;text-align:center; max-width: 800px; margin: 0 auto;}
@media (min-width: 768px) {
  .hero{padding:100px 24px 80px;}
}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:var(--surface); border: 1px solid var(--border); color:var(--accent);font-size:12px;font-weight:500;padding:6px 16px;border-radius:50px;margin-bottom:28px;letter-spacing:0.5px; box-shadow: var(--shadow-sm);}
.hero h1{font-family:'Cormorant Garamond',serif;font-size:44px;line-height:1.15;color:var(--text);margin-bottom:20px;font-weight:400}
@media (min-width: 768px) {
  .hero h1{font-size:64px;}
}
.hero h1 em{color:var(--gold);font-style:italic}
.hero p{font-size:16px;color:var(--text2);line-height:1.75;margin-bottom:36px;font-weight:300;max-width:380px;margin-left:auto;margin-right:auto}
@media (min-width: 768px) {
  .hero p{font-size:18px; max-width: 520px;}
}
.hero-cta{display:flex;flex-direction:column;gap:12px;max-width:320px;margin:0 auto}
@media (min-width: 600px) {
  .hero-cta{flex-direction:row; max-width: 500px;}
}
.btn-lg{padding:16px 24px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:16px;cursor:pointer;font-weight:500;border:none;width:100%;transition:all .3s cubic-bezier(0.16, 1, 0.3, 1)}
.btn-lg.primary{background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);color:#fff; box-shadow: var(--shadow-btn);}
.btn-lg.primary:hover{transform: translateY(-2px); box-shadow: 0 8px 24px rgba(30, 58, 95, 0.35);}
.btn-lg.outline{background:var(--surface);border:1.5px solid var(--border);color:var(--text); box-shadow: var(--shadow-sm);}
.btn-lg.outline:hover{border-color:var(--text3); transform: translateY(-2px); box-shadow: var(--shadow-md);}

/* TRUST */
.trust-row{display:flex;justify-content:center;flex-wrap:wrap;padding:20px 16px 24px;border-top:1px solid rgba(232,228,219,0.5);border-bottom:1px solid rgba(232,228,219,0.5);background:var(--surface);margin-bottom:0}
@media (min-width: 768px) {
  .trust-row { gap: 40px; padding: 24px; }
}
.trust-item{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text2);font-weight:400;padding:6px 14px}
@media (min-width: 768px) {
  .trust-item { font-size: 14px; }
}
.trust-item i{font-size:16px;color:var(--accent)}

/* HOW IT FEELS */
.feels-section{padding:56px 24px;background:var(--bg)}
@media (min-width: 768px) {
  .feels-section { padding: 80px 48px; }
}
.section-eyebrow{font-size:11px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold);margin-bottom:12px}
.section-title{font-family:'Cormorant Garamond',serif;font-size:34px;font-weight:400;color:var(--text);line-height:1.2;margin-bottom:16px}
@media (min-width: 768px) {
  .section-title{font-size:44px;}
}
.section-body{font-size:16px;color:var(--text2);line-height:1.8;font-weight:300; max-width: 600px;}
.section-body strong{font-weight:500;color:var(--text)}

.feels-cards{display:grid;grid-template-columns:1fr;gap:16px;margin-top:32px}
@media (min-width: 768px) {
  .feels-cards { grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 48px; }
}
.feels-card{display:flex;gap:18px;padding:24px;background:var(--surface);border-radius:var(--r);border:1px solid var(--border); box-shadow: var(--shadow-sm); transition: transform 0.3s ease, box-shadow 0.3s ease;}
@media (min-width: 768px) {
  .feels-card { flex-direction: column; gap: 24px; padding: 32px; }
}
.feels-card:hover{transform: translateY(-4px); box-shadow: var(--shadow-hover);}
.feels-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
@media (min-width: 768px) {
  .feels-icon { width: 56px; height: 56px; font-size: 26px; border-radius: 16px; }
}
.ic-blue{background:var(--accent-light);color:var(--accent)}
.ic-gold{background:var(--gold-light);color:var(--gold)}
.ic-rose{background:var(--rose-light);color:var(--rose)}
.ic-sage{background:var(--sage-light);color:var(--sage)}
.ic-teal{background:var(--teal-light);color:var(--teal)}
.ic-plum{background:var(--plum-light);color:var(--plum)}
.ic-sienna{background:var(--sienna-light);color:var(--sienna)}
.ic-navy{background:var(--navy-light);color:var(--navy)}
.feels-card h4{font-size:17px;font-weight:500;color:var(--text);margin-bottom:6px}
@media (min-width: 768px) {
  .feels-card h4 { font-size: 20px; margin-bottom: 12px; }
}
.feels-card p{font-size:14px;color:var(--text2);line-height:1.7;font-weight:300}
@media (min-width: 768px) {
  .feels-card p { font-size: 15px; }
}

/* COUNSELORS */
.counselors-section{padding:56px 0 0;background:var(--surface);border-top:1px solid var(--border)}
@media (min-width: 768px) {
  .counselors-section { padding: 80px 0 0; }
}
.counselors-head{padding:0 24px 8px}
@media (min-width: 768px) {
  .counselors-head{padding:0 48px 8px}
}
.counselors-sub{font-size:15px;color:var(--text2);font-weight:300;line-height:1.7;margin-top:8px;padding:0 24px 24px; max-width: 600px;}
@media (min-width: 768px) {
  .counselors-sub{padding:0 48px 40px; font-size: 16px;}
}
.counselors-list{display:grid;grid-template-columns:1fr;gap:16px;padding:0 16px}
@media (min-width: 600px) {
  .counselors-list { grid-template-columns: repeat(2, 1fr); padding: 0 24px; gap: 20px; }
}
@media (min-width: 900px) {
  .counselors-list { grid-template-columns: repeat(3, 1fr); padding: 0 48px; gap: 24px; }
}
@media (min-width: 1200px) {
  .counselors-list { grid-template-columns: repeat(4, 1fr); }
}

.counselor-card{background:var(--bg);border:1px solid transparent;border-radius:var(--r);padding:24px;cursor:pointer;transition:all .3s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: var(--shadow-sm); display:flex; flex-direction: column; justify-content: space-between; height: 100%;}
.counselor-card:hover{transform:translateY(-4px); box-shadow:var(--shadow-hover); border-color: var(--border); background:var(--surface);}
.counselor-card:active{transform:scale(0.98); box-shadow: var(--shadow-sm);}
.card-top{display:flex;align-items:center;gap:16px;margin-bottom:14px}
.card-av{width:54px;height:54px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
.card-meta{}
.card-spec{font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px}
.spec-rose{color:var(--rose)}.spec-blue{color:var(--accent)}.spec-sage{color:var(--sage)}.spec-gold{color:var(--gold)}
.spec-teal{color:var(--teal)}.spec-plum{color:var(--plum)}.spec-sienna{color:var(--sienna)}.spec-navy{color:var(--navy)}
.card-name{font-size:19px;font-weight:500;color:var(--text)}
.card-desc{font-size:14px;color:var(--text2);line-height:1.75;font-weight:300;margin-bottom:24px; flex-grow: 1;}
.start-btn{width:100%;padding:14px;border-radius:50px;border:none;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:all .3s ease; opacity: 0.95; margin-top: auto;}
.counselor-card:hover .start-btn{opacity: 1; transform: translateY(-1px);}
.sb-rose{background:linear-gradient(135deg, var(--rose) 0%, #A34863 100%);color:#fff}
.sb-blue{background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);color:#fff}
.sb-sage{background:linear-gradient(135deg, var(--sage) 0%, #3B724D 100%);color:#fff}
.sb-gold{background:linear-gradient(135deg, var(--gold) 0%, #C48E44 100%);color:#fff}
.sb-teal{background:linear-gradient(135deg, var(--teal) 0%, #257070 100%);color:#fff}
.sb-plum{background:linear-gradient(135deg, var(--plum) 0%, #5B3C8A 100%);color:#fff}
.sb-sienna{background:linear-gradient(135deg, var(--sienna) 0%, #8F4925 100%);color:#fff}
.sb-navy{background:linear-gradient(135deg, var(--navy) 0%, #1D375C 100%);color:#fff}

.guest-note{text-align:center;font-size:13px;color:var(--text3);padding:32px 24px; margin-top: 16px;}
.guest-note a{color:var(--accent);font-weight:500;text-decoration:none;cursor:pointer; border-bottom: 1px solid transparent; transition: border-color 0.2s;}
.guest-note a:hover{border-color: var(--accent);}

/* VOICES */
.voices-section{padding:56px 0;background:var(--bg);border-top:1px solid var(--border)}
@media (min-width: 768px) {
  .voices-section { padding: 80px 0; }
}
.voices-head{padding:0 24px 28px}
@media (min-width: 768px) {
  .voices-head { padding: 0 48px 40px; text-align: center; }
}
.voices-list{display:grid;grid-template-columns:1fr;gap:16px;padding:0 16px}
@media (min-width: 768px) {
  .voices-list { grid-template-columns: repeat(3, 1fr); padding: 0 48px; gap: 24px; }
}
.voice-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:26px; box-shadow: var(--shadow-sm); transition: transform 0.3s ease; display:flex; flex-direction:column; justify-content:space-between; height:100%;}
.voice-card:hover{transform: translateY(-2px);}
.voice-quote{font-family:'Cormorant Garamond',serif;font-size:21px;line-height:1.5;color:var(--text);font-style:italic;margin-bottom:24px; flex-grow: 1;}
.voice-meta{display:flex;align-items:center;gap:12px}
.voice-av{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:500; box-shadow: 0 2px 8px rgba(0,0,0,0.05); flex-shrink: 0;}
.voice-info{font-size:13px;color:var(--text2);font-weight:300}
.voice-info strong{font-weight:500;color:var(--text);display:block}

/* WHY IT MATTERS */
.why-section{background:linear-gradient(160deg, var(--accent) 0%, #11223B 100%);padding:56px 24px}
@media (min-width: 768px) {
  .why-section { padding: 100px 48px; display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
}
.why-eyebrow{font-size:11px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;color:#C9A96E;margin-bottom:12px}
.why-title{font-family:'Cormorant Garamond',serif;font-size:34px;font-weight:400;color:#fff;line-height:1.2;margin-bottom:20px}
@media (min-width: 768px) {
  .why-title { font-size: 44px; }
}
.why-body{font-size:16px;color:rgba(255,255,255,0.75);line-height:1.8;font-weight:300;margin-bottom:32px; max-width: 500px;}
@media (min-width: 768px) {
  .why-body { font-size: 18px; margin-bottom: 48px;}
}
.why-body strong{color:rgba(255,255,255,0.95);font-weight:500}
.why-stats{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:36px}
.why-stat{background:rgba(255,255,255,0.05);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);border-radius:var(--r-sm);padding:20px;text-align:center; transition: transform 0.3s ease;}
.why-stat:hover{transform: translateY(-3px); background:rgba(255,255,255,0.08);}
.why-stat-num{font-family:'Cormorant Garamond',serif;font-size:36px;color:#fff;line-height:1}
@media (min-width: 768px) {
  .why-stat-num { font-size: 48px; }
}
.why-stat-num sup{font-size:18px}
.why-stat-label{font-size:12px;color:rgba(255,255,255,0.65);font-weight:300;margin-top:8px;line-height:1.5}
.why-pillars{display:flex;flex-direction:column;gap:14px}
.why-pillar{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);border-radius:var(--r-sm);padding:20px;display:flex;gap:16px;align-items:flex-start}
.why-pillar i{font-size:22px;color:#C9A96E;flex-shrink:0;margin-top:2px}
.why-pillar h4{font-size:15px;font-weight:500;color:#fff;margin-bottom:6px}
.why-pillar p{font-size:13px;color:rgba(255,255,255,0.65);line-height:1.65;font-weight:300}

/* FOR PARTNERS */
.partners-section{background:var(--surface);padding:56px 24px;border-top:1px solid var(--border)}
@media (min-width: 768px) {
  .partners-section { padding: 80px 48px; display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: start; }
}
.partners-body{font-size:15px;color:var(--text2);font-weight:300;line-height:1.8;margin-top:14px;margin-bottom:28px; max-width: 500px;}
@media (min-width: 768px) {
  .partners-body { font-size: 16px; margin-bottom: 0;}
}
.partners-body strong{font-weight:500;color:var(--text)}
.partners-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:28px}
@media (min-width: 768px) {
  .partners-grid { margin-bottom: 0; }
}
.partner-cell{background:var(--bg);border:1px solid var(--border);border-radius:14px;padding:18px;text-align:center; transition: background 0.3s ease;}
.partner-cell:hover{background: var(--surface2);}
.partner-cell .org{font-size:13px;font-weight:500;color:var(--text);margin-bottom:4px}
.partner-cell .type{font-size:11px;color:var(--text3);font-weight:400;letter-spacing:0.3px}
.contact-cta{background:var(--accent-light);border:1px solid #C8D8EC;border-radius:var(--r);padding:24px;text-align:center; box-shadow: inset 0 2px 10px rgba(255,255,255,0.5);}
@media (min-width: 768px) {
  .contact-cta { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; padding: 32px 48px; margin-top: 24px; text-align: left; }
  .contact-cta p { margin-bottom: 0; max-width: 600px; }
}
.contact-cta p{font-size:15px;color:var(--accent);font-weight:400;line-height:1.7;margin-bottom:18px}
.contact-cta button{background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);color:#fff;border:none;padding:12px 28px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;cursor:pointer; box-shadow: var(--shadow-btn); transition: all 0.3s ease; white-space: nowrap; flex-shrink: 0;}
.contact-cta button:hover{transform: translateY(-2px); box-shadow: 0 6px 16px rgba(30, 58, 95, 0.3);}

/* FOOTER */
.footer{background:var(--accent);color:rgba(255,255,255,0.8);padding:56px 24px 40px}
@media (min-width: 768px) {
  .footer { padding: 80px 48px 40px; }
}

.footer-logo-link{display:inline-block;margin-bottom:16px;text-decoration:none;outline:none;}
.footer-site-logo{max-height:40px;width:auto;object-fit:contain;transition:opacity 0.2s ease, transform 0.3s ease;}
.footer-logo-link:hover .footer-site-logo{opacity:0.9;transform:scale(1.02);}
@media (min-width: 768px) {
  .footer-site-logo{max-height:48px;}
}

.footer-logo{font-family:'Cormorant Garamond',serif;font-size:28px;font-weight:400;color:#fff;margin-bottom:10px}
.footer-logo-dot{color:#C9A96E}
.footer-tagline{font-size:14px;color:rgba(255,255,255,0.6);font-weight:300;line-height:1.7;margin-bottom:32px;max-width:320px}
.footer-social{display:flex;gap:12px;margin-bottom:32px}
.social-btn{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.7);font-size:18px;cursor:pointer; transition: all 0.3s ease;}
.social-btn:hover{background:rgba(255,255,255,0.15); color: #fff; transform: translateY(-2px);}
.footer-divider{height:1px;background:rgba(255,255,255,0.1);margin:0 0 32px}

.footer-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:32px}
@media (min-width: 768px) {
  .footer-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 48px;}
  .footer-grid { margin-bottom: 0; gap: 64px; }
  .footer-divider.top-div { display: none; }
}

.footer-col-title{font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.4);margin-bottom:16px}
.footer-links{display:flex;flex-direction:column;gap:12px}
.footer-link{font-size:14px;color:rgba(255,255,255,0.7);font-weight:300;cursor:pointer; transition: color 0.2s;}
.footer-link:hover{color: #fff;}
.footer-badges{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:32px}
.footer-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);padding:6px 14px;border-radius:50px;font-size:12px;color:rgba(255,255,255,0.6);font-weight:300}
.footer-badge i{font-size:14px;color:rgba(255,255,255,0.4)}
.footer-bottom{font-size:12px;color:rgba(255,255,255,0.4);font-weight:300;line-height:1.8;border-top:1px solid rgba(255,255,255,0.1);padding-top:24px}

/* CHAT INTERFACE ENHANCEMENTS */
#chat{display:none;flex-direction:column; background: var(--bg);}
#chat.active{display:flex}
.chat-nav{background:rgba(255,255,255,0.9); backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px); border-bottom:1px solid var(--border);padding:14px 16px;padding-top:max(14px,env(safe-area-inset-top));display:flex;align-items:center;gap:12px;flex-shrink:0; box-shadow: 0 2px 10px rgba(0,0,0,0.02);}
.back-btn{background:var(--surface2);border:1px solid var(--border);cursor:pointer;width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--text);font-size:20px;flex-shrink:0; transition: all 0.2s ease;}
.back-btn:active{transform: scale(0.95);}
.chat-av-sm{width:42px;height:42px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0; box-shadow: 0 2px 8px rgba(0,0,0,0.05);}
.chat-info{flex:1;min-width:0}
.chat-info h3{font-size:16px;font-weight:500;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-info p{font-size:12px;color:var(--text2);font-weight:400; margin-top:2px;}
.chat-logo-sm{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:500;color:var(--accent);flex-shrink:0}
.icon-btn{background:none;border:none;cursor:pointer;width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:18px; transition: background 0.2s;}
.icon-btn:hover{background:var(--surface2); color: var(--accent);}

.messages{flex:1;overflow-y:auto;overflow-x:hidden;padding:20px 16px;display:flex;flex-direction:column;gap:18px;-webkit-overflow-scrolling:touch}
.msg{display:flex;gap:10px;max-width:88%;animation:slideUpFade .3s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; transform: translateY(10px);}
@media (min-width: 768px) {
  .msg { max-width: 70%; }
}
.msg.user{align-self:flex-end;flex-direction:row-reverse}
.msg.ai{align-self:flex-start}
.msg-av{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;align-self:flex-end; box-shadow: 0 2px 6px rgba(0,0,0,0.06);}

.msg-bubble{padding:12px 16px;border-radius:20px;font-size:15px;line-height:1.6;font-weight:400;word-break:break-word; box-shadow: var(--shadow-sm);}
.msg.ai .msg-bubble{background:var(--surface);border:1px solid var(--border);color:var(--text);border-bottom-left-radius:6px}
.msg.user .msg-bubble{background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);color:#fff;border-bottom-right-radius:6px; box-shadow: 0 4px 12px rgba(30, 58, 95, 0.15);}
.msg-time{font-size:11px;color:var(--text3);align-self:flex-end;flex-shrink:0;white-space:nowrap; margin-bottom: 4px;}
.dot{width:6px;height:6px;border-radius:50%;background:var(--text3);animation:bounce 1.4s infinite ease-in-out;display:inline-block}
.dot:nth-child(2){animation-delay:.2s}.dot:nth-child(3){animation-delay:.4s}
@keyframes bounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-6px)}}

.chat-footer{flex-shrink:0;background:rgba(255,255,255,0.9); backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px); border-top:1px solid var(--border);padding-bottom:env(safe-area-inset-bottom); box-shadow: 0 -2px 10px rgba(0,0,0,0.01);}
.limit-banner{margin:12px 16px 0;background:var(--gold-light);border:1px solid #E5CFA0;border-radius:12px;padding:12px 16px;display:flex;align-items:flex-start;gap:10px;font-size:13px; box-shadow: var(--shadow-sm); animation: fadeIn 0.3s ease;}
.limit-banner i{color:var(--gold);font-size:18px;flex-shrink:0;margin-top:1px}
.limit-banner span{color:var(--text2);font-weight:400;line-height:1.5}
.limit-banner a{color:var(--gold);font-weight:500;cursor:pointer;text-decoration:none; border-bottom: 1px solid transparent; transition: border-color 0.2s;}
.limit-banner a:hover{border-color: var(--gold);}
.input-area{padding:12px 16px 16px;display:flex;gap:10px;align-items:flex-end}
.input-wrap{flex:1;background:var(--surface2);border:1.5px solid transparent;border-radius:24px;padding:10px 16px;display:flex;align-items:center;transition:all .3s ease; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);}
.input-wrap:focus-within{border-color:var(--accent); background: var(--surface); box-shadow: 0 0 0 3px var(--accent-light);}
.chat-input{flex:1;background:none;border:none;outline:none;font-family:'Outfit',sans-serif;font-size:15px;color:var(--text);resize:none;max-height:80px;font-weight:400;line-height:1.5; padding: 2px 0;}
.chat-input::placeholder{color:var(--text3)}
.send-btn{width:46px;height:46px;border-radius:50%;border:none;background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0; box-shadow: var(--shadow-btn); transition: all 0.2s ease;}
.send-btn:hover:not(:disabled){transform: translateY(-2px); box-shadow: 0 6px 16px rgba(30, 58, 95, 0.35);}
.send-btn:active:not(:disabled){transform:scale(0.95);}
.send-btn:disabled{background:var(--surface3);color:var(--text3); box-shadow: none; cursor: not-allowed;}
.mic-btn{width:46px;height:46px;border-radius:50%;border:1.5px solid var(--border);background:var(--surface);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;transition:all 0.2s ease}
.mic-btn:hover{border-color:var(--accent);color:var(--accent)}
.mic-btn.listening{background:var(--rose);border-color:var(--rose);color:#fff;animation:micPulse 1.2s ease-in-out infinite}
.mic-btn:disabled{opacity:.4;cursor:not-allowed}
@keyframes micPulse{0%,100%{box-shadow:0 0 0 0 rgba(139,58,82,0.35)}50%{box-shadow:0 0 0 8px rgba(139,58,82,0)}}
.voice-play-btn{width:26px;height:26px;border-radius:50%;border:1px solid var(--border);background:var(--surface);color:var(--accent);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:11px;margin-left:8px;vertical-align:middle;transition:all 0.2s ease;flex-shrink:0}
.voice-play-btn:hover{border-color:var(--accent);background:var(--accent-light)}
.voice-play-btn.loading i{animation:spin 0.8s linear infinite}
.voice-play-btn.playing{background:var(--accent);color:#fff;border-color:var(--accent)}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

.call-overlay{
  position:absolute;inset:0;background:linear-gradient(180deg,var(--navy) 0%,var(--accent) 100%);
  display:none;flex-direction:column;align-items:center;justify-content:center;
  padding:40px 24px;z-index:60;color:#fff;text-align:center;
}
.call-overlay.active{display:flex}
.call-close{position:absolute;top:max(16px,env(safe-area-inset-top));right:16px;width:38px;height:38px;border-radius:50%;border:none;background:rgba(255,255,255,.12);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
.call-status{font-size:12px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:28px;font-weight:600}
.call-avatar-wrap{position:relative;width:140px;height:140px;margin-bottom:22px}
.call-avatar{width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:52px;color:#fff;position:relative;z-index:2}
.call-ring{position:absolute;inset:-14px;border-radius:50%;border:2px solid rgba(255,255,255,.25);opacity:0;transform:scale(.9)}
.call-ring.speaking{animation:callPulse 1.6s ease-in-out infinite}
@keyframes callPulse{0%{opacity:.7;transform:scale(.95)}70%{opacity:0;transform:scale(1.25)}100%{opacity:0;transform:scale(1.25)}}
.call-name{font-family:'Cormorant Garamond',serif;font-size:28px;font-weight:500;margin-bottom:6px}
.call-timer{font-size:14px;color:rgba(255,255,255,.7);font-variant-numeric:tabular-nums;margin-bottom:22px}
.call-caption{font-size:14.5px;color:rgba(255,255,255,.85);min-height:42px;max-width:340px;line-height:1.5;margin-bottom:30px}
.call-controls{display:flex;gap:18px;align-items:center}
.call-ctrl-btn{width:56px;height:56px;border-radius:50%;border:none;background:rgba(255,255,255,.14);color:#fff;font-size:20px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s ease}
.call-ctrl-btn:hover{background:rgba(255,255,255,.22)}
.call-ctrl-btn.muted{background:#fff;color:var(--navy)}
.call-ctrl-btn.end{background:var(--rose);width:64px;height:64px;font-size:24px}
.call-ctrl-btn.end:hover{background:#7a2f44}
.call-plan-note{margin-top:22px;font-size:12px;color:rgba(255,255,255,.55);max-width:300px}
.call-plan-note a{color:#fff;text-decoration:underline}

/* MODAL ENHANCEMENTS */
.modal-overlay{position:fixed;inset:0;background:rgba(18,16,10,.65); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); display:none;align-items:flex-end;justify-content:center;z-index:200; opacity: 0; transition: opacity 0.3s ease;}
@media(min-width:480px){.modal-overlay{align-items:center}}
.modal-overlay.open{display:flex; opacity: 1;}
.modal{background:var(--surface);border-radius:28px 28px 0 0;padding:28px 24px 40px;width:100%;max-width:480px;position:relative;max-height:92vh;overflow-y:auto; transform: translateY(100%); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: 0 -10px 40px rgba(0,0,0,0.1);}
.modal-overlay.open .modal{transform: translateY(0);}
@media(min-width:480px){
  .modal{border-radius:28px;padding:40px 32px; transform: scale(0.95) translateY(20px);}
  .modal-overlay.open .modal{transform: scale(1) translateY(0); box-shadow: var(--shadow-hover);}
}
.modal-handle{width:40px;height:5px;background:var(--border);border-radius:3px;margin:0 auto 24px}
@media(min-width:480px){.modal-handle{display:none}}
.modal-close{position:absolute;top:20px;right:20px;background:var(--surface2);border:none;cursor:pointer;color:var(--text2);font-size:22px;display:none; width: 36px; height: 36px; border-radius: 50%; align-items: center; justify-content: center; transition: background 0.2s;}
.modal-close:hover{background: var(--border); color: var(--text);}
@media(min-width:480px){.modal-close{display:flex}}
.modal h2{font-family:'Cormorant Garamond',serif;font-size:30px;margin-bottom:8px;font-weight:400; color: var(--text);}
.modal .sub{font-size:15px;color:var(--text2);margin-bottom:28px;font-weight:300;line-height:1.6}
.demo-hint{background:var(--accent-light);border-radius:12px;padding:14px 16px;font-size:13px;color:var(--accent);margin-bottom:24px;line-height:1.7;border:1px solid #C8D8EC; box-shadow: inset 0 2px 8px rgba(255,255,255,0.5);}
.demo-hint strong{font-weight:500;display:block;margin-bottom:4px}
.form-field{margin-bottom:18px}
.form-field label{display:block;font-size:13px;font-weight:500;color:var(--text2);margin-bottom:6px;letter-spacing:0.2px}
.pw-wrap{position:relative;display:flex;align-items:center}
.pw-toggle{position:absolute;right:16px;background:none;border:none;color:var(--text3);cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:color 0.2s}
.pw-toggle:hover{color:var(--accent)}
.form-field input{width:100%;padding:14px 16px;border:1.5px solid var(--border);border-radius:14px;font-family:'Outfit',sans-serif;font-size:15px;color:var(--text);background:var(--bg);outline:none;transition:all .3s ease;font-weight:400; box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);}
.form-field input[type="password"], .form-field input.pw-input{padding-right:48px;}
.form-field input:focus{border-color:var(--accent); background: var(--surface); box-shadow: 0 0 0 4px var(--accent-light);}
.user-menu{display:flex;align-items:center;gap:10px;}
.user-av{width:34px;height:34px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;}
.user-name{font-size:14px;font-weight:500;color:var(--text);display:none;}
@media(min-width:480px){.user-name{display:block;}}
.modal-btn{width:100%;padding:16px;border-radius:50px;border:none;background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);color:#fff;font-family:'Outfit',sans-serif;font-size:15px;font-weight:500;cursor:pointer;margin-top:10px; box-shadow: var(--shadow-btn); transition: all 0.3s ease;}
.modal-btn:hover{transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 58, 95, 0.3);}
.modal-btn:active{transform: translateY(0); box-shadow: var(--shadow-sm);}
.modal-switch{text-align:center;margin-top:20px;font-size:14px;color:var(--text2)}
.modal-switch a{color:var(--accent);cursor:pointer;font-weight:500;text-decoration:none; border-bottom: 1px solid transparent; transition: border-color 0.2s;}
.modal-switch a:hover{border-color: var(--accent);}
.success-wrap{text-align:center;padding:12px 0}
.success-icon{width:64px;height:64px;border-radius:50%;background:var(--sage-light);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:30px;color:var(--sage); box-shadow: 0 4px 12px rgba(46, 92, 62, 0.15);}
.pro-card{background:linear-gradient(135deg, var(--accent) 0%, var(--navy) 100%);border-radius:20px;padding:24px;margin-top:28px;color:#fff;text-align:left; box-shadow: var(--shadow-md);}
.pro-card h4{font-family:'Cormorant Garamond',serif;font-size:22px;margin-bottom:8px;font-weight:400}
.pro-card p{font-size:14px;opacity:.85;font-weight:300;line-height:1.65;margin-bottom:20px}
.pro-card button{background:#fff;color:var(--accent);border:none;padding:12px 24px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;cursor:pointer; transition: all 0.2s ease;}
.pro-card button:hover{transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15);}

/* --- DESKTOP CHAT REFINEMENTS (App-like Column Layout) --- */
@media (min-width: 768px) {
  #chat {
    align-items: center; 
    background: var(--surface3); /* Softer outer frame background */
  }
  .chat-nav, .messages, .chat-footer {
    width: 100%;
    max-width: 880px; /* Constrain ultra-wide stretching */
    border-left: 1px solid var(--border);
    border-right: 1px solid var(--border);
    box-shadow: 0 0 50px rgba(0,0,0,0.04); /* Subtle depth */
  }
  .messages {
    background: var(--bg); /* Ensure messages area stays standard color */
    padding: 32px 48px; /* Breathe room for messages */
  }
  .chat-nav {
    padding: 20px 32px; 
    border-bottom: 1px solid var(--border);
  }
  .chat-footer {
    padding-bottom: 24px;
    border-top: 1px solid var(--border);
  }
  .upgraded-input-area {
    padding: 16px 32px 24px !important;
  }
  .limit-banner {
    margin: 16px 32px 0;
  }
}
@media (min-width: 880px) {
  .chat-dropdown {
    right: calc(50vw - 408px); /* 440px half-width - 32px edge margin */
  }
}
</style>