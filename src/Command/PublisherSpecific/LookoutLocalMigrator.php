<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\PHP as PHP_Utils;
use \NewspackCustomContentMigrator\Utils\Logger;
use \Newspack_Scraper_Migrator_Util;
use \Newspack_Scraper_Migrator_HTML_Parser;
use \WP_CLI;
use Symfony\Component\DomCrawler\Crawler as Crawler;

/**
 * Custom migration scripts for Lookout Local.
 */
class LookoutLocalMigrator implements InterfaceCommand {

	const MEDIA_CREDIT_META              = '_media_credit';
	const DATA_EXPORT_TABLE              = 'Record';
	const CUSTOM_ENTRIES_TABLE           = 'newspack_entries';
	const LOOKOUT_S3_SCHEMA_AND_HOSTNAME = 'https://lookout-local-brightspot.s3.amazonaws.com';

	/**
	 * Extracted from nav menu:
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/city-life">City Life</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/food-drink">Food &amp; Drink</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/places">Housing</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/civic-life">Civic Life</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/education/higher-ed">Higher Ed</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/education">K-12 Education</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/coast-life">Coast Life</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/wallace-baine">Wallace Baine</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/environment">Environment</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/health-wellness">Health &amp; Wellness</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/business-technology">Business &amp; Technology</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/recreation-sports">Recreation &amp; Sports</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/election-2022">Election 2022 </a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/santa-cruz-county-obituaries">Obituaries</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/partners/civic-groups">Civic Groups</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/partners">Partners</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/lookout-educator-page">For Educators</a>
	 */
	const SECTIONS = [
		'city-life'                    => 'City Life',
		'food-drink'                   => 'Food & Drink',
		'places'                       => 'Housing',
		'civic-life'                   => 'Civic Life',
		'higher-ed'                    => 'Higher Ed',
		'education'                    => 'K-12 Education',
		'coast-life'                   => 'Coast Life',
		'wallace-baine'                => 'Wallace Baine',
		'environment'                  => 'Environment',
		'health-wellness'              => 'Health &amp; Wellness',
		'business-technology'          => 'Business &amp; Technology',
		'recreation-sports'            => 'Recreation &amp; Sports',
		'election-2022'                => 'Election 2022 ',
		'santa-cruz-county-obituaries' => 'Obituaries',
		'civic-groups'                 => 'Civic Groups',
		'partners'                     => 'Partners',
		'lookout-educator-page'        => 'For Educators',
	];

