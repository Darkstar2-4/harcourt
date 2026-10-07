<?php

use \WPML\Collect\Support\Traits\Macroable;
use function \WPML\FP\curryN;
use \WPML\LIB\WP\Cache;
use \WPML\FP\Logic;
use \WPML\TM\Jobs\JobLog;

class TranslationProxy_Batch {

	use Macroable;

	public static function update_translation_batch(
		$batch_name = false,
		$tp_id = false
	) {
		$batch_name = $batch_name
			? $batch_name
			: ( ( (bool) $tp_id === false || $tp_id === 'local' )
				? self::get_generic_batch_name() : TranslationProxy_Basket::get_basket_name() );
		if ( ! $batch_name ) {
			JobLog::add('Batch name not found `' . $batch_name . '`' );
			return null;
		}

		$getBatchId = function( $batch_name, $tp_id ) {
			$batch_id = self::getBatchId( $batch_name );

			if ( $batch_id ) {
				JobLog::add('Found existing batch with id `' . $batch_id . '` and name `' . $batch_name . '`' );
				return $batch_id;
			}

			$batch_id = self::createBatchRecord( $batch_name, $tp_id );
			JobLog::add('Created new batch with id `' . $batch_id . '` and name `' . $batch_name . '`' );

			return $batch_id;
		};

		$cache = Cache::memorizeWithCheck( 'update_translation_batch', Logic::isNotNull(), 0, $getBatchId );
		return $cache( $batch_name, $tp_id );
	}

	public static function get_generic_batch_name( $isAuto = false ) {
		if ( ! $isAuto && defined( 'WPML_DEBUG_TRANSLATION_PROXY' )  )
			\WPML\Utilities\DebugLog::storeBackTrace();

		return ( $isAuto ? 'Automatic Translations from ' : 'Manual Translations from ' ) . date( 'F \t\h\e jS\, Y' );
	}

	private static function create_generic_batch() {
		$batch_name = self::get_generic_batch_name();
		$batch_id   = self::update_translation_batch( $batch_name );

		return $batch_id;
	}

	public static function maybe_assign_generic_batch( $data ) {
		global $wpdb;

		$batch_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT batch_id
														 FROM {$wpdb->prefix}icl_translation_status
														 WHERE translation_id=%d",
				$data['translation_id']
			)
		);

		if ( ( $batch_id < 1 ) && isset( $data ['translation_service'] ) && $data ['translation_service'] == 'local' ) {
			$batch_id = self::create_generic_batch();
			$data_where = array( 'rid' => $data['rid'] );
			$wpdb->update(
				$wpdb->prefix . 'icl_translation_status',
				array( 'batch_id' => $batch_id ),
				$data_where
			);
		}
	}

	private static function createBatchRecord( $batch_name, $tp_id ) {
		global $wpdb;

		$data = [
			'batch_name'  => $batch_name,
			'last_update' => date( 'Y-m-d H:i:s' ),
		];
		if ( $tp_id ) {
			$data['tp_id'] = $tp_id === 'local' ? 0 : $tp_id;
		}
		$wpdb->insert( $wpdb->prefix . 'icl_translation_batches', $data );

		return $wpdb->insert_id;
	}
}

TranslationProxy_Batch::macro(
	'getBatchId',
	curryN(
		1,
		function( $batch_name ) {
			global $wpdb;

			$batch_id_sql      = "SELECT id FROM {$wpdb->prefix}icl_translation_batches WHERE batch_name=%s";
			$batch_id_prepared = $wpdb->prepare( $batch_id_sql, $batch_name );
			return $wpdb->get_var( $batch_id_prepared );
		}
	)
);

