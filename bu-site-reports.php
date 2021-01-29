<?php

namespace {
	// Fake for unit tests.
	if ( ! class_exists( 'WP_CLI_Command' ) ) {
		class WP_CLI_Command {}
	}
}

namespace BU\Report {
	/**
	 * Plugin Name: BU SiteReport
	 * Description: Collection of WP-CLI commands to report on sites, plugins and themes in a WordPress network
	 * Author: Jonathan Williams
	 * License: GPLv2 or later
	 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
	 * Version: 0.1
	 */
	class SiteReport extends \WP_CLI_Command {

		/**
		 * Scans wp_site table and returns sites
		 *
		 * @alias list-sites
		 *
		 * @param array $args Positional args.
		 * @param array $args_assoc Assocative args.
		 */
		public function list_sites( $args, $args_assoc ) {
			$sites = self::get_sites();

			if ( ! $sites ) {
				\WP_CLI::error( 'No sites found' );
			}

			// Setup a table to return the data.
			$output = new \cli\Table();
			$output->setHeaders( array( 'id', 'domain', 'path' ) );

			foreach ( $sites as $site ) {
				$output->addRow( $site );
			}

			$output->display();
		}

		/**
		 * Scans all sites and reports on blogs
		 *
		 * @alias list-blogs
		 *
		 * @param array $args Positional args.
		 * @param array $args_assoc Assocative args.
		 */
		public function list_blogs( $args, $args_assoc ) {
			global $wpdb;

			$sites = self::get_sites();

			if ( ! $sites ) {
				\WP_CLI::error( 'No sites found' );}

			// Setup a table to return the data.
			$output = new \cli\Table();
			$output->setHeaders(
				array(
					'blog_id',
					'site_id',
					'domain',
					'path',
					'registered',
					'last_updated',
					'public',
					'archived',
					'mature',
					'spam',
					'deleted',
					'lang_id',
					'calc_post_count',
					'admin_email',
				)
			);

			foreach ( $sites as $site ) {

				$blogs = self::get_blogs( $site['id'] );

				foreach ( $blogs as $blog ) {
					// Calculate the total number of published posts and pages.
					$post_count_query  = sprintf( "SELECT COUNT(*) FROM wp_%s_posts WHERE (post_type = 'post' OR post_type = 'page') AND post_status = 'publish';", $blog['blog_id'] );
					$post_count_result = $wpdb->get_results( $post_count_query, ARRAY_A );
					$post_count_arr    = $post_count_result[0];
					$post_count        = $post_count_arr['COUNT(*)'];

					// Add the caluculated result to the reported stats.
					$blog['post_count'] = $post_count;

					// Extract the site admin email.
					$admin_query      = sprintf( "SELECT option_value FROM wp_%s_options WHERE option_name = 'admin_email';", $blog['blog_id'] );
					$admin_result     = $wpdb->get_results( $admin_query, ARRAY_A );
					$admin_result_arr = $admin_result[0];
					$admin_email      = $admin_result_arr['option_value'];

					// Add site admin email to reported stats.
					$blog['admin_email'] = $admin_email;

					$output->addRow( $blog );
				}
			}

			$output->display();
		}

		/**
		 * Scans all of the blogs in all the sites for active plugins
		 *
		 * @alias list-active-plugins
		 *
		 * @param array $args Positional args.
		 * @param array $args_assoc Assocative args.
		 */
		public function list_active_plugins( $args, $args_assoc ) {
			$sites = self::get_sites();
			if ( ! $sites ) {
				\WP_CLI::error( 'No sites found' );}

			// Setup a table to return the data.
			$output = new \cli\Table();
			$output->setHeaders(
				array(
					'site_id',
					'blog_id',
					'plugin_name',
					'url',
				)
			);

			foreach ( $sites as $site ) {

				$blogs = self::get_blogs( $site['id'] );

				foreach ( $blogs as $blog ) {
					// Get all of the active plugins for the site.
					$site_url = 'http://' . $blog['domain'] . $blog['path'];

					$plugins = self::get_active_plugins( $blog['blog_id'] );

					if ( ! $plugins ) {
						continue;
					}

					foreach ( $plugins as $plugin ) {
						// Extract just the plugin slug from the options path.
						$path = pathinfo( $plugin );
						$hook = $path['dirname'];

						$row = array(
							$blog['site_id'],
							$blog['blog_id'],
							$hook,
							$site_url,
						);

						$output->addRow( $row );
					}
				}
			}
			$output->display();
		}

		/**
		 * Scans all of the blogs in all the sites for active plugins
		 *
		 * @alias list-active-themes
		 *
		 * @param array $args Positional args.
		 * @param array $args_assoc Assocative args.
		 */
		public function list_active_themes( $args, $args_assoc ) {
			global $wpdb;
			$blogs = self::get_blogs();

			// Setup a table to return the data.
			$output = new \cli\Table();
			$output->setHeaders(
				array(
					'site_id',
					'blog_id',
					'theme_name',
					'template_name',
					'url',
				)
			);

			foreach ( $blogs as $blog ) {
				$site_url = 'http://' . $blog['domain'] . $blog['path'];

				$stylesheet_query = sprintf( "SELECT option_value FROM wp_%s_options WHERE option_name = 'stylesheet';", $blog['blog_id'] );
				$template_query   = sprintf( "SELECT option_value FROM wp_%s_options WHERE option_name = 'template';", $blog['blog_id'] );

				$stylesheet_result = $wpdb->get_results( $stylesheet_query, ARRAY_A );
				$template_result   = $wpdb->get_results( $template_query, ARRAY_A );

				$theme_name    = $stylesheet_result[0]['option_value'];
				$template_name = $template_result[0]['option_value'];

				$row = array(
					$blog['site_id'],
					$blog['blog_id'],
					$theme_name,
					$template_name,
					$site_url,
				);

				$output->addRow( $row );
			}
			$output->display();
		}



		/**
		 * Gets all the sites in the network
		 *
		 * @return array
		 */
		public function get_sites() {
			global $wpdb;
			return $wpdb->get_results( 'SELECT * FROM wp_site', ARRAY_A );
		}

		/**
		 * Gets all the blogs in the site
		 *
		 * @param int $site_id ID of the site to fetch blogs from.
		 * @return array
		 */
		public function get_blogs( $site_id = false ) {
			global $wpdb;

			if ( $site_id ) {
				$query = sprintf( "SELECT * FROM wp_blogs WHERE site_id = '%s';", $site_id );
			} else {
				$query = 'SELECT * FROM wp_blogs';
			}

			return $wpdb->get_results( $query, ARRAY_A );
		}

		/**
		 * Gets all the active plugins for blogs
		 *
		 * @param int $blog_id ID of the blog to fetch plugins from.
		 * @return array
		 */
		public function get_active_plugins( $blog_id ) {
			global $wpdb;
			$query   = sprintf( "SELECT option_value FROM wp_%s_options WHERE option_name = 'active_plugins';", $blog_id );
			$result  = $wpdb->get_results( $query, ARRAY_A );
			$plugins = unserialize( $result[0]['option_value'] );
			return $plugins;
		}
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'sitereport', __NAMESPACE__ . '\\SiteReport' );
	}
}
