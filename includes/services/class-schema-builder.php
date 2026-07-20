<?php
namespace LlamaHire\Services;

use LlamaHire\Contracts\Schema_Builder as Schema_Builder_Contract;
use LlamaHire\Jobs;

defined( 'ABSPATH' ) || exit;

final class Schema_Builder implements Schema_Builder_Contract {
	public function build( $job_id ) {
		$job_id = absint( $job_id );
		if ( ! $job_id || Jobs::POST_TYPE !== get_post_type( $job_id ) || 'publish' !== get_post_status( $job_id ) || ! Jobs::is_open( $job_id ) ) {
			return array();
		}

		$meta        = Jobs::get_meta( $job_id );
		$title       = get_the_title( $job_id );
		$description = wp_kses_post( apply_filters( 'the_content', get_post_field( 'post_content', $job_id ) ) );
		$organization = Jobs::organization( $meta );
		$is_remote    = 'remote' === $meta['workplace'];
		$has_address  = $meta['address_locality'] && $meta['address_country'];
		$has_remote_area = $is_remote && $meta['applicant_countries'];
		if ( ! $title || ! trim( wp_strip_all_tags( $description ) ) || ! $organization['name'] || ( ! $has_address && ! $has_remote_area ) ) {
			return array();
		}
		$departments = wp_get_post_terms( $job_id, 'llamahire_department', array( 'fields' => 'names' ) );
		$data        = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'JobPosting',
			'title'              => $title,
			'description'        => $description,
			'datePosted'         => get_the_date( DATE_W3C, $job_id ),
			'employmentType'     => $meta['employment_type'],
			'hiringOrganization' => array(
				'@type'  => 'Organization',
				'name'   => $organization['name'],
			),
			'identifier'         => array(
				'@type' => 'PropertyValue',
				'name'  => $organization['name'],
				'value' => $meta['job_identifier'] ?: 'llamahire-' . get_current_blog_id() . '-job-' . $job_id,
			),
			'directApply'        => true,
		);
		if ( $organization['website'] ) {
			$data['hiringOrganization']['sameAs'] = $organization['website'];
		}
		if ( $organization['logo'] ) {
			$data['hiringOrganization']['logo'] = $organization['logo'];
		}

		if ( $meta['deadline'] && Jobs::valid_date( $meta['deadline'] ) ) {
			try {
				$deadline = new \DateTimeImmutable( $meta['deadline'] . ' 23:59:59', wp_timezone() );
				$data['validThrough'] = $deadline->format( DATE_W3C );
			} catch ( \Exception $exception ) {
				// Invalid legacy values must never break the public job page.
			}
		}
		if ( $departments && ! is_wp_error( $departments ) ) {
			$data['industry'] = implode( ', ', $departments );
		}
		if ( $is_remote ) {
			$data['jobLocationType'] = 'TELECOMMUTE';
			$countries = array_map(
				static function ( $country ) {
					return array( '@type' => 'Country', 'name' => trim( $country ) );
				},
				explode( ',', $meta['applicant_countries'] )
			);
			$data['applicantLocationRequirements'] = 1 === count( $countries ) ? reset( $countries ) : $countries;
		} elseif ( $has_address ) {
			$address = array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $meta['address_locality'],
				'addressCountry'  => $meta['address_country'],
			);
			foreach ( array( 'address_street' => 'streetAddress', 'address_region' => 'addressRegion', 'postal_code' => 'postalCode' ) as $source => $property ) {
				if ( $meta[ $source ] ) {
					$address[ $property ] = $meta[ $source ];
				}
			}
			$data['jobLocation'] = array( '@type' => 'Place', 'address' => $address );
		}
		$has_salary = '' !== $meta['salary_min'] || '' !== $meta['salary_max'];
		$valid_range = '' === $meta['salary_min'] || '' === $meta['salary_max'] || (float) $meta['salary_max'] >= (float) $meta['salary_min'];
		if ( $has_salary && $meta['salary_currency'] && $valid_range ) {
			$value = array( '@type' => 'QuantitativeValue', 'unitText' => $meta['salary_unit'] );
			if ( '' !== $meta['salary_min'] && '' !== $meta['salary_max'] && (float) $meta['salary_min'] === (float) $meta['salary_max'] ) {
				$value['value'] = (float) $meta['salary_min'];
			} else {
				if ( '' !== $meta['salary_min'] ) {
					$value['minValue'] = (float) $meta['salary_min'];
				}
				if ( '' !== $meta['salary_max'] ) {
					$value['maxValue'] = (float) $meta['salary_max'];
				}
			}
			$data['baseSalary'] = array( '@type' => 'MonetaryAmount', 'currency' => $meta['salary_currency'], 'value' => $value );
		}

		/**
		 * Filters the JobPosting entity for one open job.
		 *
		 * Extensions must keep structured values consistent with visible content.
		 *
		 * @param array $data   JobPosting entity.
		 * @param int   $job_id Job post ID.
		 */
		return (array) apply_filters( 'llamahire_job_posting_schema', $data, $job_id );
	}
}
