<?php
/**
 * Languages: the shared translation files, the helper that reads them, the
 * language picker action, and the growth plan following the member's language.
 */
class Test_I18n extends WP_Ajax_UnitTestCase {

    private function locale_path( $code ) {
        return dirname( KOUNSELIA_CORE_DIR, 4 ) . '/packages/core/src/locales/' . $code . '.json';
    }

    private function ajax( $action, $params = array() ) {
        $_POST = array_merge( array( 'action' => $action, 'nonce' => wp_create_nonce( 'kounselia_auth' ) ), $params );
        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected: wp_send_json_* dies by design under the ajax test harness.
        } catch ( WPAjaxDieStopException $e ) {
            // Also expected for error responses.
        }
        $response             = json_decode( $this->_last_response, true );
        $this->_last_response = '';
        return $response;
    }

    function test_every_language_file_has_the_same_keys_and_placeholders() {
        $english = json_decode( file_get_contents( $this->locale_path( 'en' ) ), true );
        $this->assertNotEmpty( $english );
        foreach ( array_keys( kounselia_languages() ) as $code ) {
            $words = json_decode( file_get_contents( $this->locale_path( $code ) ), true );
            $this->assertIsArray( $words, "$code.json must be valid JSON" );
            $this->assertSame( array(), array_diff( array_keys( $english ), array_keys( $words ) ), "$code is missing keys" );
            $this->assertSame( array(), array_diff( array_keys( $words ), array_keys( $english ) ), "$code has extra keys" );
            foreach ( $english as $key => $text ) {
                preg_match_all( '/\{(\w+)\}/', $text, $a );
                preg_match_all( '/\{(\w+)\}/', $words[ $key ], $b );
                sort( $a[1] );
                sort( $b[1] );
                $this->assertSame( $a[1], $b[1], "$code placeholders differ for $key" );
            }
        }
    }

    function test_translation_uses_the_language_and_falls_back_to_english() {
        $this->assertSame( 'Plan de croissance', kounselia_t( 'growth.title', array(), 'fr' ) );
        $this->assertSame( 'Growth plan', kounselia_t( 'growth.title', array(), 'en' ) );
        $this->assertSame( 'Growth plan', kounselia_t( 'growth.title', array(), 'xx' ) );
        $this->assertSame( 'no.such.key', kounselia_t( 'no.such.key', array(), 'fr' ) );
    }

    function test_placeholders_are_filled_in() {
        $this->assertSame( 'Day 3 of 30', kounselia_t( 'growth.day_of', array( 'day' => 3, 'total' => 30 ), 'en' ) );
        $this->assertStringContainsString( '3', kounselia_t( 'growth.day_of', array( 'day' => 3, 'total' => 30 ), 'ar' ) );
    }

    function test_only_arabic_is_right_to_left() {
        $this->assertTrue( kounselia_language_is_rtl( 'ar' ) );
        $this->assertFalse( kounselia_language_is_rtl( 'fr' ) );
        $this->assertSame( 'en', kounselia_language_normalize( 'de' ) );
        $this->assertSame( 'pt', kounselia_language_normalize( 'PT' ) );
    }

    function test_choosing_a_language_is_saved_and_used() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $res = $this->ajax( 'kounselia_set_language', array( 'language' => 'es' ) );
        $this->assertTrue( $res['success'] );
        $this->assertSame( 'es', $res['data']['language'] );
        $this->assertSame( 'es', kounselia_current_language( $user ) );
    }

    function test_an_unknown_language_is_refused() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $res = $this->ajax( 'kounselia_set_language', array( 'language' => 'zz' ) );
        $this->assertFalse( $res['success'] );
        $this->assertSame( 'en', kounselia_current_language( $user ) );
    }

    function test_growth_areas_and_questions_come_in_the_members_language() {
        $this->assertSame( 'Construire de meilleures habitudes', kounselia_growth_areas( 'fr' )['habits']['label'] );
        $this->assertSame( kounselia_t( 'growth.area.habits.label', array(), 'en' ), kounselia_growth_areas( 'en' )['habits']['label'] );
        $this->assertNotSame(
            kounselia_growth_questions( 'habits', 'en' )[0]['label'],
            kounselia_growth_questions( 'habits', 'es' )[0]['label']
        );
    }

    function test_the_plan_prompt_asks_the_ai_to_write_in_the_members_language() {
        $this->assertStringContainsString( 'French', kounselia_growth_build_prompt( 'habits', array( 'goal' => 'x' ), 'Ada', 'fr' ) );
        $this->assertStringContainsString( 'Arabic', kounselia_growth_build_prompt( 'habits', array( 'goal' => 'x' ), 'Ada', 'ar' ) );
    }

    function test_a_plan_remembers_the_language_it_was_made_in() {
        $user = self::factory()->user->create();
        update_user_meta( $user, 'kounselia_language', 'pt' );
        add_filter( 'kounselia_growth_plan_ai_response', function () {
            $list = array();
            for ( $i = 1; $i <= 30; $i++ ) {
                $list[] = array( 'day' => $i, 'title' => "Passo $i", 'task' => "Faça algo pequeno $i.", 'minutes' => 10 );
            }
            return wp_json_encode( array( 'title' => 'Manhãs calmas', 'summary' => 'Um mês mais calmo.', 'days' => $list ) );
        } );
        $plan = kounselia_growth_create_plan( $user, 'habits', array( 'goal' => 'Acordar cedo', 'time' => '15' ) );
        $this->assertIsInt( $plan );
        $saved = kounselia_growth_format_plan( kounselia_growth_active_plan( $user ) );
        $this->assertSame( 'pt', $saved['language'] );
    }
}
