<?php

namespace WPML\PB\Elementor\Config\DynamicElements\EssentialAddons;

use WPML\FP\Obj;
use WPML\FP\Relation;
use function WPML\FP\compose;

class TeamMember {

	public static function get() {
		$isEATeamMember = Relation::propEq( 'widgetType', 'eael-team-member' );

		$socialLinkLens = compose(
			Obj::lensProp( 'settings' ),
			Obj::lensMappedProp( 'eael_team_member_social_profile_links' ),
			Obj::lensPath( [ '__dynamic__', 'link' ] )
		);

		return [ $isEATeamMember, $socialLinkLens, 'popup', 'popup' ];
	}
}
