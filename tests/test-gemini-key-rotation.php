<?php
/**
 * Exercises the shared Gemini key-pool behavior with mocked HTTP responses.
 */
class Test_Gemini_Key_Rotation extends WP_UnitTestCase {

    private $original_keys;
    private $attempted_keys = array();
    private $responder;

    public function set_up() {
        parent::set_up();
        $this->original_keys = get_option( 'kounselia_gemini_key', null );
        $this->attempted_keys = array();
        $this->responder = null;

        $keys = array();
        for ( $i = 1; $i <= 12; $i++ ) {
            $keys[] = 'test-gemini-key-' . $i;
        }
        update_option( 'kounselia_gemini_key', implode( ',', $keys ) );
        update_option( 'kounselia_gemini_key_rotation_cursor', 0, false );
        delete_transient( 'kounselia_key_cooldowns' );
        add_filter( 'pre_http_request', array( $this, 'intercept_gemini_request' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'intercept_gemini_request' ), 10 );
        if ( null === $this->original_keys ) {
            delete_option( 'kounselia_gemini_key' );
        } else {
            update_option( 'kounselia_gemini_key', $this->original_keys );
        }
        delete_option( 'kounselia_gemini_key_rotation_cursor' );
        delete_transient( 'kounselia_key_cooldowns' );
        parent::tear_down();
    }

    public function intercept_gemini_request( $pre, $args, $url ) {
        if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
            return $pre;
        }

        $key = '';
        $query = array();
        parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
        if ( isset( $query['key'] ) ) {
            $key = $query['key'];
        } elseif ( isset( $args['headers']['x-goog-api-key'] ) ) {
            $key = $args['headers']['x-goog-api-key'];
        }
        $this->attempted_keys[] = $key;

        if ( is_callable( $this->responder ) ) {
            return call_user_func( $this->responder, $key, $args, $url );
        }

        return $pre;
    }

    private function response( $status, $data, $headers = array() ) {
        return array(
            'headers'  => $headers,
            'body'     => wp_json_encode( $data ),
            'response' => array( 'code' => $status, 'message' => ( 200 === $status ) ? 'OK' : 'Error' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    private function quota_response() {
        return $this->response( 429, array(
            'error' => array(
                'code'    => 429,
                'status'  => 'RESOURCE_EXHAUSTED',
                'message' => 'Quota exceeded.',
            ),
        ) );
    }

    public function test_key_pool_accepts_more_than_ten_keys_and_deduplicates() {
        $keys = kounselia_parse_gemini_keys( " alpha ; beta\nalpha, gamma " );

        $this->assertSame( array( 'alpha', 'beta', 'gamma' ), $keys );
        $this->assertCount( 12, kounselia_get_gemini_keys() );
    }

    public function test_chat_tries_the_entire_twelve_key_pool_after_quota_errors() {
        $this->responder = function ( $key ) {
            if ( 'test-gemini-key-12' !== $key ) {
                return $this->quota_response();
            }

            return $this->response( 200, array(
                'candidates' => array(
                    array( 'content' => array( 'parts' => array( array( 'text' => 'The fallback key worked.' ) ) ) ),
                ),
            ) );
        };

        $error = '';
        $reply = kounselia_call_gemini(
            'Be concise.',
            array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'Hello' ) ) ) ),
            'gemini-3.6-flash',
            0.2,
            100,
            $error
        );

        $this->assertSame( 'The fallback key worked.', $reply );
        $this->assertCount( 12, $this->attempted_keys );
        $this->assertCount( 12, array_unique( $this->attempted_keys ) );
    }

    public function test_all_invalid_keys_return_a_sanitized_pool_error() {
        $this->responder = function () {
            return $this->response( 400, array(
                'error' => array(
                    'code'    => 400,
                    'status'  => 'INVALID_ARGUMENT',
                    'message' => 'API key not valid. Please pass a valid API key.',
                ),
            ) );
        };

        $error = '';
        $reply = kounselia_call_gemini(
            'Be concise.',
            array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'Hello' ) ) ) ),
            'gemini-3.6-flash',
            0.2,
            100,
            $error
        );

        $this->assertNull( $reply );
        $this->assertCount( 12, $this->attempted_keys );
        $this->assertStringContainsString( 'temporarily unavailable', $error );
        $this->assertStringNotContainsString( 'not valid', $error );
        $this->assertStringNotContainsString( 'test-gemini-key-', $error );
    }

    public function test_request_wide_bad_request_does_not_burn_through_keys() {
        $this->responder = function () {
            return $this->response( 400, array(
                'error' => array( 'code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Malformed request.' ),
            ) );
        };

        $error = '';
        $reply = kounselia_call_gemini(
            'Be concise.',
            array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'Hello' ) ) ) ),
            'gemini-3.6-flash',
            0.2,
            100,
            $error
        );

        $this->assertNull( $reply );
        $this->assertCount( 1, $this->attempted_keys );
    }

    public function test_tts_fails_over_to_the_next_key() {
        $this->responder = function ( $key ) {
            if ( 'test-gemini-key-1' === $key ) {
                return $this->quota_response();
            }

            return $this->response( 200, array(
                'candidates' => array(
                    array( 'content' => array( 'parts' => array(
                        array( 'inlineData' => array( 'data' => base64_encode( 'test-audio' ) ) ),
                    ) ) ),
                ),
            ) );
        };

        $error = '';
        $pcm = kounselia_call_gemini_tts( 'Hello there.', 'Kore', $error );

        $this->assertSame( 'test-audio', $pcm );
        $this->assertCount( 2, $this->attempted_keys );
    }

    public function test_live_voice_token_request_fails_over_using_header_keys() {
        $this->responder = function ( $key, $args ) {
            if ( 'test-gemini-key-1' === $key ) {
                return $this->quota_response();
            }

            $this->assertSame( $key, $args['headers']['x-goog-api-key'] );
            return $this->response( 200, array( 'name' => 'auth_tokens/test-token' ) );
        };

        $error = '';
        $token = kounselia_mint_live_token( 'Be kind.', 'Kore', false, $error );

        $this->assertNotNull( $token );
        $this->assertSame( 'auth_tokens/test-token', $token['token'] );
        $this->assertSame( array( 'test-gemini-key-1', 'test-gemini-key-2' ), $this->attempted_keys );
    }
}