	/**
	 * Fetched from lookout.co manually. Used to populate --urls-file for demo import.
	 */
	const INITIAL_DEMO_URLS = [
		// City Life
		'https://lookout.co/santacruz/wallace-baine/story/2023-08-06/performing-arts-as-old-guard-passes-baton-on-santa-cruz-scene-how-will-new-leaders-weather-generational-shift',
		'https://lookout.co/santacruz/city-life/story/2023-08-03/sleepy-john-sandidge-retirement-kpig-radio-show-please-stand-by',
		'https://lookout.co/santacruz/city-life/story/2023-08-01/sts9-sound-tribe-sector-9-uc-santa-cruz-quarry-amphitheater-after-playing-all-over-country-sts9-comes-back-to-new-home-venue',
		'https://lookout.co/santacruz/civic-life/story/2023-07-31/county-supervisors-housing-plans-mental-health-care-courts-pajaro-river-levee-czu-fire-recovery',
		'https://lookout.co/santacruz/news/story/2023-07-31/santa-cruz-police-black-lives-matter-mural-sc-equity-collab',
		'https://lookout.co/santacruz/wallace-baine/the-here-now/story/2023-07-30/how-lara-love-hardin-survived-addiction-incarceration-and-shame-to-rebuild-her-life',
		'https://lookout.co/santacruz/news/story/2023-07-28/how-ellen-primack-built-cabrillo-festival-internationally-celebrated-showcase-new-music',
		'https://lookout.co/santacruz/food-drink/story/2023-07-28/five-star-dive-bars-max-turigliatto-is-breathing-new-life-into-santa-cruzs-watering-holes',
		'https://lookout.co/santacruz/city-life/story/2023-07-27/wallace-baine-weekender-arts-culture-entertainment-hunchback-cabrillo-stage',
		'https://lookout.co/santacruz/food-drink/story/2023-07-26/santa-cruz-icon-mariannes-ice-cream-opens-new-westside-location',

		// Food & Drink
		'https://lookout.co/santacruz/food-drink/story/2023-08-08/humble-sea-tavern-in-felton-abruptly-closes-its-doors',
		'https://lookout.co/santacruz/food-drink/newsletter/2023-08-01/greater-purpose-brewing-closes-grove-cafe-felton-fire-humble-sea-brewing-alameda-location-otter-841-inspired-beer-pesto-lily-belli-on-food',
		'https://lookout.co/santacruz/food-drink/story/2023-07-31/felton-fire-grove-cafe-to-cause-short-closure',
		'https://lookout.co/santacruz/food-drink/story/2023-07-28/eaters-digest-birichinos-2022-petillant-malvasia-bianca-in-a-can',
		'https://lookout.co/santacruz/food-drink/story/2023-07-28/five-star-dive-bars-max-turigliatto-is-breathing-new-life-into-santa-cruzs-watering-holes',
		'https://lookout.co/santacruz/food-drink/story/2023-07-26/santa-cruz-icon-mariannes-ice-cream-opens-new-westside-location',
		'https://lookout.co/santacruz/food-drink/story/2023-07-25/trestles-pop-up-offers-sneak-peek-of-nick-sherman-upcoming-aptos-restaurant',
		'https://lookout.co/santacruz/food-drink/newsletter/2023-07-25/lily-belli-on-food-poet-patriot-max-turigliatto-tran-vu-vietnamese-pop-up-mariposa-trestles-cavalletta-nick-sherman-lily-belli-on-food',
		'https://lookout.co/santacruz/food-drink/story/2023-07-21/eaters-digest-woodhouse-brewing-collaboration-sts9-wilder-session-ipa-california-craft-beer-week',
		'https://lookout.co/santacruz/food-drink/story/2023-07-21/cabrillo-college-wedding-cakes-class-inside-final-exam',
		// Housing
		'https://lookout.co/santacruz/places/story/2023-08-10/home-sales-santa-cruz-county-real-estate-interest-rates',
		'https://lookout.co/santacruz/business-technology/hiring/story/2023-08-07/how-i-got-my-job-case-manager-andres-galvan-on-confronting-housing-addiction-and-mental-health-crises',
		'https://lookout.co/santacruz/news/story/2023-07-26/santa-cruz-auburn-avenue-ferrari-250gt-lusso-west-cliff-real-estate',
		'https://lookout.co/santacruz/news/story/2023-07-24/whats-in-illegal-drugs-a-ucla-team-takes-testing-to-the-streets-to-find-out',
		'https://lookout.co/santacruz/education/higher-ed/story/2023-07-12/cabrillo-college-ucsc-joint-student-housing-project-in-limbo-after-changes-to-state-budget',
		'https://lookout.co/santacruz/santa-cruz/story/2023-07-10/downtown-santa-cruz-vintage-clothing-shops-retail-renaissance',
		'https://lookout.co/santacruz/news/story/2023-07-07/l-a-hotel-workers-are-back-on-the-job-but-say-more-strikes-are-to-come',
		'https://lookout.co/santacruz/news/story/2023-07-03/a-california-city-was-making-a-difference-on-homelessness-then-the-money-ran-out',
		'https://lookout.co/santacruz/news/story/2023-06-30/visiting-u-s-official-matches-citys-climate-friendly-infrastructure-progress-with-message-of-plenty',
		'https://lookout.co/santacruz/education/story/2023-06-29/live-oak-school-district-extends-meals-on-wheels-senior-network-services-eviction-to-aug-30',
		// Civic Life
		'https://lookout.co/santacruz/ucsc-cabrillo/story/2023-08-10/native-american-indigenous-madelyne-broome-uc-santa-cruz-astrophysics-grad-student-didnt-feel-native-enough-until-ucsc',
		'https://lookout.co/santacruz/civic-life/story/2023-08-09/california-prescription-could-pay-for-your-fresh-fruits-and-veggies',
		'https://lookout.co/santacruz/partners/marketing/story/2023-08-01/cabrillo-festival-of-contemporary-music-announces-61st-season',
		'https://lookout.co/santacruz/civic-life/story/2023-08-09/michael-cheek-convicted-rapist-civil-rights-transient-release-state-santa-cruz-county-continue-search',
		'https://lookout.co/santacruz/news/story/2023-08-09/capitola-village-lumen-gallery-pride-flags-stolen-hate-crime-capitola-police',
		'https://lookout.co/santacruz/partners/marketing/story/2023-07-06/federal-grant-funds-santa-cruz-county-wic-outreach-to-immigrants-and-farmworkers',
		'https://lookout.co/santacruz/education/higher-ed/story/2023-08-08/university-of-california-admits-record-number-of-california-first-year-students-for-fall-2023-uc-santa-cruz-acceptance-up-45-percent',
		'https://lookout.co/santacruz/civic-life/story/2023-08-08/california-has-made-voting-much-easier-but-regular-voters-still-skew-white-and-old-poll-finds',
		'https://lookout.co/santacruz/civic-life/story/2023-08-08/michael-cheek-convicted-rapist-transient-release-santa-cruz-county-bonny-doon-syda-cogliati#nt=00000175-a0bb-ddb0-a57f-e7ff35ec0000-liF0promoSmall-7030col1',
		'https://lookout.co/santacruz/education/higher-ed/cabrillo-college/story/2023-08-08/cabrillo-college-governing-board-delays-name-change-until-november-amid-deep-divisions',
		// Higher Ed
		'https://lookout.co/santacruz/education/higher-ed/cabrillo-college/story/2023-08-08/cabrillo-college-governing-board-delays-name-change-until-november-amid-deep-divisions',
		'https://lookout.co/santacruz/partners/marketing/story/2023-07-07/for-the-second-consecutive-year-cabrillo-college-robotics-club-wins-first-place-in-world-competition',
		'https://lookout.co/santacruz/education/higher-ed/cabrillo-college/story/2023-08-07/cabrillo-college-name-change-what-to-know-about-governing-board-monday-meeting',
		'https://lookout.co/santacruz/community-voices/opinion-from-community-voices/story/2023-08-06/santa-cruz-county-asian-americans-support-cabrillo-college-name-change-lets-empower-the-next-generation',
		'https://lookout.co/santacruz/education/higher-ed/story/2023-08-04/csu-likely-to-miss-2025-graduation-goals-with-unacceptably-high-equity-gaps-report-says',
		'https://lookout.co/santacruz/education/higher-ed/story/2023-08-03/cabrillo-college-governing-board-set-to-vote-monday-on-delaying-name-change',
		'https://lookout.co/santacruz/education/higher-ed/cabrillo-college/story/2023-08-03/cabrillo-college-renaming-vote-supporters-press-for-change-monument-to-racism-in-santa-cruz-county',
		'https://lookout.co/santacruz/education/higher-ed/cabrillo-college/story/2023-08-02/cabrillo-college-rising-scholars-program-gives-formerly-incarcerated-students-a-pathway-to-higher-education',
		'https://lookout.co/santacruz/city-life/story/2023-08-01/sts9-sound-tribe-sector-9-uc-santa-cruz-quarry-amphitheater-after-playing-all-over-country-sts9-comes-back-to-new-home-venue',
		'https://lookout.co/santacruz/news/story/2023-07-28/how-ellen-primack-built-cabrillo-festival-internationally-celebrated-showcase-new-music',
		// K-12 Education
		'https://lookout.co/santacruz/education/story/2023-08-09/capitola-police-give-all-clear-after-new-brighton-middle-school-receives-bomb-threat',
		'https://lookout.co/santacruz/education/story/2023-07-19/santa-cruz-gateway-school-fourth-grade-inventor-wins-first-place-in-national-competition-with-backpack-designed-for-forgetful-students',
		'https://lookout.co/santacruz/education/story/2023-07-18/california-transitional-kindergarten-day-care-competing-for-4-year-olds',
		'https://lookout.co/santacruz/education/story/2023-07-13/pajaro-valley-unified-appoints-former-teacher-and-administrator-murry-schekman-interim-superintendent',
		'https://lookout.co/santacruz/education/story/2023-07-12/california-math-overhaul-aims-to-help-struggling-students-but-will-it-hurt-whiz-kids#nt=00000175-b083-de97-ab7d-f2d7351c0000-liF0promoSmall-7030col1',
		'https://lookout.co/santacruz/education/story/2023-06-29/live-oak-school-district-extends-meals-on-wheels-senior-network-services-eviction-to-aug-30',
		'https://lookout.co/santacruz/education/story/2023-06-22/california-doubles-down-on-inclusive-education-as-red-states-ban-books-in-classrooms',
		'https://lookout.co/santacruz/education/story/2023-06-22/reyna-maharaj-st-francis-high-school-watsonville-graduate-foster-care-system-uc-san-diego-cybersecurity',
		'https://lookout.co/santacruz/education/story/2023-06-02/pvusd-superintendent-michelle-rodriguez-leaving-position-to-head-stockton-unified-school-district',
		'https://lookout.co/santacruz/food-drink/story/2023-05-30/summer-food-wine-gardening-courses',
		// Coast Life
		'https://lookout.co/santacruz/wallace-baine/story/2023-08-06/performing-arts-as-old-guard-passes-baton-on-santa-cruz-scene-how-will-new-leaders-weather-generational-shift',
		'https://lookout.co/santacruz/city-life/story/2023-08-03/santa-cruz-shakespeare-book-of-will-lauren-gunderson',
		'https://lookout.co/santacruz/partners/marketing/story/2023-07-07/for-the-second-consecutive-year-cabrillo-college-robotics-club-wins-first-place-in-world-competition',
		'https://lookout.co/santacruz/city-life/story/2023-08-03/sleepy-john-sandidge-retirement-kpig-radio-show-please-stand-by',
		'https://lookout.co/santacruz/environment/story/2023-08-01/global-warming-bigger-waves-off-california-coast-scientists-say',
		'https://lookout.co/santacruz/city-life/story/2022-08-22/staying-safe-on-a-budget-how-to-protect-your-home-from-california-wildfires',
		'https://lookout.co/santacruz/weather/story/2023-07-29/california-has-new-weapons-to-battle-summer-blackouts-battery-storage-power-from-record-rain',
		'https://lookout.co/santacruz/food-drink/story/2023-07-28/eaters-digest-birichinos-2022-petillant-malvasia-bianca-in-a-can',
		'https://lookout.co/santacruz/coast-life/story/2023-07-28/human-behavior-otter-841-santa-cruz-steamer-lane-fish-and-wildlife',
		'https://lookout.co/santacruz/city-life/story/2023-07-27/wallace-baine-weekender-arts-culture-entertainment-hunchback-cabrillo-stage',
		// Wallace Baine
		'https://lookout.co/santacruz/wallace-baine/story/2023-08-06/performing-arts-as-old-guard-passes-baton-on-santa-cruz-scene-how-will-new-leaders-weather-generational-shift',
		'https://lookout.co/santacruz/city-life/story/2023-08-03/sleepy-john-sandidge-retirement-kpig-radio-show-please-stand-by',
		'https://lookout.co/santacruz/city-life/story/2023-08-01/sts9-sound-tribe-sector-9-uc-santa-cruz-quarry-amphitheater-after-playing-all-over-country-sts9-comes-back-to-new-home-venue',
		'https://lookout.co/santacruz/wallace-baine/the-here-now/story/2023-07-30/how-lara-love-hardin-survived-addiction-incarceration-and-shame-to-rebuild-her-life',
		'https://lookout.co/santacruz/news/story/2023-07-28/how-ellen-primack-built-cabrillo-festival-internationally-celebrated-showcase-new-music',
		'https://lookout.co/santacruz/wallace-baine/newsletter/2023-07-27/weekender-hunchback-free-cabrillo-festival-rehearsals-book-of-will-playwright-visit-more-best-bets-osmosys-app-work-around',
		'https://lookout.co/santacruz/city-life/story/2023-07-27/wallace-baine-weekender-arts-culture-entertainment-hunchback-cabrillo-stage',
		'https://lookout.co/santacruz/wallace-baine/the-here-now/story/2023-07-25/a-brave-new-world-sandy-skees-shares-how-businesses-can-succeed-with-a-new-generation-of-consumers',
		'https://lookout.co/santacruz/city-life/story/2023-07-23/barbie-movie-many-perspectives-on-barbie-an-older-guy-and-two-teens-discuss-pop-culture-phenomenon',
		'https://lookout.co/santacruz/civic-life/story/2023-07-21/rfk-robert-f-kennedy-jr-presidential-run-will-santa-cruz-county-be-open-to-what-rfk-jr-is-selling',
		// Environment
		'https://lookout.co/santacruz/environment/wildfires/story/2023-08-02/york-fire-as-joshua-trees-burn-massive-wildfire-threatens-to-forever-alter-mojave-desert',
		'https://lookout.co/santacruz/weather/story/2023-07-29/california-has-new-weapons-to-battle-summer-blackouts-battery-storage-power-from-record-rain',
		'https://lookout.co/santacruz/coast-life/story/2023-07-28/human-behavior-otter-841-santa-cruz-steamer-lane-fish-and-wildlife',
		'https://lookout.co/santacruz/news/story/2023-07-27/swiss-cheese-no-please-a-rodent-hole-debacle-rocks-the-san-lorenzo-river-levee',
		'https://lookout.co/santacruz/news/story/2023-07-27/swiss-cheese-no-please-a-rodent-hole-debacle-rocks-the-san-lorenzo-river-levee',
		'https://lookout.co/santacruz/environment/story/2023-07-20/alifornia-electric-cars-feed-grid-help-avoid-brownouts',
		'https://lookout.co/santacruz/coast-life/story/2023-07-20/otter-841-fish-wildlife-randall-davis-captivity-monterey-bay-steamer-lane-cowell-beach',
		'https://lookout.co/santacruz/news/story/2023-07-19/california-will-cap-hundreds-of-orphaned-oil-wells-some-long-suspected-of-causing-illness',
		'https://lookout.co/santacruz/environment/climate/story/2023-07-19/death-valleys-extreme-heat-goes-off-the-charts-from-climate-change',
		'https://lookout.co/santacruz/coast-life/story/2023-07-18/otter-841-santa-cruz-fish-wildlife-cowell-beach-steamer-lane',
		// Health & Wellness
		'https://lookout.co/santacruz/health-wellness/story/2023-08-10/new-coronavirus-subvariant-eris-is-gaining-dominance-is-it-fueling-an-increase-in-cases',
		'https://lookout.co/santacruz/civic-life/story/2023-08-07/as-speedy-hefty-e-bikes-become-ubiquitous-around-santa-cruz-can-regulation-be-far-behind',
		'https://lookout.co/santacruz/business-technology/hiring/story/2023-08-07/how-i-got-my-job-case-manager-andres-galvan-on-confronting-housing-addiction-and-mental-health-crises',
		'https://lookout.co/santacruz/health-wellness/story/2023-08-02/valley-fever-could-hit-california-hard-the-drought-to-downpour-cycle-is-to-blame',
		'https://lookout.co/santacruz/health-wellness/story/2023-07-31/summer-covid-surge-santa-cruz-county-boosters-paxlovid',
		'https://lookout.co/santacruz/wallace-baine/the-here-now/story/2023-07-30/how-lara-love-hardin-survived-addiction-incarceration-and-shame-to-rebuild-her-life',
		'https://lookout.co/santacruz/health-wellness/story/2023-07-27/watsonville-community-hospitals-board-takes-another-step-toward-pursuing-bond-measure',
		'https://lookout.co/santacruz/community-voices/opinion-from-community-voices/story/2023-07-25/i-think-my-mother-in-law-has-discovered-the-fountain-of-youth',
		'https://lookout.co/santacruz/civic-life/story/2023-07-24/aging-population-as-santa-cruz-county-grays-impending-silver-tsunami-has-service-providers-worried',
		'https://lookout.co/santacruz/civic-life/story/2023-07-21/rfk-robert-f-kennedy-jr-presidential-run-will-santa-cruz-county-be-open-to-what-rfk-jr-is-selling',
		// Business & Technology
		'https://lookout.co/santacruz/food-drink/story/2023-08-08/humble-sea-tavern-in-felton-abruptly-closes-its-doors',
		'https://lookout.co/santacruz/coast-life/story/2023-08-04/santa-cruz-beach-boardwalk-planning-commission-ferris-wheel-chance-rides-seaside-company',
		'https://lookout.co/santacruz/food-drink/story/2023-07-31/felton-fire-grove-cafe-to-cause-short-closure',
		'https://lookout.co/santacruz/weather/story/2023-07-29/california-has-new-weapons-to-battle-summer-blackouts-battery-storage-power-from-record-rain',
		'https://lookout.co/santacruz/food-drink/story/2023-07-28/five-star-dive-bars-max-turigliatto-is-breathing-new-life-into-santa-cruzs-watering-holes',
		'https://lookout.co/santacruz/news/story/2023-07-26/santa-cruz-auburn-avenue-ferrari-250gt-lusso-west-cliff-real-estate',
		'https://lookout.co/santacruz/education/story/2023-07-25/santa-cruz-county-public-school-staffing-woes-ease-ahead-of-start-of-the-academic-year',
		'https://lookout.co/santacruz/wallace-baine/the-here-now/story/2023-07-25/a-brave-new-world-sandy-skees-shares-how-businesses-can-succeed-with-a-new-generation-of-consumers',
		'https://lookout.co/santacruz/food-drink/story/2023-07-21/cabrillo-college-wedding-cakes-class-inside-final-exam',
		'https://lookout.co/santacruz/environment/story/2023-07-20/alifornia-electric-cars-feed-grid-help-avoid-brownouts',
		// Recreation & Sports
		'https://lookout.co/santacruz/coast-life/story/2023-07-28/human-behavior-otter-841-santa-cruz-steamer-lane-fish-and-wildlife',
		'https://lookout.co/santacruz/news/story/2023-07-07/l-a-hotel-workers-are-back-on-the-job-but-say-more-strikes-are-to-come',
		'https://lookout.co/santacruz/news/story/2023-06-01/steve-garvey-senate-former-los-angeles-dodger-weighs-u-s-senate-bid',
		'https://lookout.co/santacruz/civic-life/story/2023-05-30/cotoni-coast-dairies-national-monument-parking-battle-leaves-santa-cruz-countys-lone-national-monument-gated-from-public',
		'https://lookout.co/santacruz/recreation-sports/story/2023-05-26/german-plaza-santa-cruz-youth-soccer-club-sharks-07-team-western-regionals',
		'https://lookout.co/santacruz/news/story/2023-05-19/jim-brown-football-great-actor-civil-rights-activist-dies',
		'https://lookout.co/santacruz/coast-life/story/2023-05-19/pescadero-day-trip-sea-lions-ano-nuevo-award-winning-tavern-baby-goats',
		'https://lookout.co/santacruz/recreation-sports/story/2023-05-17/scotts-valley-high-school-signing-day-siena-wong-ellie-raffo-sam-freeman-amber-boothby-elana-mcgrew',
		'https://lookout.co/santacruz/recreation-sports/story/2023-04-20/oakland-as-las-vegas-athletics-plan-to-buy-land-for-new-stadium-move-2027',
		'https://lookout.co/santacruz/recreation-sports/story/2023-04-14/whitewater-rafting-california-epic-snowpack-promises-season-for-the-ages',
		// Election 2022
		'https://lookout.co/santacruz/election-2022/story/2022-11-11/santa-cruz-election-2022-latest-results',
		'https://lookout.co/santacruz/election-2022/local-elections-section/story/2022-11-08/soquel-creek-water-district-board-carla-christensen-bruce-jaffe-corrie-kates-kris-kirby-rachel-lather',
		'https://lookout.co/santacruz/election-2022/story/2022-11-08/santa-cruz-mayors-race-fred-keeley-joy-schendledecker',
		'https://lookout.co/santacruz/election-2022/story/2022-11-07/santa-cruz-county-election-2022-weekly-update-november-7',
		'https://lookout.co/santacruz/election-2022/story/2022-11-07/santa-cruz-county-election-2022-weekly-update-november-7',
		'https://lookout.co/santacruz/community-voices/opinion-from-community-voices/story/2022-11-04/downtown-businesspeople-oppose-measure-o-with-unanimous-vote-of-downtown-association-board',
		'https://lookout.co/santacruz/education/story/2022-11-03/soquel-union-elementary-school-district-candidates-on-teacher-pay-mental-health-declining-enrollment-and-pandemic-learning-loss',
		'https://lookout.co/santacruz/education/story/2022-11-02/pajaro-valley-unified-school-district-candidates-diversity-pandemic-learning-loss-teacher-housing',
		'https://lookout.co/santacruz/election-2022/local-elections-section/story/2022-11-02/affordable-housing-downtown-santa-cruz-ballot-measure-o',
		'https://lookout.co/santacruz/community-voices/opinion-from-community-voices/story/2022-11-01/watsonville-city-council-district-7-race-only-challenger-nancy-bilicich-responds-to-lookout-questions-ari-parker-no-response',
	];

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments Instance.
	 */
	private $attachments;

