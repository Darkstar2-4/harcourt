<?php

namespace WPML\TM\Jobs\Query;

use \WPML_TM_Jobs_Search_Params;

interface Query {
	public function get_data_query( WPML_TM_Jobs_Search_Params $params );

	public function get_count_query( WPML_TM_Jobs_Search_Params $params );
}
