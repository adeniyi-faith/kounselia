<?php
/**
 * The dashboard "Welcome back, X" greeting must use a person's actual
 * first name, not a professional title like "Dr." that happens to be
 * the first word of their display name.
 */
class Test_Dashboard_Greeting extends WP_UnitTestCase {

    function test_plain_name_uses_first_word() {
        $this->assertSame( 'Bola', kounselia_greeting_first_name( 'Bola Pro' ) );
    }

    function test_single_word_name() {
        $this->assertSame( 'Maya', kounselia_greeting_first_name( 'Maya' ) );
    }

    function test_titled_name_skips_the_title() {
        $this->assertSame( 'Amara', kounselia_greeting_first_name( 'Dr. Amara Nwosu' ) );
        $this->assertSame( 'Amara', kounselia_greeting_first_name( 'Dr Amara Nwosu' ) );
        $this->assertSame( 'John', kounselia_greeting_first_name( 'Mr. John Doe' ) );
        $this->assertSame( 'Jane', kounselia_greeting_first_name( 'Prof. Jane Smith' ) );
    }

    function test_title_with_no_following_name_is_kept_as_is() {
        $this->assertSame( 'Dr.', kounselia_greeting_first_name( 'Dr.' ) );
    }
}
