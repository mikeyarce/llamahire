#!/usr/bin/env bash

set -euo pipefail

WP_ENV=(npx wp-env --config=.wp-env.ci.json run cli wp)

cleanup() {
	"${WP_ENV[@]}" llamahire fixtures cleanup --yes >/dev/null 2>&1 || true
	"${WP_ENV[@]}" eval '$id = absint( get_option( "llamahire_fixture_test_unrelated_id" ) ); if ( $id ) { wp_delete_post( $id, true ); } delete_option( "llamahire_fixture_test_unrelated_id" );' >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup
"${WP_ENV[@]}" eval '$id = wp_insert_post( array( "post_type" => "post", "post_status" => "draft", "post_title" => "Unrelated fixture safety record" ) ); update_option( "llamahire_fixture_test_unrelated_id", $id, false );'
"${WP_ENV[@]}" llamahire fixtures generate --scenario=edge-cases --seed=fixture-test --jobs=7 --applications=16
"${WP_ENV[@]}" llamahire fixtures status --format=json
"${WP_ENV[@]}" eval '
$registry = get_option( "llamahire_fixture_registry" );
global $wpdb;
$table = \LlamaHire\Applications::table();
$statuses = $wpdb->get_col( "SELECT DISTINCT status FROM {$table} WHERE id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" );
$resume_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resume_path <> \"\" AND id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" );
$job_states = array( "draft" => false, "expired" => false, "closed" => false, "exact_salary" => false, "no_salary" => false );
foreach ( $registry["jobs"] as $job_id ) {
	$meta = \LlamaHire\Jobs::get_meta( $job_id );
	$job_states["draft"] = $job_states["draft"] || "draft" === get_post_status( $job_id );
	$job_states["expired"] = $job_states["expired"] || ( $meta["deadline"] && $meta["deadline"] < current_time( "Y-m-d" ) );
	$job_states["closed"] = $job_states["closed"] || "1" === $meta["closed"];
	$job_states["exact_salary"] = $job_states["exact_salary"] || ( "" !== $meta["salary_min"] && $meta["salary_min"] === $meta["salary_max"] );
	$job_states["no_salary"] = $job_states["no_salary"] || ( "" === $meta["salary_min"] && "" === $meta["salary_max"] );
}
if ( 7 !== count( $registry["jobs"] ) || 16 !== count( $registry["applications"] ) || 5 !== count( $registry["terms"] ) || 2 !== count( $registry["pages"] ) || 1 !== count( $registry["attachments"] ) ) { WP_CLI::error( "Fixture registry counts are incorrect." ); }
if ( 4 !== count( $statuses ) || 4 !== $resume_count ) { WP_CLI::error( "Application statuses or resumes are incomplete." ); }
if ( in_array( false, $job_states, true ) ) { WP_CLI::error( "Edge-case job states are incomplete." ); }
foreach ( $registry["jobs"] as $job_id ) { if ( "llamahire-fixtures-v1" !== get_post_meta( $job_id, "_llamahire_fixture_owner", true ) ) { WP_CLI::error( "A generated job is missing its ownership marker." ); } }
WP_CLI::success( "Fixture generation assertions passed." );
'
"${WP_ENV[@]}" llamahire fixtures cleanup --yes
"${WP_ENV[@]}" eval '
$unrelated = absint( get_option( "llamahire_fixture_test_unrelated_id" ) );
if ( get_option( "llamahire_fixture_registry", false ) || ! get_post( $unrelated ) ) { WP_CLI::error( "Fixture cleanup removed unrelated data or retained its registry." ); }
WP_CLI::success( "Fixture cleanup safety assertions passed." );
'

cleanup
trap - EXIT
