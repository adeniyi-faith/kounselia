<?php
/**
 * Kounselia Core — Branded HTML Email Engine
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wraps dynamic content in a premium, responsive HTML email template using 
 * Kounselia's brand colors, typography, and logo.
 */
function kounselia_send_html_email( $to, $subject, $headline, $content, $btn_text = null, $btn_url = null ) {
    $logo_url = 'https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png';
    $year     = date( 'Y' );
    
    $button_html = '';
    if ( $btn_text && $btn_url ) {
        $button_html = '
        <div style="text-align: center; margin-top: 35px; margin-bottom: 10px;">
            <a href="' . esc_url( $btn_url ) . '" style="display: inline-block; background-color: #1E3A5F; color: #ffffff; text-decoration: none; padding: 16px 36px; border-radius: 50px; font-family: Helvetica, Arial, sans-serif; font-weight: 600; font-size: 15px; box-shadow: 0 4px 14px rgba(30, 58, 95, 0.25);">
                ' . esc_html( $btn_text ) . '
            </a>
        </div>';
    }

    // Inline CSS is strictly required for reliable cross-client email rendering.
    // We use Georgia as a safe fallback for the Cormorant Garamond serif feel.
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . esc_html( $subject ) . '</title>
    </head>
    <body style="margin: 0; padding: 0; background-color: #F8F6F2; font-family: Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #F8F6F2; width: 100%; padding: 40px 20px;">
            <tr>
                <td align="center">
                    
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 540px; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 8px 24px rgba(24, 22, 15, 0.05);">
                        <!-- Header / Logo -->
                        <tr>
                            <td align="center" style="padding: 40px 40px 20px;">
                                <img src="' . esc_url( $logo_url ) . '" alt="Kounselia" width="160" style="display: block; border: 0; max-width: 100%; height: auto;">
                            </td>
                        </tr>
                        
                        <!-- Body Content -->
                        <tr>
                            <td style="padding: 0 40px 40px;">
                                <h1 style="color: #1E3A5F; font-family: Georgia, \'Times New Roman\', serif; font-size: 28px; font-weight: normal; margin: 0 0 20px; text-align: center;">
                                    ' . wp_kses_post( $headline ) . '
                                </h1>
                                
                                <div style="color: #5B574D; font-size: 16px; line-height: 1.7; font-weight: normal;">
                                    ' . $content . '
                                </div>
                                
                                ' . $button_html . '
                            </td>
                        </tr>
                    </table>

                    <!-- Footer -->
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 540px;">
                        <tr>
                            <td align="center" style="padding: 30px 20px; color: #A8A49A; font-size: 13px; line-height: 1.6;">
                                &copy; ' . $year . ' Kounselia.<br>
                                A global mental wellness initiative.<br>
                                <span style="font-size: 11px; margin-top: 10px; display: block;">This email was sent securely. Please do not reply directly to this message.</span>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </body>
    </html>';

    // Force WP to send as HTML instead of default plain text
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    return wp_mail( $to, $subject, $html, $headers );
}