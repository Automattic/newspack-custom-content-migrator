<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;

/**
 * Custom migration scripts for The City.
 */
class TheCityMigrator implements InterfaceCommand {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * CoAuthors Plus instance.
	 *
	 * @var CoAuthorPlus CoAuthors Plus instance.
	 */
	private $coauthors_plus;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthors_plus = new CoAuthorPlus();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator thecity-chorus-cms-import-authors-and-posts',
			[ $this, 'cmd_choruscms_import_authors_and_posts' ],
			[
				'shortdesc' => 'Migrates Chorus CMS authors and posts (entries) to WordPress.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-export',
						'description' => "Path to where 'author/' and 'entry/' folders with JSONs are located.",
						'optional'    => false,
						'repeating'   => false,
					],
					// [
					// 	'type'        => 'flag',
					// 	'name'        => 'default-author-user-id',
					// 	'description' => "GAs will be assigned to post, but still a u.",
					// 	'optional'    => false,
					// 	'repeating'   => false,
					// ],
					[
						'type'        => 'flag',
						'name'        => 'refresh-authors',
						'description' => "If used, will refresh all author data from JSONs, even if author exists.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-posts',
						'description' => "If used, will refresh all posts or 'entries' data from JSONs, even if post exists.",
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable to `newspack-content-migrator thecity-chorus-cms-import-authors-and-posts`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_choruscms_import_authors_and_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		// Params.
		// $default_author_user_id = $assoc_args['default-author-user-id'];
		$refresh_authors = $assoc_args['refresh-authors'] ?? null;
		$refresh_posts = $assoc_args['refresh-posts'] ?? null;
		$path = rtrim( $assoc_args['path-to-export'], '/' );
		$authors_path = $path . '/author';
		$entries_path = $path . '/entry';
		if ( ! file_exists( $authors_path ) || ! file_exists( $entries_path ) ) {
			WP_CLI::error( 'Content not found in path.' );
		}

		// Mapping from Chorus' featured image position to Newspack's.
		$newspack_featured_image_position = [
			// HEADLINE_OVERLAY => Behind Post Title
			"HEADLINE_OVERLAY" => "behind",
			// HEADLINE_BELOW => Above Title,
			"HEADLINE_BELOW" => "above",
			// SPLIT_LEFT => Beside Title
			"SPLIT_LEFT" => "beside",
			// SPLIT_RIGHT => Beside Title
			"SPLIT_RIGHT" => "beside",
			// STANDARD => Large
			"STANDARD" => "large",
			// HEADLINE_BELOW_SHORT => Above Title
			"HEADLINE_BELOW_SHORT" => "above",
		];

		// $this->import_authors( $authors_path, $refresh_authors );
		$this->import_entries( $entries_path, $refresh_posts, $newspack_featured_image_position /*, $default_author_user_id */ );
	}

