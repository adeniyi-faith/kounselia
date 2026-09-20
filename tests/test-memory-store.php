<?php
/**
 * The structured memory profile (see
 * portal/wp-content/mu-plugins/kounselia/includes/memory-store.php) is
 * what every counselor prompt is built from. A bug in the save/load
 * round-trip silently corrupts what the AI "knows" about a user —
 * exactly the kind of thing that's easy to miss by eye (the JSON cache
 * would still look plausible) and costly to have wrong.
 */
class Test_Memory_Store extends WP_UnitTestCase {

    private function sample_profile() {
        return array(
            'identity'             => 'A new client exploring career change.',
            'life_timeline'        => array(
                array( 'year' => '2023', 'event' => 'Started a new job', 'impact' => 'High stress, low confidence at first' ),
            ),
            'emotional_map'        => array(
                'Mother' => array( 'emotion' => 'grief', 'intensity' => 'High', 'context' => 'Passed away in 2022' ),
            ),
            'goals'                => array( 'Feel more confident at work', 'Set better boundaries' ),
            'relationships'        => array( 'Partner' => 'Supportive, together 5 years' ),
            'important_people'     => array( 'Partner', 'Mother' ),
            'career'                => 'Product manager, 3 years in.',
            'health'                => 'Generally good, occasional insomnia.',
            'values'                => array( 'Honesty', 'Growth' ),
            'triggers'              => array( 'Being talked over in meetings' ),
            'traumas'               => array( 'Loss of a parent' ),
            'current_challenges'    => array( 'Imposter syndrome' ),
            'wins'                  => array( 'Got promoted' ),
            'preferences'           => array( 'tone' => 'direct but warm' ),
            'communication_style'   => 'Prefers directness',
            'personality'           => 'Introverted, analytical',
            'faith'                 => 'Not religious',
            'habits'                => array( 'Journals most mornings' ),
            'temporary_context'     => 'Had a rough week at work.',
        );
    }

    function test_save_then_load_round_trips_every_field() {
        $user_id = self::factory()->user->create();
        $profile = $this->sample_profile();

        kounselia_memory_save_profile( $user_id, $profile );
        $loaded = kounselia_memory_load_profile( $user_id );

        $this->assertSame( $profile['identity'], $loaded['identity'] );
        $this->assertSame( $profile['career'], $loaded['career'] );
        $this->assertSame( $profile['health'], $loaded['health'] );
        $this->assertSame( $profile['communication_style'], $loaded['communication_style'] );
        $this->assertSame( $profile['personality'], $loaded['personality'] );
        $this->assertSame( $profile['faith'], $loaded['faith'] );
        $this->assertSame( $profile['temporary_context'], $loaded['temporary_context'] );

        $this->assertCount( 1, $loaded['life_timeline'] );
        $this->assertSame( '2023', $loaded['life_timeline'][0]['year'] );
        $this->assertSame( 'Started a new job', $loaded['life_timeline'][0]['event'] );

        $this->assertArrayHasKey( 'Mother', $loaded['emotional_map'] );
        $this->assertSame( 'High', $loaded['emotional_map']['Mother']['intensity'] );

        $this->assertArrayHasKey( 'Partner', $loaded['relationships'] );

        foreach ( array( 'goals', 'important_people', 'values', 'triggers', 'traumas', 'current_challenges', 'wins', 'habits' ) as $field ) {
            $this->assertEqualSets( $profile[ $field ], $loaded[ $field ], "Field '{$field}' did not round-trip." );
        }

        $this->assertArrayHasKey( 'tone', $loaded['preferences'] );
        $this->assertSame( 'direct but warm', $loaded['preferences']['tone'] );

        // The usermeta cache every existing prompt/UI reader still relies on
        // must reflect the exact same data, not a stale or partial copy.
        $cached = json_decode( get_user_meta( $user_id, 'kounselia_core_memory', true ), true );
        $this->assertSame( $profile['identity'], $cached['identity'] );
        $this->assertCount( 1, $cached['life_timeline'] );
    }

    function test_saving_again_replaces_list_fields_instead_of_appending() {
        $user_id = self::factory()->user->create();
        kounselia_memory_save_profile( $user_id, array( 'goals' => array( 'Old goal' ) ) );
        kounselia_memory_save_profile( $user_id, array( 'goals' => array( 'New goal' ) ) );

        $loaded = kounselia_memory_load_profile( $user_id );
        $this->assertSame( array( 'New goal' ), $loaded['goals'] );
    }

    function test_delete_profile_clears_the_tables_and_the_cache() {
        $user_id = self::factory()->user->create();
        kounselia_memory_save_profile( $user_id, $this->sample_profile() );

        kounselia_memory_delete_profile( $user_id );

        $loaded = kounselia_memory_load_profile( $user_id );
        $this->assertSame( '', $loaded['identity'] );
        $this->assertSame( array(), $loaded['goals'] );
        $this->assertSame( array(), $loaded['life_timeline'] );

        $this->assertSame( '', get_user_meta( $user_id, 'kounselia_core_memory', true ) );
    }
}
