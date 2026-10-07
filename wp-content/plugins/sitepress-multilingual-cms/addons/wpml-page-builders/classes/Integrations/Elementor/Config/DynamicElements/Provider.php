<?php

namespace WPML\PB\Elementor\Config\DynamicElements;

class Provider {

	public static function get() {
		return array_merge(
			[
				EssentialAddons\TeamMember::get(),
				EssentialAddons\ContentTimeline::get(),
				PremiumAddonsForElementor\PremiumAddonsButton::get(),
				LoopGrid::get(),
				LoopCarousel::get(),
				Hotspot::get(),
				Popup::get(),
				IconList::get(),
				FormPopup::get(),
				MegaMenu::get(),
				Button::get(),
				Lottie::get(),
				ContainerPopup::get(),
				ImageBox::get(),
			],
			WooProduct::getAll()
		);
	}
}