	public function import_entries( $entries_path, $refresh_posts, $newspack_featured_image_position /*, $default_author_user_id */ ) {
		global $wpdb;

		$types = [];

		$entries_jsons = glob( $entries_path . '/*.json' );
		foreach ( $entries_jsons as $entry_json ) {
			$entry = json_decode( file_get_contents( $entry_json ), true );

			// Various validations and debugging checks.
			if ( 'Entry' != $entry['__typename'] ) {
				$d=1;
			}
			if ( 'PUBLISHED' != $entry['publishStatus'] ) {
				$d=1;
			}
			if ( $entry['additionalContributors'] ) {
				$d=1;
			}
			// Check $entry['type']-s => 'STORY'
			$types[ $entry['type'] ] = true;

			$post_arr = [
				'post_type'             => 'post',
				'post_status'           => 'publish',
				'post_title'            => $entry['title'],
				'post_content'          => '',

				// 'post_author'           => $user_id,
			];
			// Excerpt.
			if ( isset( $entry['dek']['html'] ) && ! empty( $entry['dek']['html'] ) ) {
				$post_arr['post_excerpt'] = $entry['dek']['html'];
			}

			// Post date.
			//   - extract '2023-06-13 21:05:36' from '2023-06-13T21:05:36.000Z'.
			$publish_date = $this->format_date( $entry['publishDate'] );
			if ( ! $publish_date ) {
				$d=1;
			}
			$post_arr['post_date'] = $publish_date;

			$post_id = wp_insert_post( $post_arr, true );
			if ( is_wp_error( $post_id ) ) {
				$err = $post_id->get_error_message();
				$d=1;
			}

			// Modify updated date.
			$updated_date = $this->format_date( $entry['updatedAt'] );
			if ( ! $updated_date ) {
				$d=1;
			}
			$wpdb->update( $wpdb->posts, [ 'post_modified' => $updated_date, 'post_modified_gmt' => $updated_date ], [ 'ID' => $post_id ] );

			// Import featured image.
			if ( isset( $entry['leadImage']['asset'] ) && ! empty( $entry['leadImage']['asset'] ) ) {
				if ( 'IMAGE' != $entry['leadImage']['asset']['type'] ) {
					$d=1;
				}
				if ( $entry['leadImage']['additionalContributors'] ) {
					$d=1;
				}
				$url = $entry['leadImage']['asset']['url'];
				$credit = $entry['leadImage']['asset']['credit']['html'];
				$title = $entry['leadImage']['asset']['title'];
				$search_text = $entry['leadImage']['asset']['searchText'];
				$caption = $entry['leadImage']['asset']['sourceCaption'];
				// 'createdAt' => '2022-12-02T19:23:52.000Z',
				$created_at = $entry['leadImage']['asset']['createdAt'];

				// Save as meta.
				$featured_image_uid = $entry['leadImage']['asset']['uid'];
			}

			// Get 'steinway-billiards-pool-hall-closes-astoria-pandemic' from $entry['url'] which is 'https://www.thecity.nyc/2023/6/12/23758757/steinway-billiards-pool-hall-closes-astoria-pandemic';
			$url_parsed = parse_url( $entry['url'] );
			$path_exploded = explode( '/', $url_parsed['path'] );
			$slug = $path_exploded[ count( $path_exploded ) - 1 ];
			if ( ! $slug ) {
				$d = 1;
			}

			// Set Newspack post meta.
			$meta = [
				'newspack_chorus_entry_uid' => $entry['uid'],
				'newspack_chorus_entry_url' => $entry['url'],
			];

			// Set Newspack featured image position.
			if ( $entry['layoutTemplate'] ) {
				if ( ! isset( $newspack_featured_image_position[ $entry['layoutTemplate'] ] ) ) {
					$d=1;
				}
				update_post_meta( $post_id, 'newspack_featured_image_position', $newspack_featured_image_position[ $entry['layoutTemplate'] ] );
			}

			// Get and assign authors.
			$ga_ids = [];
			foreach ( $entry['author'] as $author ) {
				// Get GA ID with that uid.
				$ga_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s and meta_value = %s", 'newspack_chorus_author_uid', $entry['author'][0]['uid'] ) );
				if ( ! $ga_id ) {
					$d=1;
				}
				$ga_ids[] = $ga_id;
			}
			if ( ! empty( $entry['contributors'] ) ) {
				$d=1;
			}
			// Assign authors.
			$this->coauthors_plus->assign_guest_authors_to_post( $ga_ids, $post_id );

			// Categories.
			$category_name_primary = $entry['primaryCommunityGroup']['name'];
				update_post_meta( get_the_ID(), '_yoast_wpseo_primary_category', $category_id );

			foreach ( $entry['communityGroups'] as $community_group ) {
				$category_name = $community_group['name'];
			}

			foreach ( $entry['body']['components'] as $component ) {

				// get all unique component types, their content, and example URLs.

				$component['__typename'];
			}

			array (
				'body' =>
					array (
						'components' =>
							array (
								0 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'zXfnvJ',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“We are a devastating result of the pandemic,” says the handwritten note outside of Steinway Cafe-Billiards. The iconic Astoria pool hall shuttered abruptly last Thursday, just two days after a key decision in a two-years-long lawsuit over tens of thousands of dollars in missed pandemic-era rent payments by the business.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								1 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'VSUoWC',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“I’m going through the five stages of grief,” said Athena Mennis, a general manager who started there as a waitress 22 years ago, and came in Wednesday morning to be told — along with 10 other employees — that she had lost her job. ',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								2 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => '20PTMB',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'While some patrons learned of their haunt’s closure from the note Mennis wrote and taped to the glass outside, others found out from the pool hall’s <a href="https://www.instagram.com/p/CtNUCAhO3is/?hl=en">Instagram post</a> Wednesday announcing “with great sadness and shock” that it would be the last day for the game room and bar that opened in 1990.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								3 =>
									array (
										'__typename' => 'EntryBodyRelatedList',
										'placement' =>
											array (
												'id' => '0NG4ks',
												'alignment' => NULL,
											),
										'items' =>
											array (
												0 =>
													array (
														'title' => 'One Shot From the End',
														'url' => 'https://www.thecity.nyc/queens/2022/11/8/23446172/astoria-pool-hall-steinway-cafe-billiards-innovation-queens-qns',
													),
											),
									),
								4 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'Q24QM5',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'One user shared memories of a first date there in 1993, while others shared memories of pool and chess games they’d played in the hall. ',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								5 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'h5BdDY',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“That’s really sad,” commented pool pro Fedor Gorst. “That was my first pool room that I visited in US when I was 14 or 15. I loved that place.” ',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								6 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => '0x0Yjl',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'Another commenter, Paul Taylor, lamented reading about “the closure of another billiards club,” adding that it “reminds me again of how I felt when my local club closed for the last time and is now home to a gym.”',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								7 =>
									array (
										'__typename' => 'EntryBodyImage',
										'placement' =>
											array (
												'id' => 'Eg8agL',
												'alignment' => NULL,
											),
										'contentWarning' => '',
										'image' =>
											array (
												'asset' =>
													array (
														'title' => 'A sign outside Steinway Billiards in Astoria announced its closing.',
													),
												'caption' =>
													array (
														'html' => 'A broken heart',
														'plaintext' => 'A broken heart',
													),
												'hideCredit' => false,
												'height' => 3000,
												'width' => 2000,
												'url' => 'https://cdn.vox-cdn.com/thumbor/MVJPYB1Ojxsi8aFGAMSpZz10h90=/0x0:2000x3000/2000x3000/filters:focal(1000x1500:1001x1501)/cdn.vox-cdn.com/uploads/chorus_asset/file/24722393/steinway_billiards_close.jpg',
											),
									),
								8 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'qsc3i8',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'In its 33 years, the billiards cafe has been the home of Earl Strickland, who served as the in-house pro between 2011 to 2018 and was considered one of the best nine-ball players of all time. Other notable players including Efren Reyes, Shane Van Boening and Ronnie O’Sullivan have also graced the tournament tables at the family business that operated out of a low-key block on Astoria’s bustling Steinway Street.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								9 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'Ypc59h',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'The neighborhood mainstay has also been a favorite gathering place for Greeks in Astoria and beyond. When <a href="https://www.thecity.nyc/queens/2022/11/8/23446172/astoria-pool-hall-steinway-cafe-billiards-innovation-queens-qns">THE CITY visited last year,</a> longtime patrons <a href="https://www.thecity.nyc/2022/12/4/23492338/steinway-cafe-billiards-julie-won-innovation-qns-second-shot">entertained each other</a> over games of pool or Greek backgammon, bantering with workers over $3 coffees between rounds in the manner of old friends.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								10 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'WhbBfz',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'A Queens judge first ruled in December of last year that a warrant would be issued for the pool hall’s eviction as a result of about $440,000 owed by the business in back rent since March 2020, <a href="https://iapps.courts.state.ny.us/nyscef/DocumentList?docketId=Nfh7k728Bm1xpg3Iuurvsg==&display=all&courtType=Queens%20County%20Civil%20Court%20-%20Landlord%20and%20Tenant%20Division&resultsPageNum=1">according to court documents</a>. But an appeal process stalled the eviction for about six months following the court decision, until a judge last Tuesday declined a motion from the business asking the landlords to show cause.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								11 =>
									array (
										'__typename' => 'EntryBodyHeading',
										'placement' =>
											array (
												'id' => 'NtBaUk',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '‘We All Grew Up Together’',
											),
										'level' => 2,
									),
								12 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'ofPPvh',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'Speaking to THE CITY on Monday, several longtime employees shared feelings of loss and confusion over the pool hall’s closure, as well as worries over their future. All said they had expected that any closure would be postponed for at least a few more months as the court case dragged on, and <a href="https://www.thecity.nyc/2022/12/4/23492338/steinway-cafe-billiards-julie-won-innovation-qns-second-shot">as they had hoped for a lucky break</a>.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								13 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'P2k6j4',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'Luisa Patino, 37, had been back on the job for only three days after a three-month sick leave for breast cancer when she found out that the business was closing.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								14 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => '5VIFTm',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“I feel a lot of depression. It’s pretty sad,” said Patino, who has worked at the pool hall for 13 years. “Because I know the people [a] long time, and because he closed and he told me the same day.”',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								15 =>
									array (
										'__typename' => 'EntryBodyImage',
										'placement' =>
											array (
												'id' => 'XmNGB2',
												'alignment' => NULL,
											),
										'contentWarning' => '',
										'image' =>
											array (
												'asset' =>
													array (
														'title' => 'The staff at Steinway Billiards celebrate a birthday.',
													),
												'caption' =>
													array (
														'html' => 'Staffers at Steinway Billiards, including Athena Mennis at the far left and Jana Tellez at the far right, celebrate Luisa Patino’s birthday (middle) in October of 2022.   ',
														'plaintext' => 'Staffers at Steinway Billiards, including Athena Mennis at the far left and Jana Tellez at the far right, celebrate Luisa Patino’s birthday (middle) in October of 2022.   ',
													),
												'hideCredit' => false,
												'height' => 533,
												'width' => 800,
												'url' => 'https://cdn.vox-cdn.com/thumbor/KKNaFbPkWeTCYrw8QnkKTO03kbU=/0x0:800x533/800x533/filters:focal(400x267:401x268)/cdn.vox-cdn.com/uploads/chorus_asset/file/24722614/steinway_billiards_staff.jpg',
											),
									),
								16 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'd0kTaU',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'Other members of the staff echoed Patino’s sentiment, saying they wished owner Georgiois “George” Nikolakakos would have given advance notice so they could have started looking for new jobs. ',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								17 =>
									array (
										'__typename' => 'EntryBodyImage',
										'placement' =>
											array (
												'id' => 'ChTPiW',
												'alignment' => NULL,
											),
										'contentWarning' => '',
										'image' =>
											array (
												'asset' =>
													array (
														'title' => 'Steinway Billiards owner Georgios Nikolakakos hung out behind the bar on a busy Wednesday night.',
													),
												'caption' =>
													array (
														'html' => 'George',
														'plaintext' => 'George',
													),
												'hideCredit' => false,
												'height' => 2000,
												'width' => 3000,
												'url' => 'https://cdn.vox-cdn.com/thumbor/w-4IWSiXQFrri7BOVrcm64EYbTc=/0x0:3000x2000/3000x2000/filters:focal(1500x1000:1501x1001)/cdn.vox-cdn.com/uploads/chorus_asset/file/24165138/110222_steinway_billiards_4.jpg',
											),
									),
								18 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'PQwMUZ',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'That includes 32-year-old Jana Tellez, a single mother who has worked at the billiards cafe since she was 15. Though, like many of the workers, she also emphasized her closeness with other staffers as well as with Nikolakakos, adding that she wishes him no ill will despite the sudden closure that has left her and others in limbo.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								19 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'UrBXVe',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“George’s like another dad to me, as weird as it sounds … I don’t even think he understood half the things that were going on, you know, it wasn’t even him dealing with the courts,” Tellez said. “I don’t blame him. I’m not gonna say who I blame but I just don’t blame him.”',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								20 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => '1v8ucd',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'Andres, a 34-year-old cook from Ecuador, also said he was worried about how he was going to find a job with the closure of the place due to his immigration status. He began working at the pool hall as a busboy when he was 17. ',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								21 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'kYuHVI',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“We all grew up together over there,” said Andres, who learned to play Greek backgammon at the pool hall when he was 22 years old.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								22 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => '4RzKSF',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“He played backgammon with all the old men and beat them out all the time,” Mennis, the manager, chimed in. She chuckled: “They’d all get mad at him.”',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								23 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'fvghrp',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'Nikolakakos and his daughter, Anna, who helps run the business, could not immediately be reached for comment.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								24 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'XE7EyM',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'In the meantime, Mennis has set up a <a href="https://gofund.me/e78cd004">GoFundMe</a> hoping to scrape together a sum to help support the staff as they look for their next gigs.',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								25 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'cF78ho',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'The goal, she said, is to raise about $2,000 for each former employee to help them cover upcoming bills and get back on their feet during what she calls “the slowest season for hospitality staff.”',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								26 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => '1JZKmw',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => 'Recalling the staff and customers’ last night at the pool hall on Wednesday, Tellez noted how “everybody sat there and cried — and drank.”',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								27 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'pVrFyd',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“And I worked my ass off all night,” said Mennis. ',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								28 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => 'atRNLM',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '“I had a good time saying goodbye — not a good time, but a bittersweet time,” she continued. “The people that I’ve met there I’ve known my whole life…  I just know I’m gonna lose touch with the majority of the people that I’ve spent my teenage years and adult years knowing.”',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
								29 =>
									array (
										'__typename' => 'EntryBodyNewsletter',
										'placement' =>
											array (
												'id' => 'oewNPh',
												'alignment' => NULL,
											),
										'newsletter' =>
											array (
												'name' => 'Get THE CITY Scoop',
												'slug' => 'the_city',
											),
									),
								30 =>
									array (
										'__typename' => 'EntryBodyParagraph',
										'placement' =>
											array (
												'id' => '3JkEsx',
												'alignment' => NULL,
											),
										'contents' =>
											array (
												'html' => '',
											),
										'dropcap' => false,
										'endmark' => false,
										'lead' => false,
									),
							),
					),
			);

			$types;
			$d=1;
		}
	}

