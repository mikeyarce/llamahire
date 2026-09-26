#!/usr/bin/env bash

set -euo pipefail

WP_ENV=(npx wp-env --config=.wp-env.ci.json run cli wp)

cleanup() {
	"${WP_ENV[@]}" llamahire fixtures cleanup --yes >/dev/null 2>&1 || true
	"${WP_ENV[@]}" eval '$id = absint( get_option( "llamahire_fixture_test_unrelated_id" ) ); if ( $id ) { wp_delete_post( $id, true ); } delete_option( "llamahire_fixture_test_unrelated_id" ); delete_option( "llamahire_fixture_test_user_ids" );' >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup
"${WP_ENV[@]}" eval '$id = wp_insert_post( array( "post_type" => "post", "post_status" => "draft", "post_title" => "Unrelated fixture safety record" ) ); update_option( "llamahire_fixture_test_unrelated_id", $id, false );'
"${WP_ENV[@]}" llamahire fixtures generate --scenario=state-matrix --seed=fixture-test
"${WP_ENV[@]}" llamahire fixtures status --format=json
"${WP_ENV[@]}" eval '
$registry = get_option( "llamahire_fixture_registry" );
global $wpdb;
$table = \LlamaHire\Applications::table();
$statuses = $wpdb->get_col( "SELECT DISTINCT status FROM {$table} WHERE id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" );
sort( $statuses );
$notification_statuses = $wpdb->get_col( "SELECT DISTINCT notification_status FROM {$table} WHERE id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" );
sort( $notification_statuses );
$resume_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resume_path <> \"\" AND id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" );
$resume_extensions = array_values( array_unique( array_map( static function ( $name ) { return strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ); }, $wpdb->get_col( "SELECT resume_name FROM {$table} WHERE resume_name <> \"\" AND id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" ) ) ) );
sort( $resume_extensions );
$missing_phone_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE phone = \"\" AND id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" );
$missing_letter_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE cover_letter = \"\" AND id IN (" . implode( ",", array_map( "absint", wp_list_pluck( $registry["applications"], "id" ) ) ) . ")" );
$note_counts = array();
foreach ( wp_list_pluck( $registry["applications"], "id" ) as $application_id ) { $note_counts[] = count( \LlamaHire\Application_Notes::for_application( $application_id ) ); }
$job_states = array( "draft" => false, "pending" => false, "open" => false, "closing_soon" => false, "expiring_soon" => false, "deadline_expired" => false, "listing_expired" => false, "closed" => false, "exact_salary" => false, "no_salary" => false );
$methods = array();
$workplaces = array();
$authors = array();
$salary_units = array();
$today = current_time( "Y-m-d" );
foreach ( $registry["jobs"] as $job_id ) {
	$meta = \LlamaHire\Jobs::get_meta( $job_id );
	$job_states["draft"] = $job_states["draft"] || "draft" === get_post_status( $job_id );
	$job_states["pending"] = $job_states["pending"] || "pending" === get_post_status( $job_id );
	$job_states["open"] = $job_states["open"] || ( "publish" === get_post_status( $job_id ) && \LlamaHire\Jobs::is_open( $job_id ) );
	$job_states["closing_soon"] = $job_states["closing_soon"] || ( $meta["deadline"] && $meta["deadline"] >= $today && $meta["deadline"] <= wp_date( "Y-m-d", current_time( "timestamp" ) + 7 * DAY_IN_SECONDS ) );
	$job_states["expiring_soon"] = $job_states["expiring_soon"] || \LlamaHire\Jobs::listing_expires_soon( $job_id );
	$job_states["deadline_expired"] = $job_states["deadline_expired"] || ( $meta["deadline"] && $meta["deadline"] < $today );
	$job_states["listing_expired"] = $job_states["listing_expired"] || ( $meta["listing_expires"] && $meta["listing_expires"] < $today );
	$job_states["closed"] = $job_states["closed"] || "1" === $meta["closed"];
	$job_states["exact_salary"] = $job_states["exact_salary"] || ( "" !== $meta["salary_min"] && $meta["salary_min"] === $meta["salary_max"] );
	$job_states["no_salary"] = $job_states["no_salary"] || ( "" === $meta["salary_min"] && "" === $meta["salary_max"] );
	$methods[] = $meta["application_method"];
	$workplaces[] = $meta["workplace"];
	$authors[] = (int) get_post_field( "post_author", $job_id );
	$salary_units[] = $meta["salary_unit"];
}
sort( $methods );
$methods = array_values( array_unique( $methods ) );
sort( $workplaces );
$workplaces = array_values( array_unique( $workplaces ) );
sort( $salary_units );
$salary_units = array_values( array_unique( $salary_units ) );
$user_states = array();
$user_roles = array();
foreach ( $registry["users"] as $user_id ) {
	$user_states[] = get_user_meta( $user_id, \LlamaHire\Employer_Registration::STATUS_META, true );
	$user_roles = array_merge( $user_roles, (array) get_userdata( $user_id )->roles );
}
sort( $user_states );
$user_states = array_values( array_unique( array_filter( $user_states ) ) );
if ( 15 !== count( $registry["jobs"] ) || 48 !== count( $registry["applications"] ) || 5 !== count( $registry["users"] ) || 5 !== count( $registry["terms"] ) || 7 !== count( $registry["pages"] ) || 1 !== count( $registry["attachments"] ) ) { WP_CLI::error( "Fixture registry counts are incorrect." ); }
if ( array( "hired", "interviewing", "new", "offer", "rejected", "reviewing" ) !== $statuses || array( "failed", "partial", "pending", "sent" ) !== $notification_statuses || 16 !== $resume_count || !$missing_phone_count || !$missing_letter_count || 0 !== min( $note_counts ) || 2 !== max( $note_counts ) ) { WP_CLI::error( "Application state or material coverage is incomplete." ); }
if ( class_exists( "ZipArchive" ) && array( "docx", "pdf" ) !== $resume_extensions ) { WP_CLI::error( "Resume format coverage is incomplete." ); }
if ( in_array( false, $job_states, true ) ) { WP_CLI::error( "State-matrix job states are incomplete." ); }
if ( array( "external_email", "external_url", "internal" ) !== $methods || array( "hybrid", "onsite", "remote" ) !== $workplaces || array( "DAY", "HOUR", "MONTH", "WEEK", "YEAR" ) !== $salary_units || 2 > count( array_unique( array_filter( $authors ) ) ) ) { WP_CLI::error( "Job routing, workplace, pay-unit, or ownership coverage is incomplete." ); }
if ( array( "approved", "pending_approval", "pending_email" ) !== $user_states || !in_array( \LlamaHire\Capabilities::EMPLOYER_ROLE, $user_roles, true ) || !in_array( \LlamaHire\Capabilities::HIRING_MANAGER_ROLE, $user_roles, true ) ) { WP_CLI::error( "User role or registration-state coverage is incomplete." ); }
if ( \LlamaHire\Settings::SITE_MODE_JOB_BOARD !== \LlamaHire\Settings::site_mode() ) { WP_CLI::error( "The state matrix is not configured as a job board." ); }
foreach ( $registry["jobs"] as $job_id ) { if ( "llamahire-fixtures-v1" !== get_post_meta( $job_id, "_llamahire_fixture_owner", true ) ) { WP_CLI::error( "A generated job is missing its ownership marker." ); } }
foreach ( $registry["users"] as $user_id ) { if ( "llamahire-fixtures-v1" !== get_user_meta( $user_id, "_llamahire_fixture_owner", true ) ) { WP_CLI::error( "A generated user is missing its ownership marker." ); } }
update_option( "llamahire_fixture_test_user_ids", $registry["users"], false );
WP_CLI::success( "Fixture generation assertions passed." );
'
"${WP_ENV[@]}" llamahire fixtures cleanup --yes
"${WP_ENV[@]}" eval '
$unrelated = absint( get_option( "llamahire_fixture_test_unrelated_id" ) );
$fixture_users = array_filter( array_map( "get_userdata", (array) get_option( "llamahire_fixture_test_user_ids", array() ) ) );
if ( get_option( "llamahire_fixture_registry", false ) || $fixture_users || ! get_post( $unrelated ) ) { WP_CLI::error( "Fixture cleanup removed unrelated data or retained owned records." ); }
WP_CLI::success( "Fixture cleanup safety assertions passed." );
'

cleanup
trap - EXIT