	/**
	 * Logger instance.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * DomCrawler instance.
	 *
	 * @var Crawler Crawler instance.
	 */
	private $crawler;

	/**
	 * Current working directory.
	 *
	 * @var false|string Current working directory.
	 */
	private $temp_dir;

	/**
	 * Scraper instance.
	 *
	 * @var Newspack_Scraper_Migrator_Util Instance.
	 */
	private $scraper;

	/**
	 * Parser instance.
	 *
	 * @var Newspack_Scraper_Migrator_HTML_Parser Instance.
	 */
	private $data_parser;

	/**
	 * CoAuthorPlus instance.
	 *
	 * @var CoAuthorPlus Instance.
	 */
	private $cap;

	/**
	 * Posts instance.
	 *
	 * @var Posts Posts instance.
	 */
	private $posts;

	/**
	 * Gutenberg block generator.
	 *
	 * @var GutenbergBlockGenerator Gutenberg block generator.
	 */
	private $gutenberg;

	/**
	 * Constructor.
	 */
	private function __construct() {

		// If on Atomic.
		if ( '/srv/htdocs/__wp__/' == ABSPATH ) {
			$public_path    = '/srv/htdocs';
			$this->temp_dir = '/tmp/scraper_data';
			$plugin_dir     = $public_path . '/wp-content/plugins/newspack-custom-content-migrator';
		} else {
			$public_path    = rtrim( ABSPATH, '/' );
			$this->temp_dir = $public_path . '/scraper_data';
			$plugin_dir     = $public_path . '/wp-content/plugins/newspack-custom-content-migrator';
		}

		// Newspack_Scraper_Migrator is not autoloaded.
		require realpath( $plugin_dir . '/vendor/automattic/newspack-cms-importers/newspack-scraper-migrator/includes/class-newspack-scraper-migrator-util.php' );
		require realpath( $plugin_dir . '/vendor/automattic/newspack-cms-importers/newspack-scraper-migrator/includes/class-newspack-scraper-migrator-html-parser.php' );

		$this->attachments = new Attachments();
		$this->logger      = new Logger();
		$this->scraper     = new Newspack_Scraper_Migrator_Util();
		$this->crawler     = new Crawler();
		$this->data_parser = new Newspack_Scraper_Migrator_HTML_Parser();
		$this->cap         = new CoAuthorPlus();
		$this->posts       = new Posts();
		$this->gutenberg   = new GutenbergBlockGenerator();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-create-custom-table',
			[ $this, 'cmd_create_custom_table' ],
			[
				'shortdesc' => 'Extracts all posts JSONs from the huge `Record` table into a new custom table called self::CUSTOM_ENTRIES_TABLE.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-scrape-posts',
			[ $this, 'cmd_scrape_posts' ],
			[
				'shortdesc' => 'Main command. Scrape posts from live and imports them. Make sure to run lookoutlocal-create-custom-table first.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'urls-file',
						'description' => 'File with URLs to scrape and import, one URL per line.',
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-postscrape-posts',
			[ $this, 'cmd_postscrape_posts' ],
			[
				'shortdesc' => 'Second main command. Run this one after `lookoutlocal-scrape-posts` to clean up imported content, and also expand it (like update GA avatars, bios, etc).',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-get-urls-from-record-table',
			[ $this, 'cmd_get_urls_from_record_table' ],
			[
				'shortdesc' => 'This tries to extract live post URLs from Record and custom Newspack table. Make sure to run lookoutlocal-create-custom-table first.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'urls-csv',
						'description' => 'List of post URLs to scrape and import.',
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-deprecated-import-posts-programmatically',
			[ $this, 'cmd_deprecated_import_posts' ],
			[
				'shortdesc' => 'Tried to see if we can programmatically get all relational data from `Record` table. But the answer is no -- it is simply too dificult, better to scrape. (old description: Imports posts from JSONs in  self::CUSTOM_ENTRIES_TABLE.)',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-dev',
			[ $this, 'cmd_dev' ],
			[
				'shortdesc' => 'Temp dev command for various snippets.',
				'synopsis'  => [],
			]
		);
	}

	public function cmd_get_urls_from_record_table( $pos_args, $assoc_args ) {
		global $wpdb;

		// Log files.
		$log_urls           = $this->temp_dir . '/ll__get_urls_from_db.log';
		$log_urls_not_found = $this->temp_dir . '/ll_debug__urls_not_found.log';

		// Hit timestamp on logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_urls, $ts, false );
		$this->logger->log( $log_urls_not_found, $ts, false );


		// Create folders for caching stuff.
		// Cache section (category) data to files (because SQLs on `Result` table are super slow).
		$section_data_cache_path = $this->temp_dir . '/cache_sections';
		if ( ! file_exists( $section_data_cache_path ) ) {
			mkdir( $section_data_cache_path, 0777, true );
		}

		/**
		 * Loop through all the rows from Newspack custom table and get their URLs.
		 * URLs are hard to find, since we must crawl their DB export and search through relational data, and all queries are super slow since it's one 6 GB table.
		 */

		// Get rows from our custom posts table (table was created by command lookoutlocal-create-custom-table).
		$entries_table       = self::CUSTOM_ENTRIES_TABLE;
		$newspack_table_rows = $wpdb->get_results( "select slug, data from {$entries_table}", ARRAY_A );

		// QA and debugging vars.
		$urls           = [];
		$urls_not_found = [];

		/**
		 * @var array $posts_urls All pposts URL data is stored in this array. {
		 *      @type string slug Post slug.
		 *      @type string url  Post url.
		 * }
		 */
		$posts_urls = [];
		foreach ( $newspack_table_rows as $key_row => $newspack_table_row ) {

			$row_data = json_decode( $newspack_table_row['data'], true );
			$slug     = $newspack_table_row['slug'];

			WP_CLI::line( sprintf( '%d/%d Getting URL for slug %s ...', $key_row + 1, count( $newspack_table_rows ), $slug ) );

			// Get post URL.
			$url_data = $this->get_post_url( $newspack_table_row, $section_data_cache_path );
			$url      = $url_data['url'] ?? null;
			if ( ! $url ) {
				$this->logger->log( $log_urls_not_found, sprintf( 'Not found URL for slug %s', $newspack_table_row['slug'] ), $this->logger::WARNING );
				$urls_not_found[] = $slug;
				continue;
			}

			$this->logger->log( $log_urls, $url, false );
			$urls[] = $url;
		}

		if ( ! empty( $urls_not_found ) ) {
			WP_CLI::warning( "❗️ Some URLs not found, see $log_urls_not_found" );
		}
		if ( ! empty( $urls ) ) {
			WP_CLI::warning( "👍 URLs saved to $log_urls" );
		}
	}

	public function cmd_postscrape_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		// Log files.
		$log_post_ids_updated   = $this->temp_dir . '/ll_updated_post_ids.log';
		$log_gas_urls_updated   = $this->temp_dir . '/ll_gas_urls_updated.log';
		$log_err_gas_updated    = $this->temp_dir . '/ll_err__updated_gas.log';
		$log_enhancements       = $this->temp_dir . '/ll_qa__enhancements.log';
		$log_need_oembed_resave = $this->temp_dir . '/ll__need_oembed_resave.log';

		// Create folders for caching stuff.
		// Cache scraped HTMLs (in case we need to repeat scraping/identifying data from HTMLs).
		$scraped_htmls_cache_path = $this->temp_dir . '/scraped_htmls';
		if ( ! file_exists( $scraped_htmls_cache_path ) ) {
			mkdir( $scraped_htmls_cache_path, 0777, true );
		}

		// Create folders for caching stuff.
		// Cache scraped HTMLs (in case we need to repeat scraping/identifying data from HTMLs).
		$scraped_htmls_cache_path = $this->temp_dir . '/scraped_htmls';
		if ( ! file_exists( $scraped_htmls_cache_path ) ) {
			mkdir( $scraped_htmls_cache_path, 0777, true );
		}


		// Hit timestamp on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_post_ids_updated, $ts, false );
		$this->logger->log( $log_gas_urls_updated, $ts, false );
		$this->logger->log( $log_err_gas_updated, $ts, false );
		$this->logger->log( $log_enhancements, $ts, false );
		$this->logger->log( $log_need_oembed_resave, $ts, false );

		// Get post IDs.
		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish' ] );

		/**
		 * Clean up post_content, remove inserted promo or user engagement content.
		 */
		WP_CLI::line( 'Cleaning up post_content ...' );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '%d/%d ID %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			$post_content = $wpdb->get_var( $wpdb->prepare( "select post_content from {$wpdb->posts} where ID = %d", $post_id ) );

			$post_content_updated = $this->clean_up_scraped_html( $post_id, $post_content, $log_need_oembed_resave );
			// If post_content was updated.
			if ( ! empty( $post_content_updated ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] );
				$this->logger->log( $log_post_ids_updated, sprintf( 'Updated %d', $post_id ), $this->logger::SUCCESS );
			}

			// QA remaining 'div.enhancement's.
			$this->qa_remaining_div_enhancements( $log_enhancements, $post_id, ! empty( $post_content_updated ) ? $post_content_updated : $post_content );
		}


		/**
		 * Next update GA info by scraping and fetching their author pages from live.
		 */
		WP_CLI::line( 'Updating GA author data ...' );

		// First get all author pages URLs which were originally stored as Posts' postmeta.
		$author_pages_urls = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::line( sprintf( "%d/%d %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$links_meta = get_post_meta( $post_id, 'newspackmigration_author_links' );
			if ( empty( $links_meta ) ) {
				continue;
			}

			// Flatten these multidimensional meta and add them to $author_pages_links as unique values.
			foreach ( $links_meta as $urls ) {
				foreach ( $urls as $url ) {
					if ( in_array( $url, $author_pages_urls ) ) {
						continue;
					}

					$author_pages_urls[] = $url;
				}
			}
		}

		// Now actually scrape individual author pages and update GAs with that data.
		foreach ( $author_pages_urls as $author_page_url ) {
			$errs_updating_gas = $this->update_author_info( $author_page_url, $scraped_htmls_cache_path, $log_err_gas_updated );
			if ( empty( $errs_updating_gas ) ) {
				$this->logger->log( $log_gas_urls_updated, $author_page_url, false );
			} else {
				$this->logger->log( $log_err_gas_updated, implode( "\n", $errs_updating_gas ), false );
			}
		}


		WP_CLI::line(
			'Done. QA the following logs:'
			. "\n  - ❗  ERRORS: $log_err_gas_updated"
			. "\n  - ♻️️  $log_need_oembed_resave"
			. "\n  - ⚠️  $log_enhancements"
			. "\n  - 👍  $log_post_ids_updated"
			. "\n  - 👍  $log_gas_urls_updated"
		);
		wp_cache_flush();
	}

	/**
	 * @param $url
	 * @param $scraped_htmls_cache_path
	 *
	 * @return array Error messages if they occurred during GA info update.
	 */
	public function update_author_info( $url, $scraped_htmls_cache_path ) {

		$errs_updating_gas = [];

		// HTML cache filename and path.
		$html_cached_filename  = $this->sanitize_filename( $url ) . '.html';
		$html_cached_file_path = $scraped_htmls_cache_path . '/' . $html_cached_filename;

		// Get author page from cache if exists.
		$html = file_exists( $html_cached_file_path ) ? file_get_contents( $html_cached_file_path ) : null;
		if ( is_null( $html ) ) {

			// Remote get author page from live.
			$get_result = $this->wp_remote_get_with_retry( $url );
			if ( is_wp_error( $get_result ) || is_array( $get_result ) ) {
				// Not OK.
				$msg = is_wp_error( $get_result ) ? $get_result->get_error_message() : $get_result['response']['message'];
				$errs_updating_gas[] = sprintf( 'URL:%s CODE:%s MESSAGE:%s', $url, $get_result['response']['code'], $msg );
				return;
			}

			$html = $get_result;

			// Cache HTML to file.
			file_put_contents( $html_cached_file_path, $html );
		}

		// Crawl and extract all useful data from author page HTML.
		$crawled_data = $this->crawl_author_data_from_html( $html, $url );

		// Get or create GA.
		$ga = $this->cap->get_guest_author_by_display_name( $crawled_data['name'] );
		if ( ! $ga ) {
			$ga = $this->cap->create_guest_author( [ 'display_name' => $crawled_data['name'] ] );
		}

		// GA data to update.
		$ga_update_arr = [];

		// Name is being referenced, so that stays the same.

		// Avatar -- only import and update if not already set, because we'd be importing dupes to the Media Library.
		$ga_avatar_att_id = get_post_meta( $ga->ID, '_thumbnail_id', true );
		if ( ! $ga_avatar_att_id && $crawled_data['avatar_url'] ) {
			WP_CLI::line( sprintf( "Downloading avatar URL for author '%s' ...", $crawled_data['name'] ) );
			$attachment_id = $this->attachments->import_external_file( $crawled_data['avatar_url'], $crawled_data['name'] );
			if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
				$errs_updating_gas[] = sprintf( "Error importing avatar image %s for author '%s' ERR: %s", $crawled_data['avatar_url'], $crawled_data['name'], is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : '/na' );
			} else {
				$ga_update_arr['avatar'] = $attachment_id;
			}
		}

		// Compose social links sentence.
		$social_blank = 'Follow ' . $crawled_data['name'] . ' on: ';
		$social       = $social_blank;
		$link_fn      = function( $href, $text ) {
			return sprintf( '<a href="%s" target="_blank" rel="noreferrer">%s</a>', $href, $text );
		};
		if ( isset( $crawled_data['social_twitter'] ) && ! empty( $crawled_data['social_twitter'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_twitter'], 'Twitter' );
		}
		if ( isset( $crawled_data['social_instagram'] ) && ! empty( $crawled_data['social_instagram'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_instagram'], 'Instagram' );
		}
		if ( isset( $crawled_data['social_facebook'] ) && ! empty( $crawled_data['social_facebook'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_facebook'], 'Facebook' );
		}
		if ( isset( $crawled_data['social_linkedin'] ) && ! empty( $crawled_data['social_linkedin'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_linkedin'], 'LinkedIn' );
		}

		// Bio = $social . $bio.
		$ga_update_arr['description'] = '';
		if ( $social_blank != $social ) {
			$ga_update_arr['description'] .= $social;
		}
		if ( $crawled_data['bio'] ) {
			$ga_update_arr['description'] .= ! empty( $ga_update_arr['description'] ) ? '. ' : '';
			$ga_update_arr['description'] .= $crawled_data['bio'];
		}

		// Email.
		if ( isset( $crawled_data['social_email'] ) && ! empty( $crawled_data['social_email'] ) ) {
			$ga_update_arr['user_email'] = $crawled_data['social_email'];
		}

		// Title.
		if ( $crawled_data['title'] ) {
			$ga_update_arr['job_title'] = $crawled_data['title'];
		}

		// Update the GA.
		$this->cap->update_guest_author( $ga->ID, $ga_update_arr );
		WP_CLI::success(
			sprintf(
				'Updated GA %s from %s',
				sprintf(
					'https://%s/wp-admin/post.php?post=%d&action=edit',
					wp_parse_url( get_site_url() )['host'],
					$ga->ID,
				),
				$url
			)
		);

		return $errs_updating_gas;
	}

	/**
	 * Crawls all useful post data from HTML.
	 *
	 * @param string $html                    HTML.
	 * @param array  &$debug_all_author_names Stores all author names for easier QA/debugging.
	 * @param array  &$debug_all_tags         Stores all tags for easier QA/debugging.
	 *
	 * @return array $data All posts data crawled from HTML. {
	 *      @type array   script_data            Decoded data from that one <script> element with useful post info.
	 *      @type string  post_title
	 *      @type ?string presented_by
	 * }
	 */
	public function crawl_author_data_from_html( $html, $url ) {

		$data = [];

		/**
		 * Get all post data.
		 */
		$this->crawler->clear();
		$this->crawler->add( $html );

		// Name
		$data['name'] = trim( $this->filter_selector( 'div.page-bio > h1.page-bio-author-name', $this->crawler ) );

		// Avatar image.
		$avatar_crawler     = $this->filter_selector_element( 'div.page-intro-avatar > img', $this->crawler, $single = true );
		$data['avatar_url'] = $avatar_crawler ? $avatar_crawler->getAttribute( 'src' ) : null;

		// Title, e.g. Politics and Policy Correspondent.
		$data['title'] = $this->filter_selector( 'div.page-bio > p.page-bio-author-title', $this->crawler );

		// Bio.
		$data['bio'] = $this->filter_selector( 'div.page-bio > div.page-bio-author-bio', $this->crawler );

		// Social links. Located in ul.social-bar-menu > li > a > href.
		$ul_crawler = $this->filter_selector_element( 'ul.social-bar-menu', $this->crawler, $single = true );
		// Also get entire ul.social-bar-menu HTML.
		$social_list_html               = $ul_crawler->ownerDocument->saveHTML( $ul_crawler );
		$data['social_links_full_html'] = $social_list_html ?? null;
		// <ul>
		if ( $ul_crawler ) {
			// <li>s
			$lis = $ul_crawler->getElementsByTagName( 'li' );
			foreach ( $lis as $li ) {
				// Get the first <a>.
				$as = $li->getElementsByTagName( 'a' );
				if ( $as && $as->count() > 0 ) {
					$a                   = $as[0];
					$a_html              = $a->ownerDocument->saveHTML( $a );
					$social_service_type = $a->getAttribute( 'data-social-service' );
					switch ( $social_service_type ) {
						case 'email':
							$data['social_email'] = str_replace( 'mailto:', '', $a->getAttribute( 'href' ) );
							break;
						case 'linkedin':
							// Oddly the href might have wrong value, e.g. "https://www.linkedin.com/in/https://www.linkedin.com/in/blaire-hobbs-2b278b1a0/".
							$href = $a->getAttribute( 'href' );
							// Get the last https:// occurrence in $href.
							$last_https_pos          = strrpos( $href, 'https://' );
							$href_cleaned            = substr( $href, $last_https_pos );
							$data['social_linkedin'] = $href_cleaned;
							break;
						case 'twitter':
							$href                   = $a->getAttribute( 'href' );
							$data['social_twitter'] = $href;
							break;
						case 'instagram':
							$href                     = $a->getAttribute( 'href' );
							$data['social_instagram'] = $href;
							break;
						case 'facebook':
							$href                    = $a->getAttribute( 'href' );
							$data['social_facebook'] = $href;
							break;
						default:
							throw new \UnexpectedValueException( sprintf( "A new type of social link type '%s' used on author page %s. Please update the migrator's crawl_author_data_from_html() method and add support for it.", $social_service_type, $url ) );
							break;
					}
				}
			}
		}

		return $data;
	}

	/**
	 * @param string $post_content HTML.
	 *
	 * @return string|null Cleaned HTML or null if this shouldn't be cleaned.
	 */
	public function clean_up_scraped_html( $post_id, $post_content, $log_need_oembed_resave ) {

		$post_content_updated = '';

		$this->crawler->clear();
		$this->crawler->add( $post_content );

		/**
		 * Get the outer content div.rich-text-body in which the body HTML is nested.
		 */
		$div_content_crawler = $this->filter_selector_element( 'div.rich-text-body', $this->crawler );
		/**
		 * If div.rich-text-body was already removed, just temporarily surround the HTML with a new <div> so that nodes can be traversed the same way as children.
		 */
		if ( ! $div_content_crawler ) {
			$this->crawler->clear();
			$this->crawler->add( '<div>' . $post_content . '</div>' );
			$div_content_crawler = $this->filter_selector_element( 'div', $this->crawler );
		}

		/**
		 * OK, now traverse through all child nodes. We will just keept the content inside the <div>, getting rid of the <div> itself.
		 */
		foreach ( $div_content_crawler->childNodes->getIterator() as $key_domelement => $domelement ) {

			$custom_html = null;

			/**
			 * Skip specific "div.enhancement"s.
			 */
			$is_div_class_enhancement = ( isset( $domelement->tagName ) && 'div' == $domelement->tagName ) && ( 'enhancement' == $domelement->getAttribute( 'class' ) );
			if ( $is_div_class_enhancement ) {

				$enhancement_crawler = new Crawler( $domelement );

				/**
				 * Keep adding banned divs which will be skipped from post_content.
				 */
				if ( $enhancement_crawler->filter( 'div > div#newsletter_signup' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'div > div > script[src="https://cdn.broadstreetads.com/init-2.min.js"]' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'figure > a > img[alt="Student signup banner"]' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'ps-promo' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'broadstreet-zone' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'div.promo-action' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'figure > a > img[alt="BFCU Home Loans Ad"]' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'figure > a > img[alt="Community Voices election 2022"]' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'figure > a > img[alt="click here to become a Lookout member"]' )->count() ) {
					continue;
				}
				/**
				 * Keep adding banned divs which will be skipped from post_content.
				 */


				/**
				 * YT player to Gutenberg YT block.
				 */
				if ( $enhancement_crawler->filter( 'div > div > ps-youtubeplayer' )->count() ) {
					// Get YT video ID.
					$player_crawler = $enhancement_crawler->filter( 'div > div > ps-youtubeplayer' );
					$yt_video_id    = $player_crawler->getNode(0)->getAttribute('data-video-id');
					if ( ! $yt_video_id ) {
						// TODO -- log missing TY link
					}

					// Get Gutenberg YT block.
					$yt_link     = "https://www.youtube.com/watch?v=$yt_video_id";
					$yt_block    = $this->gutenberg->get_youtube( $yt_link );
					$custom_html = serialize_blocks( [ $yt_block ] );

					// Log that this post needs manual resaving (until we figure out programmatic oembed in postmeta).
					$this->logger->log( $log_need_oembed_resave, sprintf( "PostID: %d YouTube", $post_id ), $this->logger::WARNING );
				}


				/**
				 * Tweet embed to Twitter block.
				 */
				if ( $enhancement_crawler->filter( 'div.tweet-embed' )->count() ) {
					// Get Twitter link.
					$twitter_crawler = $enhancement_crawler->filter( 'div.tweet-embed > blockquote > a' );
					$twitter_link = '';
					foreach ( $twitter_crawler->getIterator() as $twitter_a_domelement ) {
						$href = $twitter_a_domelement->getAttribute( 'href' );
						if ( false !== strpos( $href, 'twitter.com' ) ) {
							$twitter_link = $href;
							break;
						}
					}

					if ( ! empty( $twitter_link ) ) {
						// Get Gutenberg Twitter block.
						$twitter_block = $this->gutenberg->get_twitter( $twitter_link );
						$custom_html   = serialize_blocks( [ $twitter_block ] );

						// Log that this post needs manual resaving (until we figure out programmatic oembed in postmeta).
						$this->logger->log( $log_need_oembed_resave, sprintf( "PostID: %d Twitter", $post_id ), $this->logger::WARNING );
					} else {
						if ( ! $yt_video_id ) {
							// TODO -- log missing TY link
						}
					}
				}


				/**
				 * ps-carousel slides to Gutenberg gallery block.
				 */
				if ( $enhancement_crawler->filter( 'ps-carousel' )->count() ) {

					// First scrape all images data.
					/**
					 * @var array $images_data {
					 *      @type string $src           Image URL.
					 *      @type string $alt           Image alt text.
					 *      @type string $credit        Image credit.
					 *      @type string $attachment_id Image credit.
					 * }
					 */
					$images_data = [];
					$slides_crawler = $enhancement_crawler->filter( 'ps-carousel > div.carousel-slides > div.carousel-slide' );
					$img_index = 0;
					foreach ( $slides_crawler->getIterator() as $div_slide_domelement ) {

						$images_data[ $img_index ] = [
							'src' => null,
							'alt' => null,
							'credit' => null,
							'attachment_id' => null,
                       ];

						// New crawler for each slide.
						$slides_info_crawler = new Crawler( $div_slide_domelement );

						// Get Credit from > div class=carousel-slide-inner ::: data-info-attribution="Cabrillo Robotics"
						$slide_inner_crawler = $slides_info_crawler->filter( 'div.carousel-slide-inner' );
						if ( $slide_inner_crawler->count() ) {
							$attribution = $slide_inner_crawler->getNode(0)->getAttribute('data-info-attribution');
							if ( $attribution ) {
								$images_data [ $img_index ][ 'credit' ] = $attribution;
							}
						}

						// Get Src and Alt from > div class=carousel-slide-inner > div.carousel-slide-media > img ::: alt src
						$slide_inner_crawler = $slides_info_crawler->filter( 'div.carousel-slide-inner > div.carousel-slide-media > img' );
						if ( $slide_inner_crawler->count() ) {
							$src = $slide_inner_crawler->getNode(0)->getAttribute('src');
							if ( ! $src ) {
								$src = $slide_inner_crawler->getNode(0)->getAttribute('data-flickity-lazyload');
							}
							if ( $src ) {
								$images_data[ $img_index ][ 'src' ] = $src;
							}
							$alt = $slide_inner_crawler->getNode(0)->getAttribute('alt');
							if ( $alt ) {
								$images_data[ $img_index ][ 'alt' ] = $alt;
							}
						}

						$img_index++;
					}

					// Import images and get attachment IDs.
					$attachment_ids = [];
					foreach ( $images_data as $image_data ) {

						if ( ! $image_data['src'] ) {
							// TODO -- log
							continue;
						}

						WP_CLI::line( sprintf( 'Downloading image: %s', $image_data['src'] ) );
						$attachment_id = $this->attachments->import_external_file(
							$image_data[ 'src' ],
							$title = null,
							$caption = null,
							$description = null,
							$image_data[ 'alt' ],
							$post_id = 0,
							$args = []
						);

						if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
							// TODO -- log failed attachment import
						} else {
							$attachment_ids[] = $attachment_id;
							// Save credit as Newspack credit.
							if ( $image_data[ 'credit' ] ) {
								update_post_meta( $attachment_id, '_media_credit', $image_data[ 'credit' ] );
							}
						}
					}

					// Get Gutenberg gallery block.
					if ( ! empty( $attachment_ids ) ) {
						$slideshow_block = $this->gutenberg->get_jetpack_slideshow( $attachment_ids );
						$custom_html     = serialize_blocks( [ $slideshow_block ] );

						// Log that this post needs manual resaving (until we figure out programmatic oembed in postmeta).
						$this->logger->log( $log_need_oembed_resave, sprintf( "PostID: %d JPSlideshow", $post_id ), $this->logger::WARNING );
					} else {
						// TODO -- log failed attachment import <-- i.e. failed gallery, but put to same log
					}
				}

			}


			// Get domelement HTML.
			if ( $custom_html ) {
				$domelement_html = $custom_html;
			} else {
				$domelement_html = $domelement->ownerDocument->saveHTML( $domelement );
				$domelement_html = trim( $domelement_html );
				if ( empty( $domelement_html ) ) {
					continue;
				}
			}

			// Append HTML.
			$post_content_updated .= ! empty( $post_content_updated ) ? "\n" : '';
			$post_content_updated .= $domelement_html;
		}

		return $post_content_updated;
	}
	/**
	 * @param string $post_content HTML.
	 */
	public function qa_remaining_div_enhancements( $log, $post_id, $post_content ) {

		$this->crawler->clear();
		$this->crawler->add( $post_content );

		/**
		 * Get the outer content div.rich-text-body in which the body HTML is nested.
		 */
		$div_content_crawler = $this->filter_selector_element( 'div.rich-text-body', $this->crawler );
		/**
		 * If div.rich-text-body was already removed, just temporarily surround the HTML with a new <div> so that nodes can be traversed the same way as children.
		 */
		if ( ! $div_content_crawler ) {
			$this->crawler->clear();
			$this->crawler->add( '<div>' . $post_content . '</div>' );
			$div_content_crawler = $this->filter_selector_element( 'div', $this->crawler );
		}


		/**
		 * QA 'div.enhancement's.
		 */
		foreach ( $div_content_crawler->childNodes->getIterator() as $key_domelement => $domelement ) {

			/**
			 * Examine 'div.enhancement's. If they are not one of the vetted ones, log them.
			 */
			$is_div_class_enhancement = ( isset( $domelement->tagName ) && 'div' == $domelement->tagName ) && ( 'enhancement' == $domelement->getAttribute( 'class' ) );
			if ( $is_div_class_enhancement ) {

				/**
				 * Keep adding vetted 'div.enhancement's which will end up in post_content.
				 */
				$enhancement_crawler = new Crawler( $domelement );
				if ( $enhancement_crawler->filter( 'div.quote-text > blockquote' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'figure.figure > img' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'div.infobox' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'figure > a[href="mailto:elections@lookoutlocal.com"]' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'div > iframe' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'div > div > div.infogram-embed' )->count() ) {
					continue;
				}
				if ( $enhancement_crawler->filter( 'figure.figure > p > img' )->count() ) {
					continue;
				}
				/**
				 * Keep adding vetted 'div.enhancement's which will end up in post_content.
				 */


				/**
				 * Any remaining 'div.enhancement's will be logged and should be QAed for whether they're approved in post_content.
				 */
				$enchancement_html = $domelement->ownerDocument->saveHTML( $domelement );
				$this->logger->log(
					$log,
					sprintf(
						"===PostID %d:\n%s",
						$post_id,
						$enchancement_html
					),
					false
				);

			}
		}
	}

	public function cmd_scrape_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		$urls_file = $assoc_args['urls-file'];
		if ( ! file_exists( $urls_file ) ) {
			WP_CLI::error( "File $urls_file does not exist." );
		}
		$urls = explode( "\n", trim( file_get_contents( $urls_file ), "\n" ) );
		if ( empty( $urls ) ) {
			WP_CLI::error( "File $urls_file is empty." );
		}

		/**
		 * Prepare logs and caching.
		 */

		// Log files.
		$log_wrong_urls                   = $this->temp_dir . '/ll_debug__wrong_urls.log';
		$log_all_author_names             = $this->temp_dir . '/ll_debug__all_author_names.log';
		$log_all_tags                     = $this->temp_dir . '/ll_debug__all_tags.log';
		$log_all_tags_promoted_content    = $this->temp_dir . '/ll_debug__all_tags_promoted_content.log';
		$log_err_importing_featured_image = $this->temp_dir . '/ll_err__featured_image.log';

		// Hit timestamp on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_wrong_urls, $ts, false );
		$this->logger->log( $log_all_author_names, $ts, false );
		$this->logger->log( $log_all_tags, $ts, false );
		$this->logger->log( $log_all_tags_promoted_content, $ts, false );
		$this->logger->log( $log_err_importing_featured_image, $ts, false );

		// Create folders for caching stuff.
		// Cache scraped HTMLs (in case we need to repeat scraping/identifying data from HTMLs).
		$scraped_htmls_cache_path = $this->temp_dir . '/scraped_htmls';
		if ( ! file_exists( $scraped_htmls_cache_path ) ) {
			mkdir( $scraped_htmls_cache_path, 0777, true );
		}


		/**
		 * Scrape and import URLs.
		 */
		$debug_all_author_names          = [];
		$debug_wrong_posts_urls          = [];
		$debug_all_tags                  = [];
		$debug_all_tags_promoted_content = [];
		foreach ( $urls as $key_url_data => $url ) {

			if ( empty( $url ) ) {
				continue;
			}

			WP_CLI::line( sprintf( '%d/%d Scraping and importing URL %s ...', $key_url_data + 1, count( $urls ), $url ) );

			// If a "publish"-ed post with same URL exists, skip it.
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"select wpm.post_id
					from {$wpdb->postmeta} wpm
					join wp_posts wp on wp.ID = wpm.post_id 
					where wpm.meta_key = %s
					and wpm.meta_value = %s
					and wp.post_status = 'publish' ; ",
					'newspackmigration_url',
					$url
				)
			);
			if ( $post_id ) {
				WP_CLI::line( sprintf( 'Already imported ID %d URL %s, skipping.', $post_id, $url ) );
				continue;
			}

			// HTML cache filename and path.
			$html_cached_filename  = $this->sanitize_filename( $url ) . '.html';
			$html_cached_file_path = $scraped_htmls_cache_path . '/' . $html_cached_filename;

			// Get HTML from cache or fetch from HTTP.
			$html = file_exists( $html_cached_file_path ) ? file_get_contents( $html_cached_file_path ) : null;
			if ( is_null( $html ) ) {
				$get_result = $this->wp_remote_get_with_retry( $url );
				if ( is_wp_error( $get_result ) || is_array( $get_result ) ) {
					// Not OK.
					$debug_wrong_posts_urls[] = $url;
					$msg                      = is_wp_error( $get_result ) ? $get_result->get_error_message() : $get_result['response']['message'];
					$this->logger->log( $log_wrong_urls, sprintf( 'URL:%s CODE:%s MESSAGE:%s', $url, $get_result['response']['code'], $msg ), $this->logger::WARNING );
					continue;
				}

				$html = $get_result;

				// Cache HTML to file.
				file_put_contents( $html_cached_file_path, $html );
			}

			// Crawl and extract all useful data from HTML
			$crawled_data = $this->crawl_post_data_from_html( $html, $url );

			// Get slug from URL.
			$slug = $this->get_slug_from_url( $url );

			// Create post.
			$post_args = [
				'post_title'   => $crawled_data['post_title'],
				'post_content' => $crawled_data['post_content'],
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_name'    => $slug,
				'post_date'    => $crawled_data['post_date'],
			];
			$post_id   = wp_insert_post( $post_args );
			WP_CLI::line( sprintf( 'Created post ID %d', $post_id ) );

			// Collect postmeta in this array.
			$postmeta = [
				// Newspack Subtitle postmeta
				'newspack_post_subtitle'                  => $crawled_data['post_subtitle'] ?? '',
				// Basic data
				'newspackmigration_url'                   => $url,
				'newspackmigration_slug'                  => $slug,
				// E.g. "lo-sc".
				'newspackmigration_script_source'         => $crawled_data['script_data']['source'] ?? '',
				// E.g. "uc-santa-cruz". This is a backup value to help debug categories, if needed.
				'newspackmigration_script_sectionName'    => $crawled_data['script_data']['sectionName'],
				// E.g. "Promoted Content".
				'newspackmigration_script_tags'           => $crawled_data['script_data']['tags'] ?? '',
				'newspackmigration_presentedBy'           => $crawled_data['presented_by'] ?? '',
				'newspackmigration_tags_promoted_content' => $crawled_data['tags_promoted_content'] ?? '',
				// Author links, to be processed after import.
				'newspackmigration_author_links'          => $crawled_data['author_links'] ?? '',
				// Featured img info.
				'featured_image_src'                      => $crawled_data['featured_image_src'] ?? '',
				'featured_image_caption'                  => $crawled_data['featured_image_caption'] ?? '',
				'featured_image_alt'                      => $crawled_data['featured_image_alt'] ?? '',
				'featured_image_credit'                   => $crawled_data['featured_image_credit'] ?? '',
			];

			// Collect all tags_promoted_content for QA.
			if ( $crawled_data['tags_promoted_content'] ) {
				$debug_all_tags_promoted_content = array_merge( $debug_all_tags_promoted_content, [ $crawled_data['tags_promoted_content'] ] );
			}

			// Import featured image.
			if ( isset( $crawled_data['featured_image_src'] ) ) {
				WP_CLI::line( 'Downloading featured image ...' );
				$attachment_id   = $this->attachments->import_external_file(
					$crawled_data['featured_image_src'],
					$title       = null,
					$crawled_data['featured_image_caption'],
					$description = null,
					$crawled_data['featured_image_alt'],
					$post_id,
					$args        = []
				);
				if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
					$this->logger->log( $log_err_importing_featured_image, sprintf( 'Error importing featured image to post ID: %d src: %s ERR: %s', $post_id, $crawled_data['featured_image_src'], is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : '/na' ), $this->logger::WARNING );
				} else {
					set_post_thumbnail( $post_id, $attachment_id );
					// Credit goes as Newspack credit postmeta.
					if ( $crawled_data['featured_image_credit'] ) {
						update_post_meta( $attachment_id, self::MEDIA_CREDIT_META, $crawled_data['featured_image_credit'] );
					}
				}
			}

			// Authors.
			$ga_ids = [];
			// Get/create GAs.
			foreach ( $crawled_data['post_authors'] as $author_name ) {
				$ga = $this->cap->get_guest_author_by_display_name( $author_name );
				if ( $ga ) {
					$ga_id = $ga->ID;
				} else {
					$ga_id = $this->cap->create_guest_author( [ 'display_name' => $author_name ] );
				}
				$ga_ids[] = $ga_id;
			}
			if ( empty( $ga_ids ) ) {
				throw new \UnexpectedValueException( sprintf( 'Could not get any authors for post %s', $url ) );
			}
			// Assign GAs to post.
			$this->cap->assign_guest_authors_to_post( $ga_ids, $post_id, false );
			// Also collect all author names for easier debugging/QA-ing.
			$debug_all_author_names = array_merge( $debug_all_author_names, $crawled_data['post_authors'] );

			// Categories.
			$category_parent_id = 0;
			if ( $crawled_data['category_parent_name'] ) {
				// Get or create parent category.
				$category_parent_id = wp_create_category( $crawled_data['category_parent_name'], 0 );
				if ( is_wp_error( $category_parent_id ) ) {
					throw new \UnexpectedValueException( sprintf( 'Could not get or create parent category %s for post %s error message: %s', $crawled_data['category_parent_name'], $url, $category_parent_id->get_error_message() ) );
				}
			}
			// Get or create primary category.
			$category_id = wp_create_category( $crawled_data['category_name'], $category_parent_id );
			if ( is_wp_error( $category_id ) ) {
				throw new \UnexpectedValueException( sprintf( 'Could not get or create parent category %s for post %s error message: %s', $crawled_data['category_name'], $url, $category_id->get_error_message() ) );
			}
			// Set category.
			wp_set_post_categories( $post_id, [ $category_id ] );

			// Assign tags.
			$tags = $crawled_data['tags'];
			if ( $tags ) {
				// wp_set_post_tags() also takes a CSV of tags, so this might work out of the box. But we're saving
				wp_set_post_tags( $post_id, $tags );
				// Collect all tags for QA.
				$debug_all_tags = array_merge( $debug_all_tags, [ $tags ] );
			}

			// Save the postmeta.
			foreach ( $postmeta as $meta_key => $meta_value ) {
				if ( ! empty( $meta_value ) ) {
					update_post_meta( $post_id, $meta_key, $meta_value );
				}
			}

			$d = 1;
		}

		// Debug and QA info.
		if ( ! empty( $debug_wrong_posts_urls ) ) {
			WP_CLI::warning( "❗️ Check $log_wrong_urls for invalid URLs." );
		}
		if ( ! empty( $debug_all_author_names ) ) {
			$this->logger->log( $log_all_author_names, implode( "\n", $debug_all_author_names ), false );
			WP_CLI::warning( "⚠️️ QA the following $log_all_author_names " );
		}
		if ( ! empty( $debug_all_tags ) ) {
			// Flatten multidimensional array to single.
			$debug_all_tags_flattened = [];
			array_walk_recursive(
				$debug_all_tags,
				function( $e ) use ( &$debug_all_tags_flattened ) {
					$debug_all_tags_flattened[] = $e;
				}
			);
			// Log.
			$this->logger->log( $log_all_tags, implode( "\n", $debug_all_tags_flattened ), false );
			WP_CLI::warning( "⚠️️ QA the following $log_all_tags ." );
		}
		if ( ! empty( $debug_all_tags_promoted_content ) ) {
			$this->logger->log( $log_all_tags_promoted_content, implode( "\n", $debug_all_tags_promoted_content ), false );
			WP_CLI::warning( "⚠️️ QA the following $log_all_tags_promoted_content ." );
		}

		WP_CLI::line( 'Done 👍' );
	}

	public function get_slug_from_url( $url ) {
		$url_path          = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$url_path_exploded = explode( '/', $url_path );
		$slug              = end( $url_path_exploded );

		return $slug;
	}

	public function sanitize_filename( $string ) {
		$string_sanitized = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $string ) );

		return $string_sanitized;
	}

	/**
	 * Tries to get post URL from relational single-table 6GB dump the Publisher sent us.
	 * This is difficult to use due to super slow queries and that the data is convoluted.
	 *
	 * @param $newspack_entries_table_row
	 * @param $section_data_cache_path
	 *
	 * @return string|null
	 */
	public function get_post_url( $newspack_entries_table_row, $section_data_cache_path ) {
		global $wpdb;

		$slug = $newspack_entries_table_row['slug'];
		$data = json_decode( $newspack_entries_table_row['data'], true );

		/**
		 * Example post URL looks like this:
		 *      https://lookout.co/santacruz/environment/story/2020-11-18/debris-flow-evacuations-this-winter
		 *
		 * Tried getting URL/ permalink from `Record` by "cms.directory.pathTypes", but it's not there in that format:
		 *      select data from Record where data like '%00000175-41f4-d1f7-a775-edfd1bd00000:00000175-dd52-dd02-abf7-dd72cf3b0000%' and data like '%environment%';
		 * It's probably split by two objects separated by ":", but that's difficult to locate in `Record`.
		 *
		 * Next, trying to just get the name of category, e.g. "environment", and date, e.g. "2020-11-18", from `Record`, then compose the URL manually.
		 * Searching by relational sections "sectionable.section", "_id" and "_type".
		 *      select data from Record where data like '{"cms.site.owner"%' and data like '%"_type":"ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"%' and data like '%"_id":"00000175-8030-d826-abfd-ec7086fa0000"%' order by id desc limit 1;
		 */

		// Get (what I believe to be) category data entry from Record table.
		if ( ! isset( $data['sectionable.section']['_ref'] ) || ! isset( $data['sectionable.section']['_type'] ) ) {
			return null;
		}
		$article_ref                       = $data['sectionable.section']['_ref'];
		$article_type                      = $data['sectionable.section']['_type'];
		$id_like                           = sprintf( '"_id":"%s"', $article_ref );
		$type_like                         = sprintf( '"_type":"%s"', $article_type );
		$section_data_temp_cache_file_name = $article_type . '__' . $article_ref;
		$section_data_temp_cache_file_path = $section_data_cache_path . '/' . $section_data_temp_cache_file_name;

		$record_table = self::DATA_EXPORT_TABLE;
		if ( ! file_exists( $section_data_temp_cache_file_path ) ) {
			$sql = "select data from {$record_table} where data like '{\"cms.site.owner\"%' and data like '%{$id_like}%' and data like '%{$type_like}%' order by id desc limit 1;";
			WP_CLI::line( sprintf( 'Querying post URL...' ) );
			$section_result = $wpdb->get_var( $sql );
			file_put_contents( $section_data_temp_cache_file_path, $section_result );
		} else {
			$section_result = file_get_contents( $section_data_temp_cache_file_path );
		}
		$section = json_decode( $section_result, true );

		// Check if section data is valid.
		if ( ! $section || ! isset( $section['cms.directory.paths'] ) || ! $section['cms.directory.paths'] ) {
			$d = 1;
		}

		// Get last exploded url segment from, e.g. "cms.directory.paths":["00000175-41f4-d1f7-a775-edfd1bd00000:00000175-32a8-d1f7-a775-feedba580000/environment"
		if ( ! isset( $section['cms.directory.paths'][0] ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get section data for article slug: %s _ref: %s and _type:%s', $slug, $article_ref, $article_type ) );
		}
		$section_paths_exploded = explode( '/', $section['cms.directory.paths'][0] );
		$section_slug           = end( $section_paths_exploded );
		if ( ! $section_slug ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get section for article slug: %s _ref: %s and _type:%s', $slug, $article_ref, $article_type ) );
		}

		// Get date slug, e.g. '2020-11-18'.
		$date_slug = date( 'Y-m-d', $data['cms.content.publishDate'] / 1000 );
		if ( ! $section_slug ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get date slug for article slug: %s _ref: %s and _type:%s', $slug, $article_ref, $article_type ) );
		}

		// Compose URL.
		$url_data = sprintf(
			'https://lookout.co/santacruz/%s/story/%s/%s',
			$section_slug,
			$date_slug,
			$slug
		);

		return $url_data;
	}

	/**
	 * Crawls all useful post data from HTML.
	 *
	 * @param string $html                    HTML.
	 * @param array  &$debug_all_author_names Stores all author names for easier QA/debugging.
	 * @param array  &$debug_all_tags         Stores all tags for easier QA/debugging.
	 *
	 * @return array $data All posts data crawled from HTML. {
	 *      @type array   script_data            Decoded data from that one <script> element with useful post info.
	 *      @type string  post_title
	 *      @type string  subtitle
	 *      @type string  post_content
	 *      @type string  post_date
	 *      @type array   post_authors           Array of author names.
	 *      @type ?string featured_image_src
	 *      @type ?string featured_image_alt
	 *      @type ?string featured_image_caption
	 *      @type ?string featured_image_credit
	 *      @type string  category_name
	 *      @type ?string category_parent_name
	 *      @type ?string tags
	 *      @type ?string presented_by
	 * }
	 */
	public function crawl_post_data_from_html( $html, $url ) {

		$data = [];

		/**
		 * Get all post data.
		 */
		$this->crawler->clear();
		$this->crawler->add( $html );

		// Extract some data from this <script> element which contains useful data.
		$script_json = $this->filter_selector( 'script#head-dl', $this->crawler );
		$script_json = ltrim( $script_json, 'var dataLayer = ' );
		$script_json = rtrim( $script_json, ';' );
		$script_data = json_decode( $script_json, true );
		$script_data = $script_data[0] ?? null;
		if ( is_null( $script_data ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get <script> element data for post %s', $url ) );
		}

		$data['script_data'] = $script_data;

		// Title, subtitle, content.
		$title = $this->filter_selector( 'h1.headline', $this->crawler );
		if ( empty( $title ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get title for post %s', $url ) );
		}
		$data['post_title'] = $title;

		$subtitle              = $this->filter_selector( 'div.subheadline > h2', $this->crawler ) ?? null;
		$data['post_subtitle'] = $subtitle ?? null;

		$post_content = $this->filter_selector( 'div#pico', $this->crawler, false, false );
		if ( empty( $post_content ) ) {
			$post_content = $this->filter_selector( 'div.rich-text-article-body-content', $this->crawler, false, false );
		}
		if ( empty( $post_content ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get post content for post %s', $url ) );
		}
		$data['post_content'] = $post_content;

		// Date. <script> element has both date and time of publishing.
		$matched = preg_match( '/(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})/', $script_data['publishDate'], $matches_date );
		if ( false === $matched ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get date for post %s', $url ) );
		}
		$post_date         = sprintf( '%s-%s-%s %s:%s:00', $matches_date[3], $matches_date[1], $matches_date[2], $matches_date[4], $matches_date[5] );
		$data['post_date'] = $post_date;

		// Authors.
		// div.author-name might or might not have <a>s with links to author page.
		$authors_text         = $this->filter_selector( 'div.author-name', $this->crawler );
		$data['post_authors'] = $this->filter_author_names( $authors_text );
		$data['author_links'] = [];
		// If there is one or more links to author pages, save them to be processed after import.
		$author_link_crawler = $this->filter_selector_element( 'div.author-name > a', $this->crawler, $single = false );
		if ( $author_link_crawler ) {
			foreach ( $author_link_crawler->getIterator() as $author_link_node ) {
				$data['author_links'][] = $author_link_node->getAttribute( 'href' );
			}
		}

		// Featured image.
		$featured_image = $this->filter_selector_element( 'div.page-lead-media > figure > img', $this->crawler );
		if ( $featured_image ) {
			$featured_image_src         = $featured_image->getAttribute( 'src' );
			$data['featured_image_src'] = $featured_image_src;

			$featured_image_alt         = $featured_image->getAttribute( 'alt' ) ?? null;
			$data['featured_image_alt'] = $featured_image_alt;

			$featured_image_caption         = $this->filter_selector( 'div.page-lead-media > figure > div.figure-content > div.figure-caption', $this->crawler ) ?? null;
			$data['featured_image_caption'] = $featured_image_caption;

			$featured_image_credit         = $this->filter_selector( 'div.page-lead-media > figure > div.figure-content > div.figure-credit', $this->crawler );
			$featured_image_credit         = $this->format_featured_image_credit( $featured_image_credit ) ?? null;
			$data['featured_image_credit'] = $featured_image_credit;
		}

		// Category.
		// Section name is located both in <meta> element:
		// <meta property="article:section" content="UC Santa Cruz">
		// and in <script> element data:
		// $script_data['sectionName]
		// but in <script> it's in a slug form, e.g. "uc-santa-cruz", so we'll use <meta> for convenience.
		$section_meta_crawler  = $this->filter_selector_element( 'meta[property="article:section"]', $this->crawler );
		$category_name         = $section_meta_crawler->getAttribute( 'content' );
		$data['category_name'] = $category_name;

		// Parent category.
		// E.g. "higher-ed"
		$section_parent_slug          = $script_data['sectionParentPath'] ?? null;
		$category_parent_name         = self::SECTIONS[ $section_parent_slug ] ?? null;
		$data['category_parent_name'] = $category_parent_name;

		// Tags.
		$tags      = [];
		$a_crawler = $this->filter_selector_element( 'div.tags > a', $this->crawler, $single = false );
		if ( $a_crawler && $a_crawler->getIterator()->count() > 0 ) {
			foreach ( $a_crawler as $a_node ) {
				$tags[] = $a_node->nodeValue;
			}
		}
		// Tag "Promoted Content" found in <script> element too.
		$tags_promoted_content = $script_data['tags'] ?? null;
		// Add both tags.
		$data['tags']                  = ! empty( $tags ) ? $tags : null;
		$data['tags_promoted_content'] = $tags_promoted_content;

		// Presented by.
		/**
		 * E.g. "Promoted Content"
		 * This data is also found in <meta property="article:tag" content="Promoted Content">.
		 */
		$presented_by         = $this->filter_selector( 'div.brand-content-name', $this->crawler ) ?? null;
		$data['presented_by'] = $presented_by;

		return $data;
	}

	public function format_featured_image_credit( $featured_image_credit ) {
		$featured_image_credit = trim( $featured_image_credit, ' ()' );

		return $featured_image_credit;
	}
	public function filter_author_names( $authors_text ) {

		$authors_text = trim( $authors_text );
		$authors_text = ltrim( $authors_text, 'By: ' );
		$authors_text = ltrim( $authors_text, 'By ' );
		$authors_text = ltrim( $authors_text, 'Written by: ' );
		$authors_text = ltrim( $authors_text, 'Written by ' );

		// Explode names by comma.
		$authors_text = str_replace( ', ', ',', $authors_text );
		$author_names = explode( ',', $authors_text );

		// Trim all names (wo/ picking up " " spaces).
		$author_names = array_map(
			function( $value ) {
				return trim( $value, '  ' );
			},
			$author_names
		);

		return $author_names;
	}

	/**
	 * Crawls content by CSS selector.
	 * Can get text only, or full HTML content.
	 * Can sanitize text optionally
	 *
	 * @param $selector
	 * @param $dom_crawler
	 * @param $get_text
	 * @param $sanitize_text
	 *
	 * @return string|null
	 */
	public function filter_selector( $selector, $dom_crawler, $get_text = true, $sanitize_text = true ) {
		$text = null;

		$found_element = $this->data_parser->get_element_by_selector( $selector, $dom_crawler, $single = true );
		if ( $found_element && true === $get_text ) {
			// Will return text cleared from formatting.
			$text = $found_element->textContent;
		} elseif ( $found_element && false === $get_text ) {
			// Will return HTML.
			$text = $found_element->ownerDocument->saveHTML( $found_element );
		}
		if ( $found_element && true === $sanitize_text ) {
			$text = sanitize_text_field( $text );
		}

		return $text;
	}

	/**
	 * Gets Crawler node by CSS selector.
	 *
	 * @param $selector
	 * @param $dom_crawler
	 *
	 * @return false|Crawler
	 */
	public function filter_selector_element( $selector, $dom_crawler, $single = true ) {
		$found_element = $this->data_parser->get_element_by_selector( $selector, $dom_crawler, $single );

		return $found_element;
	}

	/**
	 * @param $url     URL to scrape.
	 * @param $retried Number of times this function was retried.
	 * @param $retries Number of times to retry.
	 * @param $sleep   Number of seconds to sleep between retries.
	 *
	 * @return string|array Body HTML string or Response array from \wp_remote_get() in case of error.
	 */
	public function wp_remote_get_with_retry( $url, $retried = 0, $retries = 3, $sleep = 2 ) {

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 60,
				'user-agent' => 'Newspack Scraper Migrator',
			]
		);

		// Retry if error, or if response code is not 200 and retries are not exhausted.
		if (
			( is_wp_error( $response ) || ( 200 != $response['response']['code'] ) )
			&& ( $retried < $retries )
		) {
			sleep( $sleep );
			$retried++;
			$response = $this->wp_remote_get_with_retry( $url, $retried, $retries, $sleep );
		}

		// If everything is fine, return body.
		if ( ! is_wp_error( $response ) && ( 200 == $response['response']['code'] ) ) {
			$body = wp_remote_retrieve_body( $response );

			return $body;
		}

		// If not OK, return response array.
		return $response;
	}

	/**
	 * Temp dev command for stuff and things.
	 *
	 * @param $pos_args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_dev( $pos_args, $assoc_args ) {
		global $wpdb;

		/**
		 * Locate "authorable.authors".
		 */
		// take example post from live with known author "Thomas Sawano"
		$json = $wpdb->get_var( "select data from newspack_entries where slug = 'editorial-newsletter-test-do-not-publish';" );
		// $json = $wpdb->get_var( "select data from newspack_entries where slug = 'ucsc-archive-10-000-photos-santa-cruz-history';" );
		$data = json_decode( $json, true );

		// Draft status.
		$draft  = $data['cms.content.draft'] ?? false;
		$draft2 = 'cms.content.draft' == $data['dari.visibilities'][0] ?? false;

		/**
		 * Has:
		 * authorable.authors = {array[1]}
		 * 0 = {array[2]}
		 * _ref = "00000182-b2df-d6aa-a783-b6dfd7b50000"
		 * _type = "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
		 */
		foreach ( $data['authorable.authors'] as $data_author ) {
			$authorable_author_id   = $data_author['_ref'];
			$authorable_author_type = $data_author['_type'];
			$id_like                = sprintf( '"_id":"%s"', $authorable_author_id );
			$type_like              = sprintf( '"_type":"%s"', $authorable_author_type );
			// Find author in DB.
			$author_json = $wpdb->get_var( "select data from Record where data like '{\"cms.site.owner\"%' and data like '%{$id_like}%' and data like '%{$type_like}%';" );
			// Dev test:
			// $author_json = <<<JSON
			// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.directory.paths":["00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano"],"cms.directory.pathTypes":{"00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano":"PERMALINK"},"cms.content.publishDate":1660858690827,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660927400870,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","features.disabledFeatures":[],"shared.content.rootId":null,"shared.content.sourceId":null,"shared.content.version":null,"canonical.canonicalUrl":null,"promotable.hideFromDynamicResults":false,"catimes.seo.suppressSeoSiteDisplayName":false,"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"cms.seo.keywords":[],"cms.seo.robots":[],"commentable.enableCommenting":false,"feed.disableFeed":false,"feed.renderFullContent":false,"feed.enabledFeedItemTypes":[],"image":{"_ref":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"cover":{"_ref":"00000182-b2de-d6aa-a783-b6dff3bf0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"section":{"_ref":"00000175-7fd0-dffc-a7fd-7ffd9e6a0000","_type":"ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"},"name":"Thomas Sawano","firstName":"Thomas","lastName":"Sawano","title":"Newsroom Intern","email":"thomas@lookoutlocal.com","fullBiography":"Thomas Sawano joins the Lookout team after two-and-a-half years at City on a Hill Press, the student-run newspaper at UCSC. While there, he reported on the university, arts and culture events, and the city of Santa Cruz. Thomas is deeply interested in local politics and feels fortunate to have begun his journalistic career in this town.<br/><br/>Thomas graduated in 2022 with degrees in Cognitive Science and Philosophy. Though hailing from Los Angeles, he has vowed to never live there again on account of traffic and a lack of actual weather. Thomas loves traveling, going to music festivals, and watching documentaries about the outdoors. He has recently picked up rock climbing, and hopes the sport won’t damage his typing hands <i>too </i>badly.<br/><br/>","shortBiography":"","affiliation":"Lookout Santa Cruz","isExternal":false,"theme.lookout-local.:core:page:Page.hbs._template":null,"theme.lookout-local.:core:promo:Promo.hbs.breaking":false,"theme.lookout-local.:core:promo:Promo.hbs.imageDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.descriptionDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.categoryDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.timestampDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.moreCoverageLinksDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.promoAlignment":null,"theme.lookout-local.:core:promo:Promo.hbs._template":null,"theme.lookout-local.:core:promo:Promo.amp.hbs._template":null,"cms.directory.pathsMode":"MANUAL","_id":"00000182-b2df-d6aa-a783-b6dfd7b50000","_type":"7f0435e9-b5f5-3286-9fe0-e839ddd16058"}
			// JSON;
			$author = json_decode( $author_json, true );
			// Also exist ['cover']['_ref'] and ['section']['_ref'].
			$full_name  = $author['name'];
			$first_name = $author['firstName'];
			$last_name  = $author['lastName'];
			$email      = $author['email'];
			$bio        = $author['fullBiography'];
			$short_bio  = $author['shortBiography'];
			// E.g. "Newsroom Intern"
			$title = $author['title'];
			// E.g. "Lookout Santa Cruz"
			$affiliation = $author['affiliation'];
			// External to their publication.
			$is_external = $author['isExternal'];

			// Avatar image.
			$image_ref  = $author['image']['_ref'];
			$image_type = $author['image']['_type'];
			$sql        = "select data from Record where data like '{\"cms.site.owner\"%' and data like '%\"_id\":\"{$image_ref}\"%' and data like '%\"_type\":\"{$image_type}\"%' ;";
			$image_json = $wpdb->get_var( $sql );
			// Dev test:
			// $image_json = <<<JSON
			// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.content.publishDate":1660858629241,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660858674492,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","shared.content.version":"00000182-b2e4-daa2-a5fe-b2ed30fe0000","taggable.tags":[],"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"type":{"_ref":"a95896f6-e74f-3667-a305-b6a50d72056a","_type":"982a8b2a-7600-3bb0-ae68-740f77cd85d3"},"titleFallbackDisabled":false,"file":{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/thomas-sawano-white.png","contentType":"image/png","metadata":{"cms.edits":{},"originalFilename":"Thomas Sawano white.png","http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["1074663"],"Content-Type":["image/png"]},"resizes":[{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/resizes/500/thomas-sawano-white.png","contentType":"image/png","metadata":{"width":500,"height":500,"http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["349214"],"Content-Type":["image/png"]}}}],"width":1080,"File Type":{"Detected File Type Long Name":"Portable Network Graphics","Detected File Type Name":"PNG","Detected MIME Type":"image/png","Expected File Name Extension":"png"},"PNG-IHDR":{"Filter Method":"Adaptive","Interlace Method":"No Interlace","Compression Type":"Deflate","Image Height":"1080","Color Type":"True Color with Alpha","Image Width":"1080","Bits Per Sample":"8"},"PNG-pHYs":{"Pixels Per Unit X":"3780","Pixels Per Unit Y":"3780","Unit Specifier":"Metres"},"PNG-tEXt":{"Textual Data":"Comment: xr:d:DAE5wFeyjSQ:518,j:33207655899,t:22081821"},"height":1080,"cms.crops":{},"cms.focus":{"x":0.4397042465484525,"y":0.2428842504743833}}},"keywords":[],"keywordsFallbackDisabled":false,"dateUploaded":1660858629241,"caption":"","captionFallbackDisabled":false,"credit":"","creditFallbackDisabled":false,"altText":"Thomas Sawano","bylineFallbackDisabled":false,"instructionsFallbackDisabled":false,"sourceFallbackDisabled":false,"copyrightNoticeFallbackDisabled":false,"headlineFallbackDisabled":false,"categoryFallbackDisabled":false,"supplementalCategory":[],"supplementalCategoryFallbackDisabled":false,"writerFallbackDisabled":false,"countryFallbackDisabled":false,"countryCodeFallbackDisabled":false,"origTransRefFallbackDisabled":false,"metadataStateFallbackDisabled":false,"cityFallbackDisabled":false,"width":1080,"height":1080,"_id":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"}
			// JSON;
			$image = json_decode( $image_json, true );
			if ( 's3' != $image['file']['storage'] ) {
				// Debug this.
				$d = 1;
			}
			$image_url   = self::LOOKOUT_S3_SCHEMA_AND_HOSTNAME . '/' . $image['file']['path'];
			$image_title = $image['file']['metadata']['originalFilename'];
			$image_alt   = $image['altText'];
		}
		$authorable_author_id = $data['authorable.authors']['_ref'];
		// ,"_type":"7f0435e9-b5f5-3286-9fe0-e839ddd16058"

		return;


		/**
		 * Get post data from newspack_entries
		 */
		$json = $wpdb->get_var( "SELECT data FROM newspack_entries where slug = 'first-image-from-nasas-james-webb-space-telescope-reveals-thousands-of-galaxies-in-stunning-detail';" );
		$data = json_decode( $json, true );
		return;


		/**
		 * Decode JSONs from file
		 */
		$lines = explode( "\n", file_get_contents( '/Users/ivanuravic/www/lookoutlocal/app/public/0_examine_DB_export/search/authorable_oneoff.log' ) );
		$jsons = [];
		foreach ( $lines as $line ) {
			$data = json_decode( $line, true );
			if ( ! $data ) {
				$line = str_replace( '\\\\', '\\', $line ); // Replace double escapes with just one escape.
				$data = json_decode( $line, true );
				if ( ! $data ) {
					$line = str_replace( '\\\\', '\\', $line ); // Replace double escapes with just one escape.
					$data = json_decode( $line, true );
					if ( $data ) {
						$jsons[] = $data; }
				} else {
					$jsons[] = $data; }
			} else {
				$jsons[] = $data; }
		}
		$d          = 1;
		$jsons_long = json_encode( $jsons );
		return;

	}

	/**
	 * Callable for `newspack-content-migrator lookoutlocal-create-custom-table`.
	 *
	 * Tried to see if we can get all relational data ourselves from `Record` table.
	 * The answer is no -- it is simply too difficult, better to scrape.
	 *
	 * @param array $pos_args   Array of positional arguments.
	 * @param array $assoc_args Array of associative arguments.
	 *
	 * @return void
	 */
	public function cmd_create_custom_table( $pos_args, $assoc_args ) {
		global $wpdb;

		// Table names.
		$record_table = self::DATA_EXPORT_TABLE;
		$custom_table = self::CUSTOM_ENTRIES_TABLE;

		// Check if Record table is here.
		$count_record_table = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_NAME = %s;', $record_table ) );
		if ( 1 != $count_record_table ) {
			WP_CLI::error( sprintf( 'Table %s not found.', $record_table ) );
		}

		$continue = PHP_Utils::readline( sprintf( 'Continuing will truncate the existing %s table. Continue? [y/n] ', $record_table ) );
		if ( 'y' !== $continue ) {
			WP_CLI::error( 'Aborting.' );
		}

		// Create/truncate custom table.
		$this->create_custom_table( $custom_table, $truncate = true );

		// Read from $record_table and write just posts entries to $custom_table.
		$offset        = 0;
		$batchSize     = 1000;
		$total_rows    = $wpdb->get_var( "SELECT count(*) FROM {$record_table}" );
		$total_batches = ceil( $total_rows / $batchSize );
		while ( true ) {

			WP_CLI::line( sprintf( '%d/%d getting posts from %s into %s ...', $offset, $total_rows, $record_table, $custom_table ) );

			// Query in batches.
			$sql  = "SELECT * FROM {$record_table} ORDER BY id, typeId ASC LIMIT $batchSize OFFSET $offset";
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( count( $rows ) > 0 ) {
				foreach ( $rows as $row ) {

					// Get row JSON data. It might be readily decodable, or double backslashes may have to be removed up to two times.
					$data_result = $row['data'];
					$data        = json_decode( $data_result, true );
					if ( ! $data ) {
						$data_result = str_replace( '\\\\', '\\', $data_result ); // Replace double escapes with just one escape.
						$data        = json_decode( $data_result, true );
						if ( ! $data ) {
							$data_result = str_replace( '\\\\', '\\', $data_result ); // Replace double escapes with just one escape.
							$data        = json_decode( $data_result, true );
						}
					}

					// Skip drafts.
					$draft = $data['cms.content.draft'] ?? false;
					// $draft2 = 'cms.content.draft' == $data['dari.visibilities'][0] ?? false;
					if ( $draft ) {
						continue;
					}

					// Check if this is a post.
					$slug         = $data['sluggable.slug'] ?? null;
					$title        = $data['headline'] ?? null;
					$post_content = $data['body'] ?? null;
					$is_a_post    = $slug && $title && $post_content;
					if ( ! $is_a_post ) {
						continue;
					}

					// Insert to custom table
					$wpdb->insert(
						$custom_table,
						[
							'slug' => $slug,
							'data' => json_encode( $data ),
						]
					);
				}

				$offset += $batchSize;
			} else {
				break;
			}
		}

		// Group by slugs and leave just the most recent entry.

		WP_CLI::line( 'Done' );
	}

	public function cmd_deprecated_import_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		$data_jsons = $wpdb->get_col( 'SELECT data from %s', self::CUSTOM_ENTRIES_TABLE );
		foreach ( $data_jsons as $data_json ) {
			$data = json_encode( $data_json, true );

			// Get post data.
			$slug         = $data['sluggable.slug'];
			$title        = $data['headline'];
			$subheadline  = $data['subHeadline'];
			$post_content = $data['body'];
			$post_date    = $this->convert_epoch_timestamp_to_wp_format( $data['cms.content.publishDate'] );

			// Create post.
			$post_args = [
				'post_title'   => $title,
				'post_content' => $post_content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_name'    => $slug,
				'post_date'    => $post_date,
			];
			$post_id   = wp_insert_post( $post_args );


			// Get more postmeta.
			$postmeta = [
				'newspackmigration_commentable.enableCommenting' => $data['commentable.enableCommenting'],
			];
			if ( $subheadline ) {
				$postmeta['newspackmigration_post_subtitle'] = $subheadline;
			}


			// Get more post data to update all at once.
			$post_modified    = $this->convert_epoch_timestamp_to_wp_format( $data['publicUpdateDate'] );
			$post_update_data = [
				'post_modified' => $post_modified,
			];


			// Post URL.
			// Next -- find post URL for redirect purposes and store as meta. Looks like it's stored as "canonicalURL" in some related entries.
			// ? "paths" data ?

			// Post excerpt.
			// Next -- find excerpt.


			// Featured image.
			$data['lead'];
			// These two fields:
			// "_id": "00000184-6982-da20-afed-7da6f7680000",
			// "_type": "52f00ba5-1f41-3845-91f1-1ad72e863ccb"
			$data['lead']['leadImage'];
			// Can be single entry:
			// "_ref": "0000017b-75b6-dd26-af7b-7df6582f0000",
			// "_type": "4da1a812-2b2b-36a7-a321-fea9c9594cb9"
			$caption      = $data['lead']['caption'];
			$hide_caption = $data['lead']['hideCaption'];
			$credit       = $data['lead']['credit'];
			$alt          = $data['lead']['altText'];
			// Next -- find url and download image.
			$url;
			$attachment_id = $this->attachments->import_external_file( $url, $title = null, ( $hide_caption ? $caption : null ), $description = null, $alt, $post_id, $args = [] );
			set_post_thumbnail( $post_id, $attachment_id );


			// Authors.
			// Next - search these two fields. Find bios, avatars, etc by checking staff pages at https://lookout.co/santacruz/about .
			$data['authorable.authors'];
			// Can be multiple entries:
			// [
			// {
			// "_ref": "0000017e-5a2e-d675-ad7e-5e2fd5a00000",
			// "_type": "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
			// }
			// ]
			$data['authorable.oneOffAuthors'];
			// Can be multiple entries:
			// [
			// {
			// "name":"Corinne Purtill",
			// "_id":"d6ce0bcd-d952-3539-87b9-71bdb93e98c7",
			// "_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// },
			// {
			// "name":"Sumeet Kulkarni",
			// "_id":"434ebcb2-e65c-32a6-8159-fb606c93ee0b",
			// "_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// }
			// ]

			$data['authorable.primaryAuthorBioOverride'];
			// ? Next - search where not empty and see how it's used.
			$data['hasSource.source'];
			// Can be single entry:
			// "_ref": "00000175-66c8-d1f7-a775-eeedf7280000",
			// "_type": "289d6a55-9c3a-324b-9772-9c6f94cf4f88"


			// Categories.
			// Next -- is this a taxonomy?
			$data['sectionable.section'];
			// Can be single entry:
			// "_ref": "00000180-62d1-d0a2-adbe-76d9f9e7002e",
			// "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			$data['sectionable.secondarySections'];
			// Can be multiple entries:
			// [
			// {
			// "_ref": "00000175-7fd0-dffc-a7fd-7ffd9e6a0000",
			// "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			// }
			// ]


			// Tags.
			$data['taggable.tags'];
			// Next -- find tags
			// Can be multiple entries:
			// [
			// {
			// "_ref": "00000175-ecb8-dadf-adf7-fdfe01520000",
			// "_type": "90602a54-e7fb-3b69-8e25-236e50f8f7f5"
			// }
			// ]


			// Save postmeta.
			foreach ( $postmeta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}


			// Update post data.
			if ( ! empty( $post_update_data ) ) {
				$wpdb->update( $wpdb->posts, $post_update_data, [ 'ID' => $post_id ] );
			}
		}

	}

	public function convert_epoch_timestamp_to_wp_format( $timestamp ) {
		$timestamp_seconds = intval( $timestamp ) / 1000;
		$readable          = date( 'Y-m-d H:i:s', $timestamp_seconds );

		return $readable;
	}

	/**
	 * @param $table_name
	 * @param $truncate
	 *
	 * @return void
	 */
	public function create_custom_table( $table_name, $truncate = false ) {
		global $wpdb;

		$wpdb->get_results(
			"CREATE TABLE IF NOT EXISTS {$table_name} (
				`id` INT unsigned NOT NULL AUTO_INCREMENT,
				`slug` TEXT,
				`data` TEXT,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB;"
		);

		if ( true === $truncate ) {
			$wpdb->get_results( "TRUNCATE TABLE {$table_name};" );
		}
	}
}