	/**
	 * @param $chorus_date
	 *
	 * @return array|string|string[]|null
	 */
	private function format_date( $chorus_date ) {

		// Extract e.g. '2023-06-13 21:05:36' from '2023-06-13T21:05:36.000Z'.
		$wp_date = preg_replace( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2}).*/', '$1 $2', $chorus_date );

		return $wp_date;
	}

	public function import_authors( $authors_path, $refresh_authors ) {
		global $wpdb;

		$authors_jsons = glob( $authors_path . '/*.json' );
		foreach ( $authors_jsons as $author_json ) {
			$author = json_decode( file_get_contents( $author_json ), true );

			// Get GA creation/update params.
			$ga_args = [
				'display_name' => $author['name'],
				'user_login' => $author['user']['username'],
				'first_name' => $author['user']['firstName'],
				'last_name' => $author['user']['lastName'],
			];

			// Apparently shortBio is always empty :(.
			if ( $author['shortBio'] ) {
				$ga_args['description'] = $author['shortBio'];
			}

			if ( isset( $author['socialLinks'] ) && ! empty( $author['socialLinks'] ) ) {

				// Extract links HTML for bio from socialLinks.
				$links_bio = '';
				foreach ( $author['socialLinks'] as $social_link ) {
					/**
					 * Available types: PROFILE, TWITTER, RSS, EMAIL, INSTAGRAM.
					 */
					if ( $social_link['type'] ) {
						if ( 'PROFILE' === $social_link['type'] ) {
							// Local site author page URL.
						} elseif ( 'TWITTER' === $social_link['type'] ) {
							// If doesn't end with dot, add dot.
							$links_bio .= ( ! empty( $links_bio ) && '.' != substr( $links_bio, -1 ) ) ? '.' : '';
							// If doesn't end with space, add space.
							$links_bio .= ( ! empty( $links_bio ) && ' ' != substr( $links_bio, -1 ) ) ? ' ' : '';
							// Get handle from URL.
							$handle = rtrim( $social_link['url'], '/' );
							$handle = substr( $handle, strrpos( $handle, '/' ) + 1 );
							// Add Twitter link.
							$links_bio .= sprintf( '<a href="%s" target="_blank">Follow @%s on Twitter</a>.', $social_link['url'], $handle );
						} elseif ( 'RSS' === $social_link['type'] ) {
							// RSS feed URL.
						} elseif ( 'EMAIL' === $social_link['type'] ) {
							$ga_args['user_email'] = $social_link['url'];
						} elseif ( 'INSTAGRAM' === $social_link['type'] ) {
							// If doesn't end with dot, add dot.
							$links_bio .= ( ! empty( $links_bio ) && '.' != substr( $links_bio, -1 ) ) ? '.' : '';
							// If doesn't end with space, add space.
							$links_bio .= ( ! empty( $links_bio ) && ' ' != substr( $links_bio, -1 ) ) ? ' ' : '';
							// Get handle from URL.
							$handle = rtrim( $social_link['url'], '/' );
							$handle = substr( $handle, strrpos( $handle, '/' ) + 1 );
							// Add Twitter link.
							$links_bio .= sprintf( '<a href="%s" target="_blank">Follow @%s on Instagram</a>.', $social_link['url'], $handle );
						}
					}

					// Not used key in JSONs: $social_link['label']
				}

				// Append social links to GA bio.
				if ( ! empty( $links_bio ) ) {
					// Start with bio.
					$bio_updated = isset( $ga_args['description'] ) && ! empty( $ga_args['description'] ) ? $ga_args['description'] : '';
					// If doesn't end with dot, add dot.
					$bio_updated .= ( ! empty( $bio_updated ) && '.' != substr( $bio_updated, -1 ) ) ? '.' : '';
					// If doesn't end with space, add space.
					$bio_updated .= ( ! empty( $bio_updated ) && ' ' != substr( $bio_updated, -1 ) ) ? ' ' : '';
					// Add links bio.
					$bio_updated .= $links_bio;

					// Update bio.
					$ga_args['description'] = $bio_updated;
				}
			}

			// Get existing GA.
			$ga_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_chorus_author_uid' and meta_value = %s", $author['uid'] ) );

			// If GA exists...
			if ( $ga_id ) {

				// ... and not refreshing, skip.
				if ( ! $refresh_authors ) {
					WP_CLI::log( sprintf( "Author '%s' already exists. Skipping.", $author['name'] ) );
					continue;
				}

				// ... and refreshing, update the GA.
				// Don't attempt to update user_login -- presently not supported.
				unset( $ga_args['user_login'] );
				$this->coauthors_plus->update_guest_author( $ga_id, $ga_args );
				WP_CLI::success( sprintf( 'Updated existing user data GA %d for author %s.', $ga_id, $author['name'] ) );
				continue;
			}

			// Create GA.
			$ga_id = $this->coauthors_plus->create_guest_author( $ga_args );
			WP_CLI::success( sprintf( "Created GA %d for author '%s'.", $ga_id, $author['name'] ) );
			// Save $author['uid'] as postmeta.
			if ( $author['uid'] ) {
				update_post_meta( $ga_id, 'newspack_chorus_author_uid', $author['uid'] );
			}

			/**
			 * These $authors keys also exist in author JSONs:
			 *  $author['url'] -- local site author page URL
			 *  $author['title'] -- not used, always empty
			 */
		}
	}
}
