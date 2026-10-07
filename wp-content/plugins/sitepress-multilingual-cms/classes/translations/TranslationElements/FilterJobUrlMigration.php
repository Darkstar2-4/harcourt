<?php

namespace WPML\TM\Translations\TranslationElements;

class FilterJobUrlMigration {
	public function maybeFilterJobElementsAfterMigration( $job, $sitepress ) {
		if ( ! ICL_TM_COMPLETE === $job->status || ! isset( $job->elements ) ) {
			return $job;
		}

		$migrated_site = $sitepress->get_setting( 'migrated_site' );

		if ( ! isset( $migrated_site['old_url'] ) || ! isset( $migrated_site['new_url'] ) ) {
			return $job;
		}

		$old_url      = $migrated_site['old_url'];
		$new_url      = $migrated_site['new_url'];
		$url_replaced = false;

		foreach ( $job->elements as $element ) {
			if ( 'base64' !== $element->field_format ) {
				continue;
			}

			$original_field_data = $element->field_data;
			$element->field_data = $this->maybeFilterUrlInJobElement( $element->field_data, $old_url, $new_url );

			$original_field_data_translated = $element->field_data_translated;
			$element->field_data_translated = $this->maybeFilterUrlInJobElement( $element->field_data_translated, $old_url, $new_url );

			if (
				$original_field_data !== $element->field_data ||
				$original_field_data_translated !== $element->field_data_translated
			) {
				$url_replaced = true;
			}
		}

		if ( $url_replaced && \WPML_TM_Editors::ATE === $job->editor && ! $job->needs_update ) {
			$job->editor_job_id = null;
		}

		return $job;
	}

	private function maybeFilterUrlInJobElement( $element, $old_url, $new_url ) {
		$decoded = base64_decode( $element );

		if ( strpos( $decoded, $old_url ) === false ) {
			return $element;
		}

		$decoded = str_replace( $old_url, $new_url, $decoded );

		return base64_encode( $decoded );
	}

	public function isSiteMigrated( $sitepress ) {
		$migrated_site = $sitepress->get_setting( 'migrated_site' );
		return ! empty( $migrated_site );
	}
}
