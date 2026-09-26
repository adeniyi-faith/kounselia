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
 * Builds the full branded email document (no sending). Split out of
 * kounselia_send_html_email() so the newsletter composer can show an
 * exact preview of what recipients will receive.
 *
 * $opts (all optional):
 *   preheader    — the grey preview line inboxes show after the subject.
 *   footer_html  — extra footer markup, e.g. the unsubscribe links.
 *   pixel_url    — open-tracking image URL (newsletters only).
 */
function kounselia_render_email_html( $subject, $headline, $content, $btn_text = null, $btn_url = null, $opts = array() ) {
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

    $preheader_html = '';
    if ( ! empty( $opts['preheader'] ) ) {
        // Hidden text that inboxes show as the preview snippet.
        $preheader_html = '<div style="display:none;font-size:1px;color:#F8F6F2;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . esc_html( $opts['preheader'] ) . str_repeat( '&#847;&zwnj;&nbsp;', 40 ) . '</div>';
    }

    $headline_html = '';
    if ( '' !== trim( (string) $headline ) ) {
        $headline_html = '<h1 style="color: #1E3A5F; font-family: Georgia, \'Times New Roman\', serif; font-size: 28px; font-weight: normal; margin: 0 0 20px; text-align: center;">
                                    ' . wp_kses_post( $headline ) . '
                                </h1>';
    }

    $footer_extra = ! empty( $opts['footer_html'] ) ? '<div style="margin-top: 14px;">' . $opts['footer_html'] . '</div>' : '';
    $pixel        = ! empty( $opts['pixel_url'] ) ? '<img src="' . esc_url( $opts['pixel_url'] ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">' : '';

    // Inline CSS is strictly required for reliable cross-client email rendering.
    // We use Georgia as a safe fallback for the Cormorant Garamond serif feel.
    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . esc_html( $subject ) . '</title>
    </head>
    <body style="margin: 0; padding: 0; background-color: #F8F6F2; font-family: Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
        ' . $preheader_html . '
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
                                ' . $headline_html . '

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
                                ' . $footer_extra . '
                                ' . $pixel . '
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>
        </table>
    </body>
    </html>';
}

/**
 * Wraps dynamic content in a premium, responsive HTML email template using
 * Kounselia's brand colors, typography, and logo.
 *
 * $opts is passed through to kounselia_render_email_html(); it may also
 * carry 'headers' (extra mail headers, e.g. List-Unsubscribe).
 */
function kounselia_send_html_email( $to, $subject, $headline, $content, $btn_text = null, $btn_url = null, $opts = array() ) {
    $html = kounselia_render_email_html( $subject, $headline, $content, $btn_text, $btn_url, $opts );

    // Force WP to send as HTML instead of default plain text
    $headers = array( 'Content-Type: text/html; charset=UTF-8' );
    if ( ! empty( $opts['headers'] ) && is_array( $opts['headers'] ) ) {
        $headers = array_merge( $headers, $opts['headers'] );
    }

    return wp_mail( $to, $subject, $html, $headers );
}

/**
 * Rich-editor HTML is styled by a stylesheet on the website, but most
 * email apps ignore <style> blocks. This copies the important styles
 * onto each element as inline style="" so headings, quotes, images and
 * "button" links look right in Gmail/Outlook too.
 */
function kounselia_email_inline_styles( $html ) {
    if ( '' === trim( (string) $html ) || ! class_exists( 'DOMDocument' ) ) {
        return (string) $html;
    }

    $styles = array(
        'p'          => 'margin:0 0 18px;',
        'h2'         => 'font-family:Georgia,\'Times New Roman\',serif;color:#1E3A5F;font-size:24px;font-weight:normal;line-height:1.3;margin:28px 0 12px;',
        'h3'         => 'font-family:Georgia,\'Times New Roman\',serif;color:#1E3A5F;font-size:20px;font-weight:normal;line-height:1.3;margin:24px 0 10px;',
        'h4'         => 'color:#18160F;font-size:16px;font-weight:bold;margin:20px 0 8px;',
        'blockquote' => 'margin:22px 0;padding:4px 0 4px 18px;border-left:3px solid #B07D3A;color:#18160F;font-style:italic;',
        'img'        => 'max-width:100%;height:auto;border-radius:12px;display:block;margin:18px auto;',
        'a'          => 'color:#8B3A52;',
        'ul'         => 'margin:0 0 18px;padding-left:22px;',
        'ol'         => 'margin:0 0 18px;padding-left:22px;',
        'li'         => 'margin:0 0 6px;',
        'hr'         => 'border:none;border-top:1px solid #E8E4DB;margin:28px 0;',
        'table'      => 'border-collapse:collapse;width:100%;margin:0 0 18px;',
        'td'         => 'border:1px solid #E8E4DB;padding:8px;',
        'th'         => 'border:1px solid #E8E4DB;padding:8px;background:#F2EFE9;text-align:left;',
    );
    $class_styles = array(
        'k-btn'     => 'display:inline-block;background-color:#1E3A5F;color:#ffffff;text-decoration:none;padding:14px 30px;border-radius:50px;font-weight:600;font-size:15px;',
        'k-callout' => 'background:#FBF5EA;border-radius:14px;padding:18px 20px;margin:22px 0;color:#18160F;',
        'k-lead'    => 'font-size:19px;color:#18160F;',
        'k-center'  => 'text-align:center;',
    );

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8"?><div id="k-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $xpath = new DOMXPath( $doc );
    foreach ( $xpath->query( '//*' ) as $el ) {
        $tag   = strtolower( $el->nodeName );
        $style = isset( $styles[ $tag ] ) ? $styles[ $tag ] : '';
        foreach ( preg_split( '/\s+/', (string) $el->getAttribute( 'class' ) ) as $cls ) {
            if ( isset( $class_styles[ $cls ] ) ) {
                $style .= $class_styles[ $cls ];
            }
        }
        if ( '' !== $style ) {
            // The element's own inline style (e.g. text-align from the editor) wins.
            $el->setAttribute( 'style', $style . $el->getAttribute( 'style' ) );
        }
    }

    $root = $doc->getElementById( 'k-root' );
    if ( ! $root ) {
        return (string) $html;
    }
    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    return $out;
}